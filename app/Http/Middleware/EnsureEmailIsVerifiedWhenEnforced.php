<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Feature-flag-aware replacement for Laravel's verified middleware.
 *
 * While AUTH_ENFORCE_EMAIL_VERIFICATION is false, unverified accounts keep
 * access to settings/security/password routes so migrated users are not locked
 * out before outbound mail works. When true, behavior matches EnsureEmailIsVerified.
 */
class EnsureEmailIsVerifiedWhenEnforced
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, ?string $redirectToRoute = null): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $needsVerification = $user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail();

        if (! $needsVerification) {
            return $next($request);
        }

        if (! config('auth_security.enforce_email_verification')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(409, 'Your email address is not verified.');
        }

        return Redirect::guest(URL::route($redirectToRoute ?: 'verification.notice'));
    }
}
