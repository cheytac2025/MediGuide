<?php

namespace App\Support\Audit;

/**
 * Accepts one checkpoint record without coercing its fields.
 */
final class AuditCheckpointRecord
{
    public const MESSAGES = [
        'malformed' => 'Checkpoint protection is configured, but the latest checkpoint record is malformed.',
        'incomplete' => 'Checkpoint protection is configured, but the latest checkpoint record is incomplete.',
        'invalid' => 'Checkpoint protection is configured, but the latest checkpoint record has an invalid field.',
    ];

    /**
     * @return array{state: string, checkpoint: array{sequence: int, tag: string, key_version: int, occurred_at: string, checkpoint_tag: string}|null, message: string}
     */
    public static function inspect(mixed $record): array
    {
        if (! is_array($record) || array_is_list($record)) {
            return self::problem('malformed');
        }

        foreach (['sequence', 'tag', 'key_version', 'occurred_at', 'checkpoint_tag'] as $key) {
            if (! array_key_exists($key, $record)) {
                return self::problem('incomplete');
            }
        }

        $sequence = $record['sequence'];
        $tag = $record['tag'];
        $keyVersion = $record['key_version'];
        $occurredAt = $record['occurred_at'];
        $signature = $record['checkpoint_tag'];
        $valid = is_int($sequence) && $sequence >= 0
            && is_string($tag) && preg_match('/^[0-9a-f]{64}$/', $tag) === 1
            && is_int($keyVersion) && $keyVersion >= 1
            && is_string($occurredAt) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $occurredAt) === 1
            && is_string($signature) && preg_match('/^[0-9a-f]{64}$/', $signature) === 1;

        if (! $valid) {
            return self::problem('invalid');
        }

        return [
            'state' => 'ready',
            'checkpoint' => [
                'sequence' => $sequence,
                'tag' => $tag,
                'key_version' => $keyVersion,
                'occurred_at' => $occurredAt,
                'checkpoint_tag' => $signature,
            ],
            'message' => '',
        ];
    }

    /**
     * @return array{state: string, checkpoint: null, message: string}
     */
    private static function problem(string $state): array
    {
        return [
            'state' => $state,
            'checkpoint' => null,
            'message' => self::MESSAGES[$state],
        ];
    }
}
