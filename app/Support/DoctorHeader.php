<?php

namespace App\Support;

use App\Models\Doctor;
use App\Models\User;
use Illuminate\Support\Str;

final class DoctorHeader
{
    /**
     * @return array{name: string, first_name: string, role: string, initials: string, unread_notifications: int, display_name: string}
     */
    public static function from(User $user, Doctor $doctor): array
    {
        return [
            'name' => $user->name,
            'first_name' => $user->first_name ?: Str::before($user->name, ' ') ?: $user->name,
            'role' => 'Doctor',
            'initials' => $user->initials(),
            'unread_notifications' => 0,
            'display_name' => $doctor->display_name,
        ];
    }
}
