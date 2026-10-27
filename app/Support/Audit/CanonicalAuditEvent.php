<?php

namespace App\Support\Audit;

/**
 * Canonical JSON for audit HMACs. Key order is fixed and context is minimal.
 */
final class CanonicalAuditEvent
{
    public const EVENT_DOMAIN = 'mediguide.audit.event.v1';

    public const GENESIS_DOMAIN = 'mediguide.audit.genesis.v1';

    public const CHECKPOINT_DOMAIN = 'mediguide.audit.checkpoint.v1';

    /**
     * @param  array<string, mixed>  $context
     */
    public static function event(array $fields, array $context): string
    {
        $payload = [
            'chain_version' => (int) $fields['chain_version'],
            'key_version' => (int) $fields['key_version'],
            'sequence' => (int) $fields['sequence'],
            'previous_tag' => (string) $fields['previous_tag'],
            'actor_user_id' => $fields['actor_user_id'] === null ? null : (int) $fields['actor_user_id'],
            'actor_role' => $fields['actor_role'] === null ? null : (string) $fields['actor_role'],
            'action' => (string) $fields['action'],
            'target_type' => $fields['target_type'] === null ? null : (string) $fields['target_type'],
            'target_id' => $fields['target_id'] === null ? null : (int) $fields['target_id'],
            'occurred_at' => (string) $fields['occurred_at'],
            'outcome' => (string) $fields['outcome'],
            'correlation_id' => $fields['correlation_id'] === null ? null : (string) $fields['correlation_id'],
            'context' => ($clean = self::context($context)) === [] ? new \stdClass : $clean,
        ];

        return self::EVENT_DOMAIN."\n".self::encode($payload);
    }

    public static function genesis(int $keyVersion): string
    {
        return self::GENESIS_DOMAIN.'|'.$keyVersion;
    }

    /**
     * @param  array{sequence: int, tag: string, key_version: int, occurred_at: string}  $head
     */
    public static function checkpoint(array $head): string
    {
        return self::CHECKPOINT_DOMAIN."\n".self::encode([
            'key_version' => (int) $head['key_version'],
            'occurred_at' => (string) $head['occurred_at'],
            'sequence' => (int) $head['sequence'],
            'tag' => (string) $head['tag'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function context(array $context): array
    {
        $allowed = [
            'account_user_id',
            'category',
            'clinic_id',
            'day_of_week',
            'department_id',
            'department_ids',
            'doctor_id',
            'end_time',
            'fields',
            'patient_id',
            'previous_status',
            'reason',
            'role',
            'schedule_id',
            'slot_duration',
            'start_time',
            'status',
        ];
        $clean = [];

        foreach ($allowed as $key) {
            if (! array_key_exists($key, $context)) {
                continue;
            }

            $value = self::scalar($key, $context[$key]);

            if ($value !== null) {
                $clean[$key] = $value;
            }
        }

        ksort($clean);

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function encode(array $payload): string
    {
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        if ($payload === []) {
            return '{}';
        }

        return json_encode($payload, $flags);
    }

    private static function scalar(string $key, mixed $value): mixed
    {
        if (in_array($key, ['department_ids', 'fields'], true)) {
            if (! is_array($value)) {
                return null;
            }

            $items = [];

            foreach ($value as $item) {
                if ($key === 'department_ids' && is_numeric($item)) {
                    $items[] = (int) $item;
                }

                if ($key === 'fields' && is_string($item) && preg_match('/^[a-z_]{1,32}$/', $item) === 1) {
                    $items[] = $item;
                }
            }

            return array_values(array_unique($items));
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $value) === 1) {
            return $value;
        }

        return null;
    }
}
