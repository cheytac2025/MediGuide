<?php

namespace App\Support\Integrity;

use App\Exceptions\RecordIntegrityException;
use App\Models\Appointment;
use App\Models\Patient;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;

/**
 * HMAC-SHA-256 seals for patient and appointment rows.
 *
 * A missing seal means the row has not been enrolled. An existing seal that
 * does not match is invalid and is never rewritten from that state.
 *
 * Direct SQL can still change a row. That change will not match the seal.
 * Application query-builder updates of these models are rejected. The seal
 * stores the appointment slot it covered so a tampered date, time, or doctor
 * cannot free the original slot. Concerns, contact numbers, and birth dates
 * are not copied here.
 */
class RecordIntegrityService
{
    public const TABLE = 'record_integrity';

    public const SUBJECTS = 'integrity_subjects';

    public function statusFor(string $type, int $id): IntegrityStatus
    {
        if ($this->seal($type, $id) === null && $this->protection($type, $id) === null) {
            return IntegrityStatus::Unsigned;
        }

        return $this->failureCategory($type, $id) === null
            ? IntegrityStatus::Valid
            : IntegrityStatus::Invalid;
    }

    public function statusOf(Model $model): IntegrityStatus
    {
        if (! $model->exists) {
            return IntegrityStatus::Unsigned;
        }

        return $this->statusFor($this->typeOf($model), (int) $model->getKey());
    }

    /**
     * Null when the row is unsigned or the seal matches. Otherwise a safe category.
     */
    public function failureCategory(string $type, int $id): ?string
    {
        $seal = $this->seal($type, $id);

        if ($seal === null) {
            return $this->protection($type, $id) === null ? null : 'missing_seal';
        }

        $row = $this->row($type, $id);

        if ($row === null) {
            return 'missing_record';
        }

        try {
            $key = $this->keyForVersion((int) $seal->key_version);
            $payload = $this->canonical($type, $row, (int) $seal->key_version)->encode();
        } catch (RuntimeException) {
            return 'unknown_key';
        } catch (JsonException) {
            return 'mismatch';
        }

        $stored = (string) $seal->hmac;

        if (strlen($stored) !== 64 || ! $this->sealsMatch(hash_hmac('sha256', $payload, $key), $stored)) {
            return 'mismatch';
        }

        return null;
    }

    /**
     * Lock the current row and refuse a write that would hide tampering or a stale model.
     */
    public function guardForUpdate(Model $model): IntegrityStatus
    {
        $this->assertInsideTransaction();

        $type = $this->typeOf($model);
        $id = (int) $model->getKey();
        $row = $this->lockSubject($type, $id);
        $this->lockProtection($type, $id);
        $this->lockSeal($type, $id);

        if ($row === null) {
            $this->report($type, $id, 'missing_record');

            throw new RecordIntegrityException($type, $id, 'missing_record');
        }

        if ($this->protectedFieldsDiffer($type, $row, $model)) {
            $this->report($type, $id, 'stale_write');

            throw new RecordIntegrityException($type, $id, 'stale_write');
        }

        $status = $this->statusFor($type, $id);

        if ($status === IntegrityStatus::Invalid) {
            $category = $this->failureCategory($type, $id) ?? 'mismatch';
            $this->report($type, $id, $category);

            throw new RecordIntegrityException($type, $id, $category);
        }

        if ($status === IntegrityStatus::Unsigned && $this->strict()) {
            $this->report($type, $id, 'unsigned');

            throw new RecordIntegrityException($type, $id, 'unsigned');
        }

        return $status;
    }

