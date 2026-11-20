<?php

namespace App\Notifications;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * In-app notice that doctor-authored patient instructions were published.
 *
 * Payload intentionally omits prescription text and other clinical content.
 */
class PatientInstructionsPublishedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Appointment $appointment,
        public int $revision,
        public string $event = 'patient_instructions_published',
    ) {
        $this->appointment->loadMissing('doctor');
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{title: string, message: string, appointment_id: int, event: string, revision: int, doctor_name: string|null, published_at: string}
     */
    public function toArray(object $notifiable): array
    {
        $isRevision = (int) $this->revision > 1
            || $this->event === 'patient_instructions_revised';

        return [
            'title' => "New Doctor's Instructions",
            'message' => $isRevision
                ? "Your doctor's instructions have been updated."
                : 'Your doctor has published post-consultation instructions for your appointment.',
            'appointment_id' => (int) $this->appointment->id,
            'event' => $isRevision ? 'patient_instructions_revised' : 'patient_instructions_published',
            'revision' => (int) $this->revision,
            'doctor_name' => $this->appointment->doctor?->display_name,
            'published_at' => now()->toIso8601String(),
        ];
    }
}
