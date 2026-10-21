<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class PatientHeader
{
    /**
     * @return array{name: string, first_name: string, role: string, initials: string, unread_notifications: int, recent_notifications: list<array{title: string, message: string, time: string, unread: bool, url: string}>}
     */
    public static function from(User $user): array
    {
        return [
            'name' => $user->name,
            'first_name' => $user->first_name ?: Str::before($user->name, ' '),
            'role' => 'Patient',
            'initials' => $user->initials(),
            'unread_notifications' => $user->unreadNotifications()->count(),
            'recent_notifications' => PatientNotifications::previews($user),
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
