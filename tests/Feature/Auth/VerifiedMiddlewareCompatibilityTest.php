<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Tests\TestCase;

class VerifiedMiddlewareCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_unverified_patient_keeps_settings_access_when_enforcement_is_disabled(): void
    {
        config([
            'auth_security.enforce_email_verification' => false,
            'auth_security.require_verified_patient' => false,
        ]);

        $user = User::factory()->unverified()->role(RoleName::Patient)->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertOk();

        $this->actingAs($user)
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'MediGuide@New2026',
                'password_confirmation' => 'MediGuide@New2026',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('security.edit'));

        $this->assertTrue(Hash::check('MediGuide@New2026', $user->fresh()->password));
    }

    /**
     * @return array<string, array{0: RoleName}>
     */
    public static function privilegedRoles(): array
    {
        return [
            'hospital-staff' => [RoleName::HospitalStaff],
            'doctor' => [RoleName::Doctor],
            'it-administrator' => [RoleName::ItAdministrator],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('privilegedRoles')]
    public function test_unverified_privileged_roles_keep_security_access_when_enforcement_is_disabled(RoleName $role): void
    {
        config(['auth_security.enforce_email_verification' => false]);

        $user = User::factory()->unverified()->role($role)->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('appearance.edit'))
            ->assertOk();
    }

    public function test_enforcement_flag_blocks_unverified_users_from_security_settings(): void
    {
        config(['auth_security.enforce_email_verification' => true]);

        $user = User::factory()->unverified()->role(RoleName::Patient)->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_verified_users_retain_security_access_when_enforcement_is_enabled(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
        config(['auth_security.enforce_email_verification' => true]);

        $user = User::factory()->role(RoleName::Patient)->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/security')
                ->where('canManageTwoFactor', true));
    }

    public function test_unverified_patient_can_access_totp_challenge_path_and_password_confirm(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
        config(['auth_security.enforce_email_verification' => false]);

        $user = User::factory()->unverified()->withTwoFactor()->role(RoleName::Patient)->create([
            'password' => Hash::make('password'),
        ]);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.login'));

        $this->get(route('two-factor.login'))->assertOk();
    }

    public function test_new_patient_registration_still_sends_verification_and_signed_link_works(): void
    {
        Notification::fake();

        $this->post(route('register.store'), [
            'first_name' => 'Ana',
            'middle_name' => null,
            'last_name' => 'Reyes',
            'date_of_birth' => '2000-01-15',
            'sex' => 'female',
            'contact_number' => '09171234567',
            'email' => 'ana.verify@example.com',
            'password' => 'MediGuide@2026',
            'password_confirmation' => 'MediGuide@2026',
        ])->assertRedirect(route('login'));

        $user = User::query()->where('email', 'ana.verify@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $this->actingAs($user)->get($url)
            ->assertRedirect(route('patient.dashboard', absolute: false).'?verified=1');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_expired_verification_link_remains_rejected(): void
    {
        $user = User::factory()->unverified()->role(RoleName::Patient)->create();

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->subMinutes(10),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $this->actingAs($user)->get($url);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_patient_workflow_flag_still_blocks_booking_when_enabled_for_new_accounts(): void
    {
        config([
            'auth_security.enforce_email_verification' => false,
            'auth_security.require_verified_patient' => true,
            'auth_security.new_account_after' => now()->subMinute()->toIso8601String(),
            'auth_security.grace_until' => now()->addDay()->toIso8601String(),
        ]);

        $user = User::factory()->unverified()->role(RoleName::Patient)->create([
            'created_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('patient.book-appointment'))
            ->assertRedirect(route('verification.notice'));

        // Settings remain open because enforce_email_verification is false.
        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertOk();
    }

    public function test_cross_role_access_remains_denied_for_unverified_accounts(): void
    {
        config(['auth_security.enforce_email_verification' => false]);

        $patient = User::factory()->unverified()->role(RoleName::Patient)->create();

        $this->actingAs($patient)->get(route('staff.dashboard'))->assertForbidden();
        $this->actingAs($patient)->get(route('doctor.dashboard'))->assertForbidden();
        $this->actingAs($patient)->get(route('admin.dashboard'))->assertForbidden();
    }
}
