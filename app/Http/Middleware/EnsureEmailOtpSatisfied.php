<?php

namespace App\Http\Middleware;

use App\Services\Auth\EmailOtpChallengeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks authenticated navigation while an email OTP challenge is pending.
 */
class EnsureEmailOtpSatisfied
{
    public function __construct(private readonly EmailOtpChallengeService $otp) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->otp->featureEnabled()) {
            return $next($request);
        }

        if (! $request->session()->has(EmailOtpChallengeService::SESSION_USER)) {
            return $next($request);
        }

        if ($request->session()->get(EmailOtpChallengeService::SESSION_PASSED) === true) {
            return $next($request);
        }

        if ($request->routeIs([
            'auth.email-otp.challenge',
            'auth.email-otp.verify',
            'auth.email-otp.resend',
            'logout',
            'verification.notice',
            'verification.send',
            'verification.verify',
        ])) {
            return $next($request);
        }

        return redirect()->route('auth.email-otp.challenge');
    }
}