    /**
     * Write a seal for the current database row.
     *
     * Callers must already know this is a trusted create, a refresh of a
     * valid seal, enrollment of an unsigned row, or rotation of a valid seal.
     */
    public function writeSeal(string $type, int $id): void
    {
        $this->assertInsideTransaction();

        $row = $this->row($type, $id);

        if ($row === null) {
            throw new RuntimeException('Cannot seal a record that does not exist.');
        }

        $keyVersion = (int) config('integrity.key_version');
        $payload = $this->canonical($type, $row, $keyVersion)->encode();
        $hmac = hash_hmac('sha256', $payload, $this->keyForVersion($keyVersion));
        $now = now();
        $binding = $this->slotBinding($type, $row);

        $existing = $this->seal($type, $id);

        if ($existing === null) {
            DB::table(self::TABLE)->insert([
                'subject_type' => $type,
                'subject_id' => $id,
                'integrity_version' => (int) config('integrity.version'),
                'key_version' => $keyVersion,
                'hmac' => $hmac,
                ...$binding,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table(self::TABLE)->where('id', $existing->id)->update([
                'integrity_version' => (int) config('integrity.version'),
                'key_version' => $keyVersion,
                'hmac' => $hmac,
                ...$binding,
                'updated_at' => $now,
            ]);
        }

        $this->rememberProtection($type, $id, $binding, $now);
    }

    /**
     * Seal one unsigned row. Valid and invalid seals are left unchanged.
     */
    public function enrollOne(string $type, int $id): string
    {
        return $this->lockAnd($type, $id, function (?object $row, IntegrityStatus $status) use ($type, $id): string {
            if ($row === null) {
                return 'missing';
            }

            if ($status === IntegrityStatus::Valid) {
                return 'skipped_valid';
            }

            if ($status === IntegrityStatus::Invalid) {
                $this->report($type, $id, $this->failureCategory($type, $id) ?? 'mismatch');

                return 'skipped_invalid';
            }

            $this->writeSeal($type, $id);

            return 'sealed';
        });
    }

    /**
     * Re-sign one seal that already verifies. Unsigned and invalid rows are skipped.
     */
    public function rotateOne(string $type, int $id): string
    {
        return $this->lockAnd($type, $id, function (?object $row, IntegrityStatus $status) use ($type, $id): string {
            if ($row === null || $this->seal($type, $id) === null) {
                return $this->protection($type, $id) === null ? 'unsigned' : 'invalid';
            }

            if ($status !== IntegrityStatus::Valid) {
                $this->report($type, $id, $this->failureCategory($type, $id) ?? 'mismatch');

                return 'invalid';
            }

            $seal = $this->seal($type, $id);

            if ((int) $seal->key_version === (int) config('integrity.key_version')
                && (int) $seal->integrity_version === (int) config('integrity.version')) {
                return 'current';
            }

            $this->writeSeal($type, $id);

            return 'rotated';
        });
    }

    /**
     * Re-seal a row whose protection marker remains after its HMAC was removed.
     *
     * This trusts the current database row. A mismatched HMAC is never repaired.
     */
    public function recoverOne(string $type, int $id): string
    {
        return $this->lockAnd($type, $id, function (?object $row, IntegrityStatus $status) use ($type, $id): string {
            if ($row === null) {
                return 'missing';
            }

            if ($this->failureCategory($type, $id) === 'missing_seal') {
                $this->writeSeal($type, $id);

                return 'recovered';
            }

            if ($status === IntegrityStatus::Valid) {
                return 'skipped_valid';
            }

            if ($status === IntegrityStatus::Invalid) {
                $this->report($type, $id, $this->failureCategory($type, $id) ?? 'mismatch');

                return 'skipped_invalid';
            }

            return 'unsigned';
        });
    }

    /**
     * @template TReturn
     *
     * @param  Closure(?object, IntegrityStatus): TReturn  $callback
     * @return TReturn
     */
    public function lockAnd(string $type, int $id, Closure $callback): mixed
    {
        return DB::transaction(function () use ($type, $id, $callback) {
            $row = $this->lockSubject($type, $id);
            $this->lockProtection($type, $id);
            $this->lockSeal($type, $id);
            $status = $row === null ? IntegrityStatus::Invalid : $this->statusFor($type, $id);

            return $callback($row, $status);
        });
    }

    public function report(string $type, int $id, string $category): void
    {
        Log::warning('Record integrity check failed.', [
            'record_type' => $type,
            'record_id' => $id,
            'category' => $category,
        ]);
    }

    public function typeOf(Model $model): string
    {
        return match ($model::class) {
            Appointment::class => CanonicalRecord::APPOINTMENT,
            Patient::class => CanonicalRecord::PATIENT,
            default => throw new RuntimeException('This record type is not integrity protected.'),
        };
    }

    /**
     * @return list<int>
     */
    public function ids(string $type): array
    {
        $table = $type === CanonicalRecord::APPOINTMENT ? 'appointments' : 'patients';

        return DB::table($table)->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    public function sealsMatch(string $expected, string $stored): bool
    {
        return hash_equals($expected, $stored);
    }

    public function strict(): bool
    {
        return (bool) config('integrity.strict');
    }

    /**
     * Original slots whose seal no longer matches the appointment that held them.
     *
     * @return list<array{start: string, end: string}>
     */
    public function displacedSlots(int $doctorId, string $date, bool $lock = false): array
    {
        return $this->displaced('slot_doctor_id', $doctorId, $date, $lock);
    }

    /**
     * @return list<array{start: string, end: string}>
     */
    public function displacedPatientSlots(int $patientId, string $date, bool $lock = false): array
    {
        return $this->displaced('slot_patient_id', $patientId, $date, $lock);
    }

    /**
     * @return list<array{start: string, end: string}>
     */
    private function displaced(string $ownerColumn, int $ownerId, string $date, bool $lock): array
    {
        $query = DB::table(self::TABLE)
            ->where('subject_type', CanonicalRecord::APPOINTMENT)
            ->where($ownerColumn, $ownerId)
            ->whereDate('slot_date', $date);

        $subjects = $this->protectionQuery($ownerColumn, $ownerId, $date, $lock)->get();

        if ($lock) {
            $query->lockForUpdate();
        }

        $seals = $query->get();

        if ($seals->isEmpty() && $subjects->isEmpty()) {
            return [];
        }

        $slots = [];
        $ids = $seals->pluck('subject_id')->merge($subjects->pluck('subject_id'))->unique()->all();
        $rows = DB::table('appointments')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->id);

        foreach ($seals as $seal) {
            $row = $rows->get((int) $seal->subject_id);
            $invalid = $row === null || $this->failureCategory(CanonicalRecord::APPOINTMENT, (int) $seal->subject_id) !== null;
            $moved = $row !== null && $this->bindingDiffers($seal, $row);

            if (($invalid || $moved) && $seal->slot_start !== null && $seal->slot_end !== null) {
                $slots[] = [
                    'start' => (string) CanonicalRecord::time($seal->slot_start),
                    'end' => (string) CanonicalRecord::time($seal->slot_end),
                ];
            }
        }

        foreach ($subjects as $subject) {
            if ($this->seal(CanonicalRecord::APPOINTMENT, (int) $subject->subject_id) !== null) {
                continue;
            }

            if ($subject->slot_start === null || $subject->slot_end === null) {
                continue;
            }

            $slots[] = [
                'start' => (string) CanonicalRecord::time($subject->slot_start),
                'end' => (string) CanonicalRecord::time($subject->slot_end),
            ];
        }

        return $slots;
    }

    private function bindingDiffers(object $seal, object $row): bool
    {
        return (int) $seal->slot_doctor_id !== (int) $row->doctor_id
            || (int) $seal->slot_patient_id !== (int) $row->patient_id
            || CanonicalRecord::date($seal->slot_date) !== CanonicalRecord::date($row->appointment_date)
            || CanonicalRecord::time($seal->slot_start) !== CanonicalRecord::time($row->start_time)
            || CanonicalRecord::time($seal->slot_end) !== CanonicalRecord::time($row->end_time);
    }

    private function protectedFieldsDiffer(string $type, object $row, Model $model): bool
    {
        $original = $model->getRawOriginal();
        $original['id'] = $row->id;

        return $this->canonical($type, $row, 1)->fields
            !== $this->canonical($type, (object) $original, 1)->fields;
    }

    private function seal(string $type, int $id): ?object
    {
        return DB::table(self::TABLE)
            ->where('subject_type', $type)
            ->where('subject_id', $id)
            ->first();
    }

    private function lockSubject(string $type, int $id): ?object
    {
        $table = $type === CanonicalRecord::APPOINTMENT ? 'appointments' : 'patients';

        return DB::table($table)->where('id', $id)->lockForUpdate()->first();
    }

    private function lockSeal(string $type, int $id): ?object
    {
        return DB::table(self::TABLE)
            ->where('subject_type', $type)
            ->where('subject_id', $id)
            ->lockForUpdate()
            ->first();
    }

    private function protection(string $type, int $id): ?object
    {
        return DB::table(self::SUBJECTS)
            ->where('subject_type', $type)
            ->where('subject_id', $id)
            ->first();
    }

    private function lockProtection(string $type, int $id): ?object
    {
        return DB::table(self::SUBJECTS)
            ->where('subject_type', $type)
            ->where('subject_id', $id)
            ->lockForUpdate()
            ->first();
    }

    private function protectionQuery(string $ownerColumn, int $ownerId, string $date, bool $lock): Builder
    {
        $query = DB::table(self::SUBJECTS)
            ->where('subject_type', CanonicalRecord::APPOINTMENT)
            ->where($ownerColumn, $ownerId)
            ->whereDate('slot_date', $date);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query;
    }

    /**
     * @param  array{slot_patient_id: int|null, slot_doctor_id: int|null, slot_date: string|null, slot_start: string|null, slot_end: string|null}  $binding
     */
    private function rememberProtection(string $type, int $id, array $binding, mixed $now): void
    {
        $existing = $this->protection($type, $id);

        if ($existing === null) {
            DB::table(self::SUBJECTS)->insert([
                'subject_type' => $type,
                'subject_id' => $id,
                ...$binding,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        DB::table(self::SUBJECTS)->where('id', $existing->id)->update([
            ...$binding,
            'updated_at' => $now,
        ]);
    }

    private function row(string $type, int $id): ?object
    {
        $table = $type === CanonicalRecord::APPOINTMENT ? 'appointments' : 'patients';

        return DB::table($table)->where('id', $id)->first();
    }

    private function canonical(string $type, object $row, int $keyVersion): CanonicalRecord
    {
        return $type === CanonicalRecord::APPOINTMENT
            ? CanonicalRecord::appointment($row, $keyVersion)
            : CanonicalRecord::patient($row, $keyVersion);
    }

    /**
     * @return array{slot_patient_id: int|null, slot_doctor_id: int|null, slot_date: string|null, slot_start: string|null, slot_end: string|null}
     */
    private function slotBinding(string $type, object $row): array
    {
        if ($type !== CanonicalRecord::APPOINTMENT) {
            return [
                'slot_patient_id' => null,
                'slot_doctor_id' => null,
                'slot_date' => null,
                'slot_start' => null,
                'slot_end' => null,
            ];
        }

        return [
            'slot_patient_id' => (int) $row->patient_id,
            'slot_doctor_id' => (int) $row->doctor_id,
            'slot_date' => CanonicalRecord::date($row->appointment_date),
            'slot_start' => CanonicalRecord::time($row->start_time),
            'slot_end' => CanonicalRecord::time($row->end_time),
        ];
    }

    private function keyForVersion(int $version): string
    {
        $configured = $version === (int) config('integrity.key_version')
            ? config('integrity.key')
            : ($this->previousKeys()[$version] ?? null);

        if (! is_string($configured) || $configured === '') {
            throw new RuntimeException('The integrity key is not configured.');
        }

        $encoded = str_starts_with($configured, 'base64:') ? substr($configured, 7) : $configured;
        $raw = base64_decode($encoded, true);

        if ($raw === false || strlen($raw) !== 32) {
            throw new RuntimeException('The integrity key must be 256 bits.');
        }

        return $raw;
    }

    /**
     * @return array<int, string>
     */
    private function previousKeys(): array
    {
        $configured = (string) config('integrity.previous_keys');
        $keys = [];

        foreach (explode(',', $configured) as $part) {
            $part = trim($part);

            if ($part === '' || ! str_contains($part, ':')) {
                continue;
            }

            [$version, $material] = explode(':', $part, 2);
            $keys[(int) $version] = $material;
        }

        return $keys;
    }

    private function assertInsideTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('Integrity seals must be written inside the record transaction.');
        }
    }
}
