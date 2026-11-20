<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PatientEmailVerificationGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_unverified_patient_keeps_access_when_requirement_flag_is_off(): void
    {
        config(['auth_security.require_verified_patient' => false]);

        $user = User::factory()->unverified()->role(RoleName::Patient)->create([
            'created_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('patient.book-appointment'))
            ->assertOk();
    }

    public function test_new_unverified_patient_is_blocked_when_requirement_flag_is_on(): void
    {
        config([
            'auth_security.require_verified_patient' => true,
            'auth_security.new_account_after' => now()->subMinute()->toIso8601String(),
            'auth_security.grace_until' => now()->addDays(14)->toIso8601String(),
        ]);

        $user = User::factory()->unverified()->role(RoleName::Patient)->create([
            'created_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('patient.book-appointment'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_grandfathered_unverified_patient_remains_accessible_during_notice_period(): void
    {
        config([
            'auth_security.require_verified_patient' => true,
            'auth_security.new_account_after' => now()->subDay()->toIso8601String(),
            'auth_security.grace_until' => now()->addDays(7)->toIso8601String(),
        ]);

        $user = User::factory()->unverified()->role(RoleName::Patient)->create([
            'created_at' => now()->subDays(30),
        ]);

        $this->actingAs($user)
            ->get(route('patient.book-appointment'))
            ->assertOk();

        $this->assertTrue(
            app(\App\Support\Auth\PatientVerificationPolicy::class)->shouldShowVerificationNotice($user)
        );
    }

    public function test_grandfathered_patient_is_blocked_after_grace_deadline(): void
    {
        config([
            'auth_security.require_verified_patient' => true,
            'auth_security.new_account_after' => now()->subDays(10)->toIso8601String(),
            'auth_security.grace_until' => now()->subMinute()->toIso8601String(),
        ]);

        $user = User::factory()->unverified()->role(RoleName::Patient)->create([
            'created_at' => now()->subDays(30),
        ]);

        $this->actingAs($user)
            ->get(route('patient.appointments'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_expired_verification_link_is_rejected(): void
    {
        $user = User::factory()->unverified()->role(RoleName::Patient)->create();

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->subMinutes(5),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $this->actingAs($user)->get($url);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_dashboard_and_logout_remain_reachable_for_unverified_patients(): void
    {
        config([
            'auth_security.require_verified_patient' => true,
            'auth_security.new_account_after' => now()->subMinute()->toIso8601String(),
        ]);

        $user = User::factory()->unverified()->role(RoleName::Patient)->create([
            'created_at' => now(),
        ]);

        $this->actingAs($user)->get(route('patient.dashboard'))->assertOk();
        $this->actingAs($user)->get(route('verification.notice'))->assertOk();
    }
}
