<?php

namespace App\Support;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

class AuthenticatedHome
{
    /**
     * Resolve the post-authentication home route for the given user.
     */
    public static function route(?Authenticatable $user): string
    {
        if (! $user instanceof User) {
            return route('dashboard.unavailable');
        }

        return match ($user->role?->slug) {
            RoleName::Patient->value => route('patient.dashboard'),
            default => route('dashboard.unavailable'),
        };
    }
}
