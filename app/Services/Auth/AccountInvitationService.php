<?php

namespace App\Services\Auth;

use App\Enums\AccountInvitationPurpose;
use App\Enums\AuditAction;
use App\Enums\AuditOutcome;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\AccountInvitation;
use App\Models\User;
use App\Notifications\Auth\AccountActivationInvitation;
use App\Support\Audit\AuditTrailService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

class AccountInvitationService
{
    public function __construct(private readonly AuditTrailService $audit) {}

    /**
     * @return array{invitation: AccountInvitation, plain_token: string}
     */
    public function issue(User $user, AccountInvitationPurpose $purpose, ?User $invitedBy = null): array
    {
        $this->assertEligibleInvitee($user, $purpose);

        $ttlHours = max(1, (int) config('auth_security.account_invitation_ttl_hours', 48));
        $plain = Str::random(64);

        $invitation = DB::transaction(function () use ($user, $purpose, $invitedBy, $plain, $ttlHours): AccountInvitation {
            AccountInvitation::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose->value)
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            return AccountInvitation::query()->create([
                'user_id' => $user->id,
                'invited_by' => $invitedBy?->id,
                'purpose' => $purpose,
                'token_hash' => Hash::make($plain),
                'expires_at' => now()->addHours($ttlHours),
                'last_sent_at' => now(),
                'send_count' => 1,
            ]);
        });

        $this->send($user, $plain, $purpose);

        $this->audit->recordSafely(
            $purpose === AccountInvitationPurpose::Claim
                ? AuditAction::AccountClaimInvitationSent
                : AuditAction::AccountInvitationSent,
            AuditOutcome::Success,
            actor: $invitedBy,
            context: [
                'invitee_user_id' => $user->id,
                'purpose' => $purpose->value,
                'invitation_id' => $invitation->id,
            ],
        );

        return ['invitation' => $invitation, 'plain_token' => $plain];
    }

    public function resend(AccountInvitation $invitation, ?User $actor = null): bool
    {
        if (! $invitation->isOpen()) {
            return false;
        }

        $cooldown = max(0, (int) config('auth_security.account_invitation_resend_cooldown_seconds', 60));
        if ($invitation->last_sent_at !== null && $invitation->last_sent_at->greaterThan(now()->subSeconds($cooldown))) {
            return false;
        }

        $plain = Str::random(64);
        $ttlHours = max(1, (int) config('auth_security.account_invitation_ttl_hours', 48));

        $invitation->forceFill([
            'token_hash' => Hash::make($plain),
            'expires_at' => now()->addHours($ttlHours),
            'last_sent_at' => now(),
            'send_count' => $invitation->send_count + 1,
        ])->save();

        $user = $invitation->user()->firstOrFail();
        $this->send($user, $plain, $invitation->purpose);

        $this->audit->recordSafely(
            AuditAction::AccountInvitationResent,
            AuditOutcome::Success,
            actor: $actor,
            context: [
                'invitee_user_id' => $user->id,
                'purpose' => $invitation->purpose->value,
                'invitation_id' => $invitation->id,
            ],
        );

        return true;
    }

    public function revoke(AccountInvitation $invitation, ?User $actor = null): void
    {
        if ($invitation->revoked_at !== null || $invitation->consumed_at !== null) {
            return;
        }

        $invitation->forceFill(['revoked_at' => now()])->save();

        $this->audit->recordSafely(
            AuditAction::AccountInvitationRevoked,
            AuditOutcome::Success,
            actor: $actor,
            context: [
                'invitee_user_id' => $invitation->user_id,
                'purpose' => $invitation->purpose->value,
                'invitation_id' => $invitation->id,
            ],
        );
    }

    public function findOpenByPlainToken(string $plainToken): ?AccountInvitation
    {
        $candidates = AccountInvitation::query()
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        foreach ($candidates as $invitation) {
            if (Hash::check($plainToken, $invitation->token_hash)) {
                return $invitation;
            }
        }

        return null;
    }

    /**
     * @return 'ok'|'invalid'|'expired'|'revoked'|'consumed'
     */
    public function activate(string $plainToken, string $password): string
    {
        $invitation = AccountInvitation::query()
            ->whereNull('consumed_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->first(fn (AccountInvitation $row) => Hash::check($plainToken, $row->token_hash));

        if ($invitation === null) {
            return 'invalid';
        }

        if ($invitation->revoked_at !== null) {
            return 'revoked';
        }

        if ($invitation->expires_at->isPast()) {
            $this->audit->recordSafely(
                AuditAction::AccountInvitationExpired,
                AuditOutcome::Failure,
                context: ['invitation_id' => $invitation->id, 'invitee_user_id' => $invitation->user_id],
            );

            return 'expired';
        }

        if ($invitation->consumed_at !== null) {
            return 'consumed';
        }

        $user = $invitation->user()->firstOrFail();
        $this->assertEligibleInvitee($user, $invitation->purpose);

        DB::transaction(function () use ($user, $invitation, $password): void {
            $user->forceFill([
                'password' => $password,
                'email_verified_at' => $user->email_verified_at ?? now(),
                'status' => UserStatus::Active,
            ])->save();

            $invitation->forceFill(['consumed_at' => now()])->save();

            AccountInvitation::query()
                ->where('user_id', $user->id)
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->whereKeyNot($invitation->id)
                ->update(['revoked_at' => now()]);
        });

        $this->audit->recordSafely(
            AuditAction::AccountInvitationAccepted,
            AuditOutcome::Success,
            actor: $user,
            context: [
                'invitation_id' => $invitation->id,
                'purpose' => $invitation->purpose->value,
            ],
        );

        return 'ok';
    }

    private function send(User $user, string $plainToken, AccountInvitationPurpose $purpose): void
    {
        $hours = max(1, (int) config('auth_security.account_invitation_ttl_hours', 48));
        $label = $purpose === AccountInvitationPurpose::Claim ? 'claim' : 'activation';

        try {
            Notification::sendNow($user, new AccountActivationInvitation($plainToken, $label, $hours));
        } catch (Throwable $exception) {
            report($exception);

            throw $exception;
        }
    }

    private function assertEligibleInvitee(User $user, AccountInvitationPurpose $purpose): void
    {
        if ($user->hasRole(RoleName::Patient)) {
            throw new \InvalidArgumentException('Patient accounts cannot receive staff invitations.');
        }

        if ($purpose === AccountInvitationPurpose::Activation && $user->status !== UserStatus::PendingActivation) {
            throw new \InvalidArgumentException('Activation invitations require a pending-activation account.');
        }

        if ($purpose === AccountInvitationPurpose::Claim
            && ! in_array($user->status, [UserStatus::Active, UserStatus::Inactive], true)) {
            throw new \InvalidArgumentException('Claim invitations require an active or inactive account.');
        }
    }
}
