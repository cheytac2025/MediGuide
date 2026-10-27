<?php

namespace App\Support\Audit;

use App\Enums\AuditAction;
use App\Enums\AuditOutcome;
use App\Exceptions\AuditTrailException;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Appends HMAC-linked audit events. Successful business changes call record()
 * inside their own transaction so a failed append rolls the change back.
 * Denials call recordSafely() so an audit failure does not replace a 403.
 *
 * The chain lock is taken after the caller's row locks. Callers must not
 * lock audit_chain_state and then lock appointment or patient rows.
 */
class AuditTrailService
{
    public const STATE = 'audit_chain_state';

    public const EVENTS = 'audit_events';

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        AuditAction $action,
        AuditOutcome $outcome,
        ?string $targetType = null,
        ?int $targetId = null,
        array $context = [],
        ?User $actor = null,
    ): object {
        try {
            return $this->append($action, $outcome, $targetType, $targetId, $context, $actor);
        } catch (QueryException $exception) {
            $code = (int) ($exception->errorInfo[1] ?? 0);

            if (in_array($code, [1205, 1213], true)) {
                throw new AuditTrailException;
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function recordSafely(
        AuditAction $action,
        AuditOutcome $outcome,
        ?string $targetType = null,
        ?int $targetId = null,
        array $context = [],
        ?User $actor = null,
    ): void {
        try {
            $this->record($action, $outcome, $targetType, $targetId, $context, $actor);
        } catch (Throwable) {
            Log::warning('Audit event was not recorded.', [
                'action' => $action->value,
                'outcome' => $outcome->value,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function recordFor(
        AuditAction $action,
        AuditOutcome $outcome,
        Model $target,
        array $context = [],
        ?User $actor = null,
    ): object {
        return $this->record(
            $action,
            $outcome,
            $this->targetType($target),
            (int) $target->getKey(),
            $context,
            $actor,
        );
    }

    /**
     * @return array{sequence: int, tag: string, key_version: int, occurred_at: string, checkpoint_tag: string}
     */
    public function checkpoint(): array
    {
        $head = DB::table(self::STATE)->where('id', 1)->first();
        $sequence = (int) ($head->last_sequence ?? 0);
        $tag = $sequence === 0 ? $this->genesisTag((int) config('audit.key_version')) : (string) $head->last_tag;
        $occurred = now()->utc()->format('Y-m-d\TH:i:s.u\Z');
        $keyVersion = (int) config('audit.key_version');
        $body = [
            'sequence' => $sequence,
            'tag' => $tag,
            'key_version' => $keyVersion,
            'occurred_at' => $occurred,
        ];

        return [
            ...$body,
            'checkpoint_tag' => hash_hmac('sha256', CanonicalAuditEvent::checkpoint($body), $this->keyForVersion($keyVersion)),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function append(
        AuditAction $action,
        AuditOutcome $outcome,
        ?string $targetType,
        ?int $targetId,
        array $context,
        ?User $actor,
    ): object {
        $actor ??= Auth::user() instanceof User ? Auth::user() : null;

        if ($actor instanceof User) {
            $actor->loadMissing('role');
        }

        $clean = CanonicalAuditEvent::context($context);
        $occurredAt = now()->utc()->format('Y-m-d\TH:i:s.u\Z');
        $keyVersion = (int) config('audit.key_version');
        $chainVersion = (int) config('audit.version');
        $correlation = app()->bound('audit.correlation_id') ? app('audit.correlation_id') : null;
        $correlation = is_string($correlation) && preg_match('/^[A-Za-z0-9-]{36}$/', $correlation) === 1 ? $correlation : null;

        return DB::transaction(function () use ($action, $outcome, $targetType, $targetId, $clean, $occurredAt, $keyVersion, $chainVersion, $correlation, $actor): object {
            $state = DB::table(self::STATE)->where('id', 1)->lockForUpdate()->first();

            if ($state === null) {
                throw new AuditTrailException;
            }

            $sequence = (int) $state->last_sequence + 1;
            $previous = $sequence === 1
                ? $this->genesisTag($keyVersion)
                : (string) $state->last_tag;
            $fields = [
                'chain_version' => $chainVersion,
                'key_version' => $keyVersion,
                'sequence' => $sequence,
                'previous_tag' => $previous,
                'actor_user_id' => $actor?->id,
                'actor_role' => $actor?->role?->slug,
                'action' => $action->value,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'occurred_at' => $occurredAt,
                'outcome' => $outcome->value,
                'correlation_id' => $correlation,
            ];
            $tag = hash_hmac('sha256', CanonicalAuditEvent::event($fields, $clean), $this->keyForVersion($keyVersion));

            DB::table(self::EVENTS)->insert([
                ...$fields,
                'context' => $clean === [] ? '{}' : CanonicalAuditEvent::encode($clean),
                'tag' => $tag,
            ]);
            DB::table(self::STATE)->where('id', 1)->update([
                'last_sequence' => $sequence,
                'last_tag' => $tag,
                'key_version' => $keyVersion,
                'updated_at' => now(),
            ]);

            $row = DB::table(self::EVENTS)->where('sequence', $sequence)->first();

            if ($row === null) {
                throw new AuditTrailException;
            }

            return $row;
        });
    }

    public function genesisTag(int $keyVersion): string
    {
        return hash_hmac('sha256', CanonicalAuditEvent::genesis($keyVersion), $this->keyForVersion($keyVersion));
    }

    public function keyForVersion(int $version): string
    {
        $configured = $version === (int) config('audit.key_version')
            ? config('audit.key')
            : ($this->previousKeys()[$version] ?? null);

        if (! is_string($configured) || $configured === '') {
            throw new AuditTrailException;
        }

        $encoded = str_starts_with($configured, 'base64:') ? substr($configured, 7) : $configured;
        $raw = base64_decode($encoded, true);

        if ($raw === false || strlen($raw) !== 32) {
            throw new AuditTrailException;
        }

        return $raw;
    }

    /**
     * @return array<int, string>
     */
    private function previousKeys(): array
    {
        $keys = [];
        $configured = config('audit.previous_keys');

        if (! is_string($configured) || trim($configured) === '') {
            return [];
        }

        foreach (explode(',', $configured) as $entry) {
            $parts = explode(':', trim($entry), 2);

            if (count($parts) === 2 && ctype_digit($parts[0]) && $parts[1] !== '') {
                $keys[(int) $parts[0]] = $parts[1];
            }
        }

        return $keys;
    }

    private function targetType(Model $target): string
    {
        return match ($target::class) {
            Appointment::class => 'appointment',
            Patient::class => 'patient',
            User::class => 'user',
            Clinic::class => 'clinic',
            Doctor::class => 'doctor',
            DoctorSchedule::class => 'schedule',
            Department::class => 'department',
            default => 'record',
        };
    }
}
