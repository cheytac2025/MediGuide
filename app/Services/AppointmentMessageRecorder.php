<?php

namespace App\Services;

use App\Enums\AppointmentMessageKind;
use App\Models\Appointment;
use App\Models\AppointmentMessage;
use App\Models\User;
use App\Support\Appointments\AppointmentMessageText;

/**
 * Appends a patient-visible appointment message. The body stays out of audit metadata.
 */
class AppointmentMessageRecorder
{
    /**
     * @param  array{from_date?: string, from_start?: string, from_end?: string, to_date?: string, to_start?: string, to_end?: string}  $schedule
     */
    public function record(
        Appointment $appointment,
        AppointmentMessageKind $kind,
        ?string $body,
        User $actor,
        array $schedule = [],
    ): ?AppointmentMessage {
        $clean = AppointmentMessageText::clean($body);

        if ($clean === null) {
            return null;
        }

        return AppointmentMessage::query()->create([
            'appointment_id' => $appointment->id,
            'kind' => $kind,
            'body' => $clean,
            'actor_user_id' => $actor->id,
            'from_date' => $schedule['from_date'] ?? null,
            'from_start_time' => $schedule['from_start'] ?? null,
            'from_end_time' => $schedule['from_end'] ?? null,
            'to_date' => $schedule['to_date'] ?? null,
            'to_start_time' => $schedule['to_start'] ?? null,
            'to_end_time' => $schedule['to_end'] ?? null,
        ]);
    }
}
