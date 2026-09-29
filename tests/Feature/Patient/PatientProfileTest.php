<?php

namespace Tests\Feature\Patient;

use App\Enums\RoleName;
use App\Enums\Sex;
use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PatientProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_guests_cannot_access_patient_profile(): void
    {
        $this->get(route('patient.profile'))
            ->assertRedirect(route('login'));
    }

    public function test_patients_can_access_patient_profile(): void
    {
        $user = $this->patientUser();

        $this->actingAs($user)
            ->get(route('patient.profile'))
            ->assertOk()
            ->assertSee('My Profile')
            ->assertSee('View your registered patient and account information.')
            ->assertSee('Personal Information')
            ->assertSee('Account Information');
    }

    public function test_hospital_staff_cannot_access_patient_profile(): void
    {
        $this->actingAs(User::factory()->role(RoleName::HospitalStaff)->create())
            ->get(route('patient.profile'))
            ->assertForbidden();
    }

    public function test_doctors_cannot_access_patient_profile(): void
    {
        $this->actingAs(User::factory()->role(RoleName::Doctor)->create())
            ->get(route('patient.profile'))
            ->assertForbidden();
    }

    public function test_it_administrators_cannot_access_patient_profile(): void
    {
        $this->actingAs(User::factory()->role(RoleName::ItAdministrator)->create())
            ->get(route('patient.profile'))
            ->assertForbidden();
    }

    public function test_patient_sees_their_own_profile_information(): void
    {
        $user = $this->patientUser([
            'first_name' => 'Juan',
            'middle_name' => null,
            'last_name' => 'Dela Cruz',
            'name' => 'Juan Dela Cruz',
            'email' => 'juan.delacruz@example.com',
        ], [
            'date_of_birth' => '2002-01-15',
            'sex' => Sex::Male,
            'contact_number' => '09171234567',
        ]);

        $this->actingAs($user)
            ->get(route('patient.profile'))
            ->assertOk()
            ->assertSee('Juan Dela Cruz')
            ->assertSee('January 15, 2002')
            ->assertSee('Male')
            ->assertSee('09171234567')
            ->assertSee('juan.delacruz@example.com')
            ->assertSee('Patient')
            ->assertSee('Active');
    }

    public function test_middle_name_is_included_when_present(): void
    {
        $user = $this->patientUser([
            'first_name' => 'Maria',
            'middle_name' => 'Santos',
            'last_name' => 'Reyes',
            'name' => 'Maria Santos Reyes',
        ]);

        $this->actingAs($user)
            ->get(route('patient.profile'))
            ->assertOk()
            ->assertSee('Maria Santos Reyes');
    }

    public function test_missing_optional_middle_name_is_handled_cleanly(): void
    {
        $user = $this->patientUser([
            'first_name' => 'Ana',
            'middle_name' => null,
            'last_name' => 'Lopez',
            'name' => 'Ana Lopez',
        ]);

        $response = $this->actingAs($user)->get(route('patient.profile'));

        $response->assertOk();
        $response->assertSee('Ana Lopez');
        $this->assertStringNotContainsString('Ana  Lopez', $response->getContent());
    }

    public function test_patient_does_not_see_another_patients_information(): void
    {
        $viewer = $this->patientUser([
            'first_name' => 'Viewer',
            'last_name' => 'Patient',
            'name' => 'Viewer Patient',
            'email' => 'viewer@example.com',
        ], [
            'contact_number' => '09171111111',
        ]);

        $this->patientUser([
            'first_name' => 'Other',
            'last_name' => 'Patient',
            'name' => 'Other Patient',
            'email' => 'other@example.com',
        ], [
            'contact_number' => '09172222222',
        ]);

        $this->actingAs($viewer)
            ->get(route('patient.profile'))
            ->assertOk()
            ->assertSee('Viewer Patient')
            ->assertSee('viewer@example.com')
            ->assertSee('09171111111')
            ->assertDontSee('Other Patient')
            ->assertDontSee('other@example.com')
            ->assertDontSee('09172222222');
    }

    public function test_password_and_security_fields_are_not_displayed(): void
    {
        $user = $this->patientUser();

        $response = $this->actingAs($user)->get(route('patient.profile'));

        $response->assertOk();
        $response->assertDontSee('password');
        $response->assertDontSee($user->getAuthPassword());
        $response->assertDontSee('remember_token');
        $response->assertDontSee('two_factor');
        $response->assertDontSee('recovery');
        $response->assertDontSee('role_id');
        $response->assertDontSee('patient_id');
        $response->assertDontSee('user_id');
    }

    public function test_profile_page_contains_no_editable_patient_profile_form(): void
    {
        $user = $this->patientUser();

        $response = $this->actingAs($user)->get(route('patient.profile'));
        $content = $response->getContent();
        $profileStart = strpos($content, 'mg-profile-page');
        $profileEnd = strpos($content, '</main>');
        $profileHtml = strtolower(substr($content, (int) $profileStart, max(0, (int) $profileEnd - (int) $profileStart)));

        $response->assertOk();
        $response->assertDontSee('Edit Profile');
        $response->assertDontSee('Save Changes');
        $response->assertDontSee('Update Profile');
        $response->assertSee('To request corrections to your registered information, please contact authorized hospital personnel.');
        $this->assertStringNotContainsString('<form', $profileHtml);
        $this->assertStringNotContainsString('<input', $profileHtml);
        $this->assertStringNotContainsString('<textarea', $profileHtml);
        $this->assertStringNotContainsString('<select', $profileHtml);
    }

    public function test_no_patient_profile_update_route_was_introduced(): void
    {
        $this->assertTrue(Route::has('patient.profile'));

        $route = Route::getRoutes()->getByName('patient.profile');

        $this->assertNotNull($route);
        $this->assertSame('patient/profile', $route->uri());
        $this->assertSame(['GET', 'HEAD'], $route->methods());

        $this->actingAs($this->patientUser())
            ->post(route('patient.profile'))
            ->assertMethodNotAllowed();

        $this->actingAs($this->patientUser())
            ->put(route('patient.profile'))
            ->assertMethodNotAllowed();

        $this->actingAs($this->patientUser())
            ->patch(route('patient.profile'))
            ->assertMethodNotAllowed();

        $this->actingAs($this->patientUser())
            ->delete(route('patient.profile'))
            ->assertMethodNotAllowed();
    }

    public function test_patient_sidebar_and_header_profile_links_point_to_patient_profile(): void
    {
        $user = $this->patientUser();

        $this->actingAs($user)
            ->get(route('patient.profile'))
            ->assertOk()
            ->assertSee('href="'.route('patient.profile').'"', false)
            ->assertSee('aria-current="page"', false);

        $dashboard = $this->actingAs($user)->get(route('patient.dashboard'));
        $dashboard->assertOk();
        $dashboard->assertSee('href="'.route('patient.profile').'"', false);
    }

    /**
     * @param  array<string, mixed>  $userAttributes
     * @param  array<string, mixed>  $patientAttributes
     */
    private function patientUser(array $userAttributes = [], array $patientAttributes = []): User
    {
        $user = User::factory()->role(RoleName::Patient)->create(array_merge([
            'first_name' => 'Juan',
            'middle_name' => null,
            'last_name' => 'Dela Cruz',
            'name' => 'Juan Dela Cruz',
            'status' => UserStatus::Active,
        ], $userAttributes));

        $user->patient()->create(array_merge([
            'date_of_birth' => '2002-01-15',
            'sex' => Sex::Male,
            'contact_number' => '09171234567',
        ], $patientAttributes));

        return $user->fresh(['patient', 'role']);
    }
}
