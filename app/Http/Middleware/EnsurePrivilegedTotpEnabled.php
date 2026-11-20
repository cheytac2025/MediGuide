<?php

namespace App\Http\Middleware;

use App\Enums\RoleName;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional privileged MFA gate. Disabled by default; does not alter Fortify TOTP.
 */
class EnsurePrivilegedTotpEnabled
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('auth_security.require_totp_privileged')) {
            return $next($request);
        }

        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $privileged = $user->hasRole(RoleName::HospitalStaff)
            || $user->hasRole(RoleName::Doctor)
            || $user->hasRole(RoleName::ItAdministrator);

        if (! $privileged) {
            return $next($request);
        }

        if ($user->two_factor_confirmed_at !== null) {
            return $next($request);
        }

        if ($request->routeIs(['security.edit', 'logout', 'two-factor.*', 'password.confirm*'])) {
            return $next($request);
        }

        return redirect()
            ->route('security.edit')
            ->with('status', 'Two-factor authentication is required for this account.');
    }
}
