<?php

namespace App\Support\Audit;

use App\Exceptions\AuditTrailException;
use Illuminate\Support\Facades\DB;

/**
 * Read-only HMAC verification of the audit chain and an optional checkpoint.
 */
class AuditVerificationService
{
    /**
     * @return array{total: int, valid: int, invalid: int, first_sequence: ?int, category: ?string, passed: bool}
     */
    public function verify(?AuditCheckpointStore $store = null): array
    {
        $store ??= app(AuditCheckpointStore::class);
        $rows = DB::table(AuditTrailService::EVENTS)->orderBy('sequence')->get();
        $state = DB::table(AuditTrailService::STATE)->where('id', 1)->first();
        $trail = app(AuditTrailService::class);
        $previousTag = null;
        $expectedSequence = 1;
        $valid = 0;
        $failure = null;

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

                $previousTag = (string) $row->tag;
                $expectedSequence++;
                $valid++;
            }
        }

        if ($failure === null && $state !== null) {
            $headSequence = (int) $state->last_sequence;
            $headTag = $headSequence === 0 ? null : (string) $state->last_tag;

            if ($headSequence !== $rows->count() || ($headSequence > 0 && $headTag !== $previousTag)) {
                $failure = ['sequence' => max(1, $headSequence), 'category' => 'state'];
            }
        }

        $checkpoint = $store->latest();

        if ($failure === null && $checkpoint !== null) {
            $failure = $this->checkpointFailure($checkpoint, (int) ($state->last_sequence ?? 0), $previousTag, $trail);
        }

        $total = $rows->count();

        return [
            'total' => $total,
            'valid' => $failure === null ? $valid : $valid,
            'invalid' => $failure === null ? 0 : max(1, $total - $valid),
            'first_sequence' => $failure['sequence'] ?? null,
            'category' => $failure['category'] ?? null,
            'passed' => $failure === null,
        ];
    }

    /**
     * @param  array{sequence: int, tag: string, key_version: int, occurred_at: string, checkpoint_tag: string}  $checkpoint
     * @return array{sequence: int, category: string}|null
     */
    private function checkpointFailure(array $checkpoint, int $headSequence, ?string $headTag, AuditTrailService $trail): ?array
    {
        try {
            $key = $trail->keyForVersion((int) $checkpoint['key_version']);
        } catch (AuditTrailException) {
            return ['sequence' => (int) $checkpoint['sequence'], 'category' => 'unknown_key'];
        }

        $expected = hash_hmac('sha256', CanonicalAuditEvent::checkpoint([
            'sequence' => (int) $checkpoint['sequence'],
            'tag' => (string) $checkpoint['tag'],
            'key_version' => (int) $checkpoint['key_version'],
            'occurred_at' => (string) $checkpoint['occurred_at'],
        ]), $key);

        if (! $this->matches($expected, (string) $checkpoint['checkpoint_tag'])) {
            return ['sequence' => (int) $checkpoint['sequence'], 'category' => 'checkpoint'];
        }

        if ($headSequence !== (int) $checkpoint['sequence'] || ($headSequence > 0 && $headTag !== (string) $checkpoint['tag'])) {
            return ['sequence' => (int) $checkpoint['sequence'], 'category' => 'checkpoint'];
        }

        if ($headSequence === 0 && (int) $checkpoint['sequence'] === 0) {
            $genesis = $trail->genesisTag((int) $checkpoint['key_version']);

            if (! $this->matches($genesis, (string) $checkpoint['tag'])) {
                return ['sequence' => 0, 'category' => 'checkpoint'];
            }
        }

        return null;
    }

    private function matches(string $expected, string $stored): bool
    {
        return strlen($expected) === 64 && strlen($stored) === 64 && hash_equals($expected, $stored);
    }
}
