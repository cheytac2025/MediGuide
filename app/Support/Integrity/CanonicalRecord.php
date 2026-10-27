<?php

namespace App\Support\Integrity;

use JsonException;

/**
 * Deterministic payload for an HMAC-SHA-256 record seal.
 *
 * Field order, types, and nulls are fixed. Encrypted columns contribute the
 * stored ciphertext, never the decrypted value. Timestamps are excluded.
 */
final class CanonicalRecord
{
    public const APPOINTMENT = 'appointment';

    public const PATIENT = 'patient';

    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly int $version,
        public readonly string $type,
        public readonly int $id,
        public readonly int $keyVersion,
        public readonly array $fields,
    ) {}

    /**
     * @param  object|array<string, mixed>  $row
     */
    public static function appointment(object|array $row, int $keyVersion): self
    {
        $row = (array) $row;

        return new self((int) config('integrity.version'), self::APPOINTMENT, (int) $row['id'], $keyVersion, [
            'patient_id' => (int) $row['patient_id'],
            'doctor_id' => (int) $row['doctor_id'],
            'doctor_schedule_id' => (int) $row['doctor_schedule_id'],
            'appointment_date' => self::date($row['appointment_date'] ?? null),
            'start_time' => self::time($row['start_time'] ?? null),
            'end_time' => self::time($row['end_time'] ?? null),
            'status' => self::text($row['status'] ?? null),
            'patient_concern' => self::nullableText($row['patient_concern'] ?? null),
        ]);
    }

    /**
     * @param  object|array<string, mixed>  $row
     */
    public static function patient(object|array $row, int $keyVersion): self
    {
        $row = (array) $row;

        return new self((int) config('integrity.version'), self::PATIENT, (int) $row['id'], $keyVersion, [
            'user_id' => (int) $row['user_id'],
            'date_of_birth' => self::text($row['date_of_birth'] ?? null),
            'sex' => self::text($row['sex'] ?? null),
            'contact_number' => self::text($row['contact_number'] ?? null),
        ]);
    }

    /**
     * @throws JsonException
     */
    public function encode(): string
    {
        return json_encode([
            'v' => $this->version,
            'type' => $this->type,
            'id' => $this->id,
            'key_version' => $this->keyVersion,
            'fields' => $this->fields,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr((string) $value, 0, 10);
    }

    public static function time(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $time = substr((string) $value, 0, 8);

        return strlen($time) === 5 ? $time.':00' : $time;
    }

    public static function text(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    public static function nullableText(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
