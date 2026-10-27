<?php

namespace App\Listeners;

use App\Enums\AuditAction;
use App\Enums\AuditOutcome;
use App\Enums\UserStatus;
use App\Exceptions\AuditTrailException;
use App\Models\User;
use App\Support\Audit\AuditTrailService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Fortify;
use Throwable;

/**
 * Records authentication outcomes without passwords, tokens, or submitted secrets.
 */
class RecordAuthenticationAudit
{
    public function __construct(private readonly AuditTrailService $audit) {}

    public function login(Login $event): void
    {
        $user = $event->user instanceof User ? $event->user : null;
        $passkey = request()->is('passkeys/login');

        try {
            $this->audit->record(
                $passkey ? AuditAction::PasskeySucceeded : AuditAction::LoginSucceeded,
                AuditOutcome::Success,
                actor: $user,
            );
        } catch (Throwable) {
            Auth::guard($event->guard)->logout();

            throw new AuditTrailException;
        }
    }

    public function failed(Failed $event): void
    {
        $username = Fortify::username();
        $submitted = $event->credentials[$username] ?? null;
        $user = is_string($submitted) && $submitted !== ''
            ? User::query()->where($username, $submitted)->first()
            : ($event->user instanceof User ? $event->user : null);
        $inactive = $user instanceof User && $user->status !== UserStatus::Active;

        $this->audit->recordSafely(
            $inactive ? AuditAction::LoginInactive : AuditAction::LoginFailed,
            $inactive ? AuditOutcome::Denied : AuditOutcome::Failure,
            context: ['reason' => $inactive ? 'inactive' : 'rejected'],
            actor: $user instanceof User ? $user : null,
        );
    }

    public function logout(Logout $event): void
    {
        $user = $event->user instanceof User ? $event->user : null;

        $this->audit->recordSafely(
            AuditAction::Logout,
            AuditOutcome::Success,
            actor: $user,
        );
    }
}
