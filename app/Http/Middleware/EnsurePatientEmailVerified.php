<?php

namespace App\Http\Middleware;

use App\Support\Auth\PatientVerificationPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces verified email for patient workflows when the feature flag is on,
 * while preserving grandfather strategy B (notice period, no sudden lockout).
 */
class EnsurePatientEmailVerified
{
    public function __construct(private readonly PatientVerificationPolicy $policy) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if ($this->policy->shouldShowVerificationNotice($user)) {
            $request->session()->now(
                'verification_notice',
                'Please verify your email address. Your account remains accessible during the notice period.',
            );
        }

        if (! $this->policy->requiresVerifiedEmail($user)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Email verification is required.'], 403);
        }

        return redirect()
            ->route('verification.notice')
            ->with('status', 'Please verify your email address to continue.');
    }
}
