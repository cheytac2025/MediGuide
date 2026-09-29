<?php

namespace App\Notifications;

use App\Models\Appointment;
use App\Support\PatientHeader;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AppointmentConfirmedNotification extends Notification
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
            'title' => 'Appointment Confirmed',
            'message' => "Your appointment with {$doctor} on {$date} at {$time} has been confirmed.",
            'appointment_id' => $this->appointment->id,
            'event' => 'appointment_confirmed',
        ];
    }
}
