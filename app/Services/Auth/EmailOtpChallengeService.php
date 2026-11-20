<?php

namespace App\Services\Auth;

use App\Enums\AuditAction;
use App\Enums\AuditOutcome;
use App\Enums\RoleName;
use App\Models\EmailOtpChallenge;
use App\Models\User;
use App\Notifications\Auth\EmailLoginOtpNotification;
use App\Support\Audit\AuditTrailService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Optional patient email OTP. Codes are hashed at rest and never logged.
 */
class EmailOtpChallengeService
{
    public const SESSION_CHALLENGE = 'auth.email_otp.challenge_id';

    public const SESSION_USER = 'auth.email_otp.user_id';

    public const SESSION_PASSED = 'auth.email_otp.passed';

    public function __construct(private readonly AuditTrailService $audit) {}

    public function featureEnabled(): bool
    {
        return (bool) config('auth_security.email_otp_enabled');
    }

    public function userRequiresChallenge(User $user): bool
    {
        return $this->featureEnabled()
            && $user->hasRole(RoleName::Patient)
            && $user->email_otp_enabled
            && $user->hasVerifiedEmail();
    }

    /**
     * @return array{challenge_id: string}
     */
    public function begin(User $user): array
    {
        $plain = $this->generateCode();
        $challengeId = (string) Str::uuid();
        $ttl = max(60, (int) config('auth_security.email_otp_ttl_seconds', 300));

        DB::transaction(function () use ($user, $plain, $challengeId, $ttl): void {
            EmailOtpChallenge::query()
                ->where('user_id', $user->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            EmailOtpChallenge::query()->create([
                'user_id' => $user->id,
                'challenge_id' => $challengeId,
                'code_hash' => Hash::make($plain),
                'attempts' => 0,
                'expires_at' => now()->addSeconds($ttl),
                'last_sent_at' => now(),
            ]);
        });

        $this->sendCode($user, $plain);

        $this->audit->recordSafely(
            AuditAction::EmailOtpSent,
            AuditOutcome::Success,
            actor: $user,
            context: ['reason' => 'challenge_started'],
        );

        return ['challenge_id' => $challengeId];
    }

    public function resend(User $user, string $challengeId): bool
    {
        $challenge = $this->activeChallenge($user, $challengeId);

        if ($challenge === null) {
            return false;
        }

        $cooldown = max(0, (int) config('auth_security.email_otp_resend_cooldown_seconds', 60));

        if ($challenge->last_sent_at !== null && $challenge->last_sent_at->greaterThan(now()->subSeconds($cooldown))) {
            return false;
        }

        $plain = $this->generateCode();
        $challenge->forceFill([
            'code_hash' => Hash::make($plain),
            'attempts' => 0,
            'expires_at' => now()->addSeconds(max(60, (int) config('auth_security.email_otp_ttl_seconds', 300))),
            'last_sent_at' => now(),
        ])->save();

        $this->sendCode($user, $plain);

        $this->audit->recordSafely(
            AuditAction::EmailOtpResent,
            AuditOutcome::Success,
            actor: $user,
            context: ['reason' => 'resend'],
        );

        return true;
    }

    /**
     * @return 'ok'|'invalid'|'expired'|'locked'|'consumed'
     */
    public function verify(User $user, string $challengeId, string $code): string
    {
        $challenge = EmailOtpChallenge::query()
            ->where('user_id', $user->id)
            ->where('challenge_id', $challengeId)
            ->first();

        if ($challenge === null) {
            $this->fail($user, 'missing');

            return 'invalid';
        }

        if ($challenge->consumed_at !== null) {
            $this->fail($user, 'replay');

            return 'consumed';
        }

        if ($challenge->expires_at->isPast()) {
            $this->fail($user, 'expired');

            return 'expired';
        }

        $maxAttempts = max(1, (int) config('auth_security.email_otp_max_attempts', 5));

        if ($challenge->attempts >= $maxAttempts) {
            $this->fail($user, 'locked');

            return 'locked';
        }

        $challenge->increment('attempts');
        $challenge->refresh();

        if (! Hash::check($code, $challenge->code_hash)) {
            $this->fail($user, 'rejected');

            return $challenge->attempts >= $maxAttempts ? 'locked' : 'invalid';
        }

        $challenge->forceFill(['consumed_at' => now()])->save();

        $this->audit->recordSafely(
            AuditAction::EmailOtpSucceeded,
            AuditOutcome::Success,
            actor: $user,
        );

        return 'ok';
    }

    public function activeChallenge(User $user, string $challengeId): ?EmailOtpChallenge
    {
        $challenge = EmailOtpChallenge::query()
            ->where('user_id', $user->id)
            ->where('challenge_id', $challengeId)
            ->whereNull('consumed_at')
            ->first();

        if ($challenge === null || $challenge->expires_at->isPast()) {
            return null;
        }

        return $challenge;
    }

    private function sendCode(User $user, string $plain): void
    {
        try {
            Notification::sendNow($user, new EmailLoginOtpNotification($plain));
        } catch (Throwable $exception) {
            report($exception);

            throw $exception;
        }
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function fail(User $user, string $reason): void
    {
        $this->audit->recordSafely(
            AuditAction::EmailOtpFailed,
            AuditOutcome::Failure,
            actor: $user,
            context: ['reason' => $reason],
        );
    }
}
