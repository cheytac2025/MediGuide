<?php

namespace App\Support;

use Illuminate\Contracts\Session\Session;

/**
 * Marks the current booking draft as AI Front Desk assisted.
 *
 * Stores only clinic/doctor identifiers and the validated specialization label.
 * Conversation text stays in AiFrontDeskConversation until booking succeeds.
 */
final class AiBookingContext
{
    public const SESSION_KEY = 'ai_booking_context';

    /**
     * @param  array{clinic_id?: mixed, doctor_id?: mixed, specialization?: mixed}|null  $context
     * @return array{clinic_id: int, doctor_id: int, specialization: ?string}|null
     */
    public static function validated(?array $context): ?array
    {
        if (! is_array($context)) {
            return null;
        }

        $clinicId = filter_var($context['clinic_id'] ?? null, FILTER_VALIDATE_INT);
        $doctorId = filter_var($context['doctor_id'] ?? null, FILTER_VALIDATE_INT);

        if ($clinicId === false || $doctorId === false || $clinicId < 1 || $doctorId < 1) {
            return null;
        }

        $specialization = $context['specialization'] ?? null;
        $specialization = is_string($specialization) ? trim($specialization) : null;

        if ($specialization === '') {
            $specialization = null;
        }

        return [
            'clinic_id' => $clinicId,
            'doctor_id' => $doctorId,
            'specialization' => $specialization,
        ];
    }

    public static function store(Session $session, int $clinicId, int $doctorId, ?string $specialization): void
    {
        $session->put(self::SESSION_KEY, [
            'clinic_id' => $clinicId,
            'doctor_id' => $doctorId,
            'specialization' => $specialization,
        ]);
    }

    /**
     * @return array{clinic_id: mixed, doctor_id: mixed, specialization: mixed}|null
     */
    public static function get(Session $session): ?array
    {
        $context = $session->get(self::SESSION_KEY);

        return is_array($context) ? $context : null;
    }

    public static function forget(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }

    /**
     * @return array{clinic_id: int, doctor_id: int, specialization: ?string}|null
     */
    public static function consumeValidated(Session $session): ?array
    {
        $context = self::get($session);
        self::forget($session);

        return self::validated($context);
    }
}
