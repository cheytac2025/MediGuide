<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
use App\Enums\Sex;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Features;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee('Welcome Back');
        $response->assertSee('Sign in to continue to MediGuide.');
        $response->assertSee('Queen Mary');
        $response->assertSee('Remember Me');
        $response->assertSee('Forgot Password?');
        $response->assertSee('Create Account');
        $response->assertSee('Your Health.');
        $response->assertSee('Patient-Centered');
    }

    public function test_patients_can_authenticate_and_are_redirected_to_the_patient_dashboard(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'name' => 'Juan Dela Cruz',
        ]);

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('patient.dashboard'));
    }

    public function test_users_with_two_factor_enabled_are_redirected_to_two_factor_challenge(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        $user = User::factory()->withTwoFactor()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('two-factor.login'));
        $response->assertSessionHas('login.id', $user->id);
        $this->assertGuest();
    }

    public function test_users_cannot_authenticate_with_an_invalid_password(): void
    {
        $user = User::factory()->create();

        $response = $this->from(route('login'))->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors([
            'email' => 'The email or password you entered is incorrect.',
        ]);
        $this->assertArrayNotHasKey('password', session()->get('_old_input', []));
    }

    public function test_users_cannot_authenticate_with_a_nonexistent_email(): void
    {
        $response = $this->from(route('login'))->post(route('login.store'), [
            'email' => 'missing@example.com',
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors([
            'email' => 'The email or password you entered is incorrect.',
        ]);
        $this->assertArrayNotHasKey('password', session()->get('_old_input', []));
    }

    public function test_invalid_credentials_use_the_same_generic_error(): void
    {
        $user = User::factory()->create();
        $message = 'The email or password you entered is incorrect.';

        $this->from(route('login'))->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors(['email' => $message]);

        $this->from(route('login'))->post(route('login.store'), [
            'email' => 'nobody@example.com',
            'password' => 'password',
        ])->assertSessionHasErrors(['email' => $message]);
    }

    public function test_email_is_required(): void
    {
        $response = $this->from(route('login'))->post(route('login.store'), [
            'email' => '',
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors([
            'email' => 'Email address is required.',
        ]);
    }

    public function test_email_must_be_a_valid_email_address(): void
    {
        $response = $this->from(route('login'))->post(route('login.store'), [
            'email' => 'not-an-email',
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors([
            'email' => 'Please enter a valid email address.',
        ]);
    }

    public function test_password_is_required(): void
    {
        $response = $this->from(route('login'))->post(route('login.store'), [
            'email' => 'juan@example.com',
            'password' => '',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors([
            'password' => 'Password is required.',
        ]);
    }

    public function test_remember_me_sets_the_remember_cookie(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
            'remember' => '1',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertCookie(Auth::guard()->getRecallerName());
        $this->assertNotNull($user->fresh()?->remember_token);
    }

    public function test_guests_cannot_access_the_patient_dashboard(): void
    {
        $this->get(route('patient.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_patients_can_access_the_patient_dashboard_with_their_name(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'name' => 'Juan Dela Cruz',
        ]);

        $response = $this->actingAs($user)->get(route('patient.dashboard'));

        $response->assertOk();
        $response->assertSee('Good day, Juan!');
        $response->assertSee('Juan Dela Cruz');
        $response->assertSee('Patient');
    }

    #[DataProvider('nonPatientRoles')]
    public function test_non_patients_cannot_access_the_patient_dashboard(RoleName $role): void
    {
        $user = User::factory()->role($role)->create();

        $this->actingAs($user)
            ->get(route('patient.dashboard'))
            ->assertForbidden();
    }

    #[DataProvider('nonPatientRoles')]
    public function test_non_patients_are_redirected_to_the_unavailable_dashboard(RoleName $role): void
    {
        $user = User::factory()->role($role)->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard.unavailable'));

        $this->get(route('dashboard.unavailable'))
            ->assertOk()
            ->assertSee('Your dashboard is currently under development.');
    }

    public function test_session_id_is_regenerated_after_login(): void
    {
        $user = User::factory()->create();

        $this->get(route('login'));
        $previousSessionId = session()->getId();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($previousSessionId, session()->getId());
    }

    public function test_users_can_logout_and_lose_dashboard_access(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();

        $this->get(route('patient.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_patients_are_redirected_away_from_login_and_register(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('login'))
            ->assertRedirect(route('patient.dashboard'));

        $this->actingAs($user)
            ->get(route('register'))
            ->assertRedirect(route('patient.dashboard'));
    }

    public function test_registration_still_redirects_to_login_with_success_message(): void
    {
        $response = $this->post(route('register.store'), [
            'first_name' => 'Juan',
            'middle_name' => 'Santos',
            'last_name' => 'Dela Cruz',
            'date_of_birth' => '2015-07-24',
            'sex' => Sex::Male->value,
            'contact_number' => '09171234567',
            'email' => 'juan.loginflow@example.com',
            'password' => 'MediGuide@2026',
            'password_confirmation' => 'MediGuide@2026',
        ]);

        $this->assertGuest();
        $response->assertRedirect(route('login'));
        $response->assertSessionHas(
            'status',
            'Account created successfully. Please sign in using your registered email and password.',
        );

        $this->followRedirects($response)
            ->assertSee('Account created successfully. Please sign in using your registered email and password.');
    }

    public function test_inactive_accounts_cannot_log_in(): void
    {
        $user = User::factory()->inactive()->create();

        $response = $this->from(route('login'))->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors([
            'email' => 'The email or password you entered is incorrect.',
        ]);
    }

    public function test_login_is_rate_limited(): void
    {
        $user = User::factory()->create();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');
        }

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertTooManyRequests();
    }

    /**
     * @return array<string, array{0: RoleName}>
     */
    public static function nonPatientRoles(): array
    {
        return [
            'hospital staff' => [RoleName::HospitalStaff],
            'doctor' => [RoleName::Doctor],
            'it administrator' => [RoleName::ItAdministrator],
        ];
    }
}
