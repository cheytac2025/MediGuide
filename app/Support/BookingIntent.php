<?php

namespace App\Support;

use App\Enums\ClinicStatus;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorScheduleStatus;
use App\Enums\DoctorStatus;
use App\Models\Clinic;
use App\Models\Doctor;
use Illuminate\Contracts\Session\Session;

/**
 * Guest booking handoff from the AI front desk.
 *
 * Stores only clinic/doctor identifiers. Consumers must re-validate
 * before sending a patient to the booking wizard.
 */
final class BookingIntent
{
    public const SESSION_KEY = 'booking_intent';

    /**
     * @param  array{clinic_id?: mixed, doctor_id?: mixed}|null  $intent
     * @return array{clinic_id: int, doctor_id: int}|null
     */
    public static function validated(?array $intent): ?array
    {
        if (! is_array($intent)) {
            return null;
        }

        $clinicId = filter_var($intent['clinic_id'] ?? null, FILTER_VALIDATE_INT);
        $doctorId = filter_var($intent['doctor_id'] ?? null, FILTER_VALIDATE_INT);

        if ($clinicId === false || $doctorId === false || $clinicId < 1 || $doctorId < 1) {
            return null;
        }

        $clinic = Clinic::query()
            ->whereKey($clinicId)
            ->where('status', ClinicStatus::Active)
            ->whereHas('department', fn ($query) => $query->where('status', DepartmentStatus::Active))
            ->first();

        if (! $clinic instanceof Clinic) {
            return null;
        }

        $doctor = Doctor::query()
            ->whereKey($doctorId)
            ->where('clinic_id', $clinic->id)
            ->where('status', DoctorStatus::Active)
            ->whereHas('schedules', fn ($query) => $query->where('status', DoctorScheduleStatus::Active))
            ->first();

        if (! $doctor instanceof Doctor) {
            return null;
        }

        return [
            'clinic_id' => $clinic->id,
            'doctor_id' => $doctor->id,
        ];
    }

    public static function store(Session $session, int $clinicId, int $doctorId): void
    {
        $session->put(self::SESSION_KEY, [
            'clinic_id' => $clinicId,
            'doctor_id' => $doctorId,
        ]);
    }

    /**
     * @return array{clinic_id: mixed, doctor_id: mixed}|null
     */
    public static function get(Session $session): ?array
    {
        $intent = $session->get(self::SESSION_KEY);

        return is_array($intent) ? $intent : null;
    }

    public static function forget(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }

    /**
     * Consume and re-validate the session booking intent.
     *
     * @return array{clinic_id: int, doctor_id: int}|null
     */
    public static function consumeValidated(Session $session): ?array
    {
        $intent = self::get($session);
        self::forget($session);

        return self::validated($intent);
    }
}
