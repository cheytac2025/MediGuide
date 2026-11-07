<?php

namespace App\Support\Audit;

use App\Exceptions\AuditTrailException;
use Illuminate\Support\Facades\DB;

/**
 * Read-only HMAC verification of the audit chain and, when configured, a checkpoint.
 *
 * On MySQL and MariaDB, verification opens its own transaction. An existing
 * transaction is rejected before the checkpoint is read and before isolation
 * is changed. SQLite may run inside the caller's transaction; that does not
 * cover MySQL or MariaDB concurrency.
 *
 * The checkpoint file is read before the database transaction. The ordered
 * event rows and the one chain-state row are then read on that same
 * connection, with no chunking or row locks. A checkpoint appended after the
 * file read is not compared with the earlier snapshot. Nothing is written,
 * created, or re-signed.
 */
class AuditVerificationService
{
    public const OPEN_TRANSACTION_MESSAGE = 'Audit verification must run outside an existing database transaction.';

    /**
     * @return array{
     *     total: int,
     *     valid: int,
     *     invalid: int,
     *     first_sequence: ?int,
     *     category: ?string,
     *     passed: bool,
     *     checkpoint_protection: string,
     *     checkpoint_sequence: ?int,
     *     head_sequence: int,
     *     uncovered_events: ?int,
     *     message: ?string
     * }
     */
    public function verify(?AuditCheckpointStore $store = null): array
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true) && $connection->transactionLevel() > 0) {
            return $this->openTransactionResult();
        }

        $store ??= app(AuditCheckpointStore::class);
        $status = $store->status();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return $connection->transaction(function () use ($status): array {
            return $this->verifySnapshot($status);
        });
    }

    /**
     * @return array{
     *     total: int,
     *     valid: int,
     *     invalid: int,
     *     first_sequence: null,
     *     category: string,
     *     passed: false,
     *     checkpoint_protection: string,
     *     checkpoint_sequence: null,
     *     head_sequence: int,
     *     uncovered_events: null,
     *     message: string
     * }
     */
    private function openTransactionResult(): array
    {
        return [
            'total' => 0,
            'valid' => 0,
            'invalid' => 0,
            'first_sequence' => null,
            'category' => 'transaction',
            'passed' => false,
            'checkpoint_protection' => 'not_checked',
            'checkpoint_sequence' => null,
            'head_sequence' => 0,
            'uncovered_events' => null,
            'message' => self::OPEN_TRANSACTION_MESSAGE,
        ];
    }

    /**
     * @return array{
     *     total: int,
     *     valid: int,
     *     invalid: int,
     *     first_sequence: ?int,
     *     category: ?string,
     *     passed: bool,
     *     checkpoint_protection: string,
     *     checkpoint_sequence: ?int,
     *     head_sequence: int,
     *     uncovered_events: ?int,
     *     message: ?string
     * }
     */
    private function verifySnapshot(AuditCheckpointStatus $status): array
    {
        $rows = DB::table(AuditTrailService::EVENTS)->orderBy('sequence')->get();
        $state = DB::table(AuditTrailService::STATE)->where('id', 1)->first();
        $trail = app(AuditTrailService::class);
        $previousTag = null;
        $expectedSequence = 1;
        $valid = 0;
        $failure = null;
        $verifiedTags = [];
        $sequences = $rows->map(fn (object $row): int => (int) $row->sequence)->all();

        if (count($sequences) !== count(array_unique($sequences))) {
            $failure = ['sequence' => (int) ($sequences[0] ?? 1), 'category' => 'duplicate'];
        }

        if ($failure === null) {
            foreach ($rows as $row) {
                $sequence = (int) $row->sequence;

                if ($sequence !== $expectedSequence) {
                    $failure = ['sequence' => $expectedSequence, 'category' => 'gap'];
                    break;
                }

                $context = json_decode((string) $row->context, true);
                $fields = [
                    'chain_version' => $row->chain_version,
                    'key_version' => $row->key_version,
                    'sequence' => $sequence,
                    'previous_tag' => $row->previous_tag,
                    'actor_user_id' => $row->actor_user_id,
                    'actor_role' => $row->actor_role,
                    'action' => $row->action,
                    'target_type' => $row->target_type,
                    'target_id' => $row->target_id,
                    'occurred_at' => $row->occurred_at,
                    'outcome' => $row->outcome,
                    'correlation_id' => $row->correlation_id,
                ];

                try {
                    $key = $trail->keyForVersion((int) $row->key_version);
                    $expectedPrevious = $sequence === 1
                        ? $trail->genesisTag((int) $row->key_version)
                        : (string) $previousTag;
                    $expectedTag = hash_hmac('sha256', CanonicalAuditEvent::event($fields, is_array($context) ? $context : []), $key);
                } catch (AuditTrailException) {
                    $failure = ['sequence' => $sequence, 'category' => 'unknown_key'];
                    break;
                }

                if (! $this->matches($expectedPrevious, (string) $row->previous_tag)) {
                    $failure = ['sequence' => $sequence, 'category' => 'broken_link'];
                    break;
                }

                if (! $this->matches($expectedTag, (string) $row->tag)) {
                    $failure = ['sequence' => $sequence, 'category' => 'mismatch'];
                    break;
                }

                $verifiedTags[$sequence] = (string) $row->tag;
                $previousTag = (string) $row->tag;
                $expectedSequence++;
                $valid++;
            }
        }

        $headSequence = $state !== null ? (int) $state->last_sequence : 0;

        if ($failure === null && $state !== null) {
            $headTag = $headSequence === 0 ? null : (string) $state->last_tag;

            if ($headSequence !== $rows->count() || ($headSequence > 0 && $headTag !== $previousTag)) {
                $failure = ['sequence' => max(1, $headSequence), 'category' => 'state'];
            }
        }

        $checkpointSequence = null;
        $uncovered = null;
        $protection = $status->state;

        if ($status->state === 'inactive') {
            $protection = 'inactive';
        } elseif ($status->state !== 'ready' || $status->checkpoint === null) {
            $protection = $status->state;

            if ($failure === null) {
                $failure = [
                    'sequence' => null,
                    'category' => 'checkpoint',
                    'message' => $status->message,
                ];
            }
        } else {
            $checkpoint = $status->checkpoint;
            $checkpointSequence = $checkpoint['sequence'];
            $protection = 'unchecked';

            if ($failure === null) {
                $checkpointFailure = $this->checkpointFailure($checkpoint, $headSequence, $verifiedTags, $trail);

                if ($checkpointFailure === null) {
                    $protection = 'verified';
                    $uncovered = $headSequence - $checkpoint['sequence'];
                } else {
                    $failure = $checkpointFailure;
                    $protection = 'failed';
                }
            }
        }

        $total = $rows->count();

        return [
            'total' => $total,
            'valid' => $valid,
            'invalid' => $failure === null ? 0 : max(1, $total - $valid),
            'first_sequence' => $failure['sequence'] ?? null,
            'category' => $failure['category'] ?? null,
            'passed' => $failure === null,
            'checkpoint_protection' => $protection,
            'checkpoint_sequence' => $checkpointSequence,
            'head_sequence' => $headSequence,
            'uncovered_events' => $uncovered,
            'message' => $failure['message'] ?? null,
        ];
    }

    /**
     * @param  array{sequence: int, tag: string, key_version: int, occurred_at: string, checkpoint_tag: string}  $checkpoint
     * @param  array<int, string>  $verifiedTags
     * @return array{sequence: ?int, category: string, message: string}|null
     */
    private function checkpointFailure(array $checkpoint, int $headSequence, array $verifiedTags, AuditTrailService $trail): ?array
    {
        $inspected = AuditCheckpointRecord::inspect($checkpoint);

        if ($inspected['checkpoint'] === null) {
            return [
                'sequence' => null,
                'category' => 'checkpoint',
                'message' => $inspected['message'],
            ];
        }

        $sequence = $inspected['checkpoint']['sequence'];
        $tag = $inspected['checkpoint']['tag'];
        $keyVersion = $inspected['checkpoint']['key_version'];

        try {
            $key = $trail->keyForVersion($keyVersion);
        } catch (AuditTrailException) {
            return [
                'sequence' => $sequence,
                'category' => 'unknown_key',
                'message' => 'The checkpoint uses an unknown key version.',
            ];
        }

        $expected = hash_hmac('sha256', CanonicalAuditEvent::checkpoint([
            'sequence' => $sequence,
            'tag' => $tag,
            'key_version' => $keyVersion,
            'occurred_at' => $inspected['checkpoint']['occurred_at'],
        ]), $key);

        if (! $this->matches($expected, $inspected['checkpoint']['checkpoint_tag'])) {
            return [
                'sequence' => $sequence,
                'category' => 'checkpoint',
                'message' => 'The checkpoint signature is invalid.',
            ];
        }

        if ($headSequence < $sequence) {
            return [
                'sequence' => $sequence,
                'category' => 'checkpoint',
                'message' => 'The audit chain ends before the checkpoint sequence.',
            ];
        }

        if ($sequence === 0) {
            if (! $this->matches($trail->genesisTag($keyVersion), $tag)) {
                return [
                    'sequence' => 0,
                    'category' => 'checkpoint',
                    'message' => 'The checkpoint genesis tag is invalid.',
                ];
            }

            return null;
        }

        $eventTag = $verifiedTags[$sequence] ?? null;

        if (! is_string($eventTag) || ! $this->matches($eventTag, $tag)) {
            return [
                'sequence' => $sequence,
                'category' => 'checkpoint',
                'message' => 'The checkpoint does not match the audit event at its recorded sequence.',
            ];
        }

        return null;
    }

    private function matches(string $expected, string $stored): bool
    {
        return strlen($expected) === 64 && strlen($stored) === 64 && hash_equals($expected, $stored);
    }
}
