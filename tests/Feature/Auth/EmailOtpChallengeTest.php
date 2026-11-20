<?php

namespace Tests\Feature\Auth;

use App\Enums\AuditAction;
use App\Enums\RoleName;
use App\Models\AuditEvent;
use App\Models\EmailOtpChallenge;
use App\Models\User;
use App\Notifications\Auth\EmailLoginOtpNotification;
use App\Services\Auth\EmailOtpChallengeService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmailOtpChallengeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_optional_email_otp_challenge_succeeds_and_regenerates_session(): void
    {
        config(['auth_security.email_otp_enabled' => true]);
        Notification::fake();

        $user = User::factory()->role(RoleName::Patient)->create([
            'email_otp_enabled' => true,
            'password' => Hash::make('password'),
        ]);

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('auth.email-otp.challenge'));
        Notification::assertSentTo($user, EmailLoginOtpNotification::class);

        $challenge = EmailOtpChallenge::query()->where('user_id', $user->id)->latest('id')->first();
        $this->assertNotNull($challenge);

        $plain = '424242';
        $challenge->forceFill(['code_hash' => Hash::make($plain)])->save();

        $beforeSession = session()->getId();

        $verify = $this->post(route('auth.email-otp.verify'), ['code' => $plain]);
        $verify->assertRedirect(route('patient.dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($beforeSession, session()->getId());
        $this->assertNotNull($challenge->fresh()->consumed_at);
        $this->assertTrue(
            AuditEvent::query()->where('action', AuditAction::EmailOtpSucceeded->value)->exists()
        );
    }

    public function test_email_otp_rejects_invalid_expired_and_replayed_codes(): void
    {
        config(['auth_security.email_otp_enabled' => true]);
        $service = app(EmailOtpChallengeService::class);

        $user = User::factory()->role(RoleName::Patient)->create([
            'email_otp_enabled' => true,
        ]);

        Notification::fake();
        $started = $service->begin($user);
        $challenge = EmailOtpChallenge::query()->where('challenge_id', $started['challenge_id'])->firstOrFail();
        $challenge->forceFill(['code_hash' => Hash::make('111111')])->save();

        $this->assertSame('invalid', $service->verify($user, $started['challenge_id'], '000000'));

        $challenge->forceFill(['expires_at' => now()->subMinute(), 'attempts' => 0])->save();
        $this->assertSame('expired', $service->verify($user, $started['challenge_id'], '111111'));

        $challenge->forceFill([
            'expires_at' => now()->addMinutes(5),
            'attempts' => 0,
            'consumed_at' => null,
            'code_hash' => Hash::make('222222'),
        ])->save();
        $this->assertSame('ok', $service->verify($user, $started['challenge_id'], '222222'));
        $this->assertSame('consumed', $service->verify($user, $started['challenge_id'], '222222'));
    }

    public function test_email_otp_locks_after_max_attempts(): void
    {
        config([
            'auth_security.email_otp_enabled' => true,
            'auth_security.email_otp_max_attempts' => 2,
        ]);
        Notification::fake();

        $service = app(EmailOtpChallengeService::class);
        $user = User::factory()->role(RoleName::Patient)->create(['email_otp_enabled' => true]);
        $started = $service->begin($user);
        EmailOtpChallenge::query()->where('challenge_id', $started['challenge_id'])
            ->update(['code_hash' => Hash::make('999999')]);

        $this->assertSame('invalid', $service->verify($user, $started['challenge_id'], '000000'));
        $this->assertSame('locked', $service->verify($user, $started['challenge_id'], '000000'));
    }

    public function test_email_delivery_failure_blocks_login_completion(): void
    {
        config(['auth_security.email_otp_enabled' => true]);

        Notification::fake();
        Notification::assertNothingSent();

        $user = User::factory()->role(RoleName::Patient)->create([
            'email_otp_enabled' => true,
            'password' => Hash::make('password'),
        ]);

        // Force mail channel failure by swapping notification send path via partial mock of service begin
        $this->mock(EmailOtpChallengeService::class, function ($mock) use ($user) {
            $mock->shouldReceive('featureEnabled')->andReturn(true);
            $mock->shouldReceive('userRequiresChallenge')->withArgs(fn ($u) => $u->is($user))->andReturn(true);
            $mock->shouldReceive('begin')->andThrow(new \RuntimeException('smtp down'));
        });

        $response = $this->from(route('login'))->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_email_otp_not_required_when_feature_disabled_or_user_opted_out(): void
    {
        config(['auth_security.email_otp_enabled' => true]);

        $user = User::factory()->role(RoleName::Patient)->create([
            'email_otp_enabled' => false,
            'password' => Hash::make('password'),
        ]);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('patient.dashboard'));

        config(['auth_security.email_otp_enabled' => false]);
        $optedIn = User::factory()->role(RoleName::Patient)->create([
            'email_otp_enabled' => true,
            'password' => Hash::make('password'),
        ]);

        $this->post(route('login.store'), [
            'email' => $optedIn->email,
            'password' => 'password',
        ])->assertRedirect(route('patient.dashboard'));
    }

    public function test_email_otp_resend_respects_cooldown_and_rotates_code(): void
    {
        config([
            'auth_security.email_otp_enabled' => true,
            'auth_security.email_otp_resend_cooldown_seconds' => 60,
        ]);
        Notification::fake();

        $service = app(EmailOtpChallengeService::class);
        $user = User::factory()->role(RoleName::Patient)->create(['email_otp_enabled' => true]);
        $started = $service->begin($user);
        $challenge = EmailOtpChallenge::query()->where('challenge_id', $started['challenge_id'])->firstOrFail();
        $firstHash = $challenge->code_hash;

        $this->assertFalse($service->resend($user, $started['challenge_id']));

        $challenge->forceFill(['last_sent_at' => now()->subSeconds(61)])->save();
        $this->assertTrue($service->resend($user, $started['challenge_id']));
        $this->assertNotSame($firstHash, $challenge->fresh()->code_hash);
        Notification::assertSentTimes(EmailLoginOtpNotification::class, 2);
    }

    public function test_non_patient_roles_skip_email_otp_even_when_flag_and_column_enabled(): void
    {
        config([
            'auth_security.email_otp_enabled' => true,
            'auth_security.require_email_otp_all_roles' => false,
        ]);
        Notification::fake();

        $doctor = User::factory()->role(RoleName::Doctor)->create([
            'email_otp_enabled' => true,
            'password' => Hash::make('password'),
        ]);

        $this->post(route('login.store'), [
            'email' => $doctor->email,
            'password' => 'password',
        ])->assertRedirect(route('doctor.dashboard'));

        Notification::assertNothingSent();
        $this->assertSame(0, EmailOtpChallenge::query()->where('user_id', $doctor->id)->count());
    }

    public function test_mandatory_all_roles_email_otp_applies_to_each_role(): void
    {
        config([
            'auth_security.email_otp_enabled' => false,
            'auth_security.require_email_otp_all_roles' => true,
        ]);
        Notification::fake();

        $roles = [
            RoleName::Patient->value => 'patient.dashboard',
            RoleName::Doctor->value => 'doctor.dashboard',
            RoleName::HospitalStaff->value => 'staff.dashboard',
            RoleName::ItAdministrator->value => 'admin.dashboard',
        ];

        foreach ($roles as $role => $home) {
            $user = User::factory()->role(RoleName::from($role))->create([
                'email_otp_enabled' => false,
                'password' => Hash::make('password'),
            ]);

            $this->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'password',
            ])->assertRedirect(route('auth.email-otp.challenge'));

            Notification::assertSentTo($user, EmailLoginOtpNotification::class);

            $challenge = EmailOtpChallenge::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
            $plain = '555555';
            $challenge->forceFill(['code_hash' => Hash::make($plain)])->save();

            $this->post(route('auth.email-otp.verify'), ['code' => $plain])
                ->assertRedirect(route($home));

            $this->post(route('logout'));
            Notification::fake();
        }
    }

    public function test_mandatory_otp_default_flag_is_disabled(): void
    {
        $this->assertFalse((bool) config('auth_security.require_email_otp_all_roles'));
    }

    public function test_passkey_style_login_without_otp_session_is_not_blocked(): void
    {
        // Password LoginResponse sets SESSION_USER; WebAuthn/passkey auth does not.
        // EnsureEmailOtpSatisfied only redirects when that session key is present.
        config(['auth_security.require_email_otp_all_roles' => true]);

        $admin = User::factory()->role(RoleName::ItAdministrator)->create();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk();

        $this->assertFalse(session()->has(EmailOtpChallengeService::SESSION_USER));
        $this->assertSame(0, EmailOtpChallenge::query()->where('user_id', $admin->id)->count());
    }
}
