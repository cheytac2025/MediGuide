<?php

namespace App\Http\Responses;

use App\Enums\AuditAction;
use App\Enums\AuditOutcome;
use App\Models\User;
use App\Support\Audit\AuditTrailService;
use App\Support\AuthenticatedHome;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
use Symfony\Component\HttpFoundation\Response;

class VerifyEmailResponse implements VerifyEmailResponseContract
{
    public function toResponse($request): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            app(AuditTrailService::class)->recordSafely(
                AuditAction::EmailVerificationSucceeded,
                AuditOutcome::Success,
                actor: $user,
            );
        }

        return redirect()->intended(AuthenticatedHome::route($user).'?verified=1');
    }
}
