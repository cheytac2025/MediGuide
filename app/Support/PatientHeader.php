<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class PatientHeader
{
    /**
     * @return array{name: string, first_name: string, role: string, initials: string, unread_notifications: int}
     */
    public static function from(User $user): array
    {
        return [
            'name' => $user->name,
            'first_name' => $user->first_name ?: Str::before($user->name, ' '),
            'role' => 'Patient',
            'initials' => $user->initials(),
            'unread_notifications' => $user->unreadNotifications()->count(),
        ];
    }

    public static function formatAppointmentDate(Appointment $appointment): string
    {
        return $appointment->appointment_date->format('l, F j, Y');
    }

    public static function formatAppointmentTime(Appointment $appointment): string
    {
        $value = (string) $appointment->start_time;

        try {
            return Carbon::createFromFormat('H:i:s', $value)->format('g:i A');
        } catch (\Throwable) {
            try {
                return Carbon::createFromFormat('H:i', $value)->format('g:i A');
            } catch (\Throwable) {
                return $value;
            }
        }
    }
}
