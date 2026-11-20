<?php

namespace App\Support\Auth;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Grandfather strategy B: notice period for existing unverified patients.
 * Does not mark accounts verified. Does not lock out when the feature flag is off.
 */
final class PatientVerificationPolicy
{
    public function requiresVerifiedEmail(User $user): bool
    {
        if (! config('auth_security.require_verified_patient')) {
            return false;
        }

        if (! $user->hasRole(RoleName::Patient)) {
            return false;
        }

        if ($user->hasVerifiedEmail()) {
            return false;
        }

        if ($this->isGrandfatheredWithinGrace($user)) {
            return false;
        }

        return true;
    }

    public function shouldShowVerificationNotice(User $user): bool
    {
        if (! $user->hasRole(RoleName::Patient) || $user->hasVerifiedEmail()) {
            return false;
        }

        if (! config('auth_security.require_verified_patient')) {
            return false;
        }

        return $this->isGrandfatheredWithinGrace($user);
    }

    public function isGrandfatheredWithinGrace(User $user): bool
    {
        $cutoff = $this->parseOptionalDate(config('auth_security.new_account_after'));

        if ($cutoff === null) {
            // No cutoff configured: every unverified patient is grandfathered (notice only).
            return true;
        }

        $createdAt = $user->created_at ?? now();

        if ($createdAt->greaterThanOrEqualTo($cutoff)) {
            return false;
        }

        $graceUntil = $this->parseOptionalDate(config('auth_security.grace_until'));

        if ($graceUntil === null) {
            // Existing accounts stay accessible until an absolute grace deadline is set.
            return true;
        }

        return now()->lessThanOrEqualTo($graceUntil);
    }

    private function parseOptionalDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
