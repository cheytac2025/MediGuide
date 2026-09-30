<?php

namespace Tests\Feature\Admin;

use App\Enums\ClinicStatus;
use App\Enums\DoctorStatus;
use App\Enums\RoleName;
use App\Enums\Sex;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DevelopmentClinicSeeder;
use Database\Seeders\DevelopmentDepartmentSeeder;
use Database\Seeders\DevelopmentDoctorSeeder;
use Database\Seeders\DevelopmentItAdministratorSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(DevelopmentDepartmentSeeder::class);
        $this->seed(DevelopmentClinicSeeder::class);
        $this->seed(DevelopmentDoctorSeeder::class);
    }

    public function test_guests_cannot_access_the_it_admin_dashboard(): void
    {
        $this->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_patients_cannot_access_the_it_admin_dashboard(): void
    {
        $this->actingAs(User::factory()->role(RoleName::Patient)->create())
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    public function test_hospital_staff_cannot_access_the_it_admin_dashboard(): void
    {
        $this->actingAs(User::factory()->role(RoleName::HospitalStaff)->create())
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    public function test_doctors_cannot_access_the_it_admin_dashboard(): void
    {
        $this->actingAs(User::factory()->role(RoleName::Doctor)->create())
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    public function test_it_administrator_can_access_the_dashboard(): void
    {
        $this->actingAs($this->adminUser())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Good day, Dev!')
            ->assertSee('Active Clinics')
            ->assertSee('Active Doctors')
            ->assertSee('Hospital Staff Accounts')
            ->assertSee('Doctor Accounts')
            ->assertSee('Patient Accounts')
            ->assertSee('Clinic Management')
            ->assertSee('Doctor Management')
            ->assertSee('User Account Management')
            ->assertSee('Dashboard')
            ->assertSee('Clinics')
            ->assertSee('Doctors')
            ->assertSee('User Accounts')
            ->assertDontSee('Add Clinic')
            ->assertDontSee('Add Doctor')
            ->assertDontSee('two_factor_secret');
    }

    public function test_it_administrator_login_redirects_to_the_admin_dashboard(): void
    {
        $user = $this->adminUser();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard'));
    }

    public function test_active_clinic_count_uses_real_database_data(): void
    {
        $inactiveDepartment = Department::query()->where('name', 'Development Department C')->firstOrFail();
        $activeDepartment = Department::query()->where('name', 'Development Department A')->firstOrFail();

        Clinic::query()->create([
            'department_id' => $inactiveDepartment->id,
            'name' => 'Active Clinic In Inactive Department',
            'status' => ClinicStatus::Active,
        ]);

        Clinic::query()->create([
            'department_id' => $activeDepartment->id,
            'name' => 'Inactive Clinic In Active Department',
            'status' => ClinicStatus::Inactive,
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-stat="clinics">2', false);
    }

    public function test_active_doctor_count_uses_real_database_data(): void
    {
        $clinic = Clinic::query()->where('name', 'Development Clinic A')->firstOrFail();

        Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'display_name' => 'Extra Inactive Doctor',
            'status' => DoctorStatus::Inactive,
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-stat="doctors">2', false);
    }

    public function test_hospital_staff_account_count_uses_role_data(): void
    {
        User::factory()->role(RoleName::HospitalStaff)->count(2)->create();

        $this->actingAs($this->adminUser())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-stat="staff">2', false);
    }

    public function test_doctor_account_count_uses_role_data(): void
    {
        User::factory()->role(RoleName::Doctor)->create();

        $this->actingAs($this->adminUser())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-stat="doctor-accounts">1', false);
    }

    public function test_patient_account_count_uses_role_data(): void
    {
        User::factory()->role(RoleName::Patient)->count(3)->create();

        $this->actingAs($this->adminUser())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-stat="patients">3', false);
    }

    public function test_development_it_administrator_seeder_creates_the_account(): void
    {
        $this->seed(DevelopmentItAdministratorSeeder::class);

        $user = User::query()->where('email', DevelopmentItAdministratorSeeder::EMAIL)->first();

        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole(RoleName::ItAdministrator));
        $this->assertTrue(Hash::check(DevelopmentItAdministratorSeeder::PASSWORD, $user->password));
        $this->assertSame('active', $user->status->value);
    }

    public function test_development_it_administrator_seeder_is_safe_to_rerun(): void
    {
        $this->seed(DevelopmentItAdministratorSeeder::class);
        $this->seed(DevelopmentItAdministratorSeeder::class);

        $this->assertSame(
            1,
            User::query()->where('email', DevelopmentItAdministratorSeeder::EMAIL)->count(),
        );
    }

    public function test_public_registration_still_creates_a_patient_only(): void
    {
        $this->post(route('register.store'), [
            'first_name' => 'Juan',
            'middle_name' => 'Santos',
            'last_name' => 'Dela Cruz',
            'date_of_birth' => '2015-07-24',
            'sex' => Sex::Male->value,
            'contact_number' => '09171234567',
            'email' => 'juan.admincheck@example.com',
            'password' => 'MediGuide@2026',
            'password_confirmation' => 'MediGuide@2026',
        ])->assertRedirect(route('login'));

        $user = User::query()->where('email', 'juan.admincheck@example.com')->firstOrFail();

        $this->assertTrue($user->hasRole(RoleName::Patient));
        $this->assertGuest();
    }

    public function test_public_registration_cannot_assign_a_privileged_role(): void
    {
        $adminRole = Role::query()->where('slug', RoleName::ItAdministrator->value)->firstOrFail();
        $staffRole = Role::query()->where('slug', RoleName::HospitalStaff->value)->firstOrFail();

        $this->post(route('register.store'), [
            'first_name' => 'Juan',
            'middle_name' => null,
            'last_name' => 'Dela Cruz',
            'date_of_birth' => '2015-07-24',
            'sex' => Sex::Male->value,
            'contact_number' => '09171234567',
            'email' => 'juan.privileged@example.com',
            'password' => 'MediGuide@2026',
            'password_confirmation' => 'MediGuide@2026',
            'role_id' => $adminRole->id,
            'role' => RoleName::HospitalStaff->value,
            'slug' => $staffRole->slug,
        ])->assertRedirect(route('login'));

        $user = User::query()->where('email', 'juan.privileged@example.com')->firstOrFail();

        $this->assertSame(RoleName::Patient->value, $user->role->slug);
        $this->assertNotSame($adminRole->id, $user->role_id);
        $this->assertNotSame($staffRole->id, $user->role_id);
    }

    private function adminUser(): User
    {
        return User::factory()->role(RoleName::ItAdministrator)->create([
            'first_name' => 'Dev',
            'last_name' => 'Admin',
            'name' => 'Dev Admin',
            'email' => 'it-admin-dashboard@example.com',
        ]);
    }
}
