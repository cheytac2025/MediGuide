<?php

namespace App\Notifications;

use App\Models\Appointment;
use App\Support\PatientHeader;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AppointmentCancelledNotification extends Notification
{
    use Queueable;

    public function __construct(public Appointment $appointment)
    {
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
     * @return array{title: string, message: string, appointment_id: int, event: string}
     */
    public function toArray(object $notifiable): array
    {
        $doctor = $this->appointment->doctor->display_name;
        $date = PatientHeader::formatAppointmentDate($this->appointment);
        $time = PatientHeader::formatAppointmentTime($this->appointment);

        return [
            'title' => 'Appointment Cancelled',
            'message' => "Your appointment with {$doctor} on {$date} at {$time} has been cancelled.",
            'appointment_id' => $this->appointment->id,
            'event' => 'appointment_cancelled',
        ];
    }
}
