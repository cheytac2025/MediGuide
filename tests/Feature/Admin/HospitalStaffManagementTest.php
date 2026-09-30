<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\DevelopmentClinicSeeder;
use Database\Seeders\DevelopmentDepartmentSeeder;
use Database\Seeders\DevelopmentDoctorSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HospitalStaffManagementTest extends TestCase
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

    public function test_guests_cannot_access_hospital_staff_management(): void
    {
        $this->get(route('admin.hospital-staff'))
            ->assertRedirect(route('login'));
    }

    public function test_patients_hospital_staff_and_doctors_receive_403(): void
    {
        foreach ([RoleName::Patient, RoleName::HospitalStaff, RoleName::Doctor] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get(route('admin.hospital-staff'))
                ->assertForbidden();
        }
    }

    public function test_it_admin_can_access_hospital_staff_management(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.hospital-staff'))
            ->assertOk()
            ->assertSee('Hospital Staff Management')
            ->assertSee('Manage Hospital Staff accounts and department assignments.');
    }

    public function test_page_lists_hospital_staff_only_and_shows_department_assignments(): void
    {
        $one = $this->staff('Ada Staff', 'ada.staff@mediguide.test', ['Development Department A']);
        $many = $this->staff('Bea Staff', 'bea.staff@mediguide.test', [
            'Development Department A',
            'Development Department B',
        ]);
        $none = $this->staff('Cara Staff', 'cara.staff@mediguide.test', []);
        $doctor = User::factory()->role(RoleName::Doctor)->create([
            'name' => 'Dee Doctor',
            'email' => 'dee.doctor@mediguide.test',
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.hospital-staff'))
            ->assertOk()
            ->assertSee($one->name)
            ->assertSee($one->email)
            ->assertSee($many->name)
            ->assertSee($many->email)
            ->assertSee('Development Department A')
            ->assertSee('Development Department B')
            ->assertSee($none->email)
            ->assertSee('No departments assigned')
            ->assertSee('ACTIVE')
            ->assertSee('Created')
            ->assertDontSee($doctor->email)
            ->assertDontSee($doctor->name);

        $this->assertSame(1, $one->hospitalStaff->departments()->count());
        $this->assertSame(2, $many->fresh()->hospitalStaff->departments()->count());
    }

    public function test_add_and_edit_link_to_the_existing_staff_account_workflow(): void
    {
        $staff = $this->staff('Ada Staff', 'ada.staff@mediguide.test', ['Development Department A']);

        $this->actingAs($this->admin())
            ->get(route('admin.hospital-staff'))
            ->assertOk()
            ->assertSee(route('admin.users.staff.create'), false)
            ->assertSee(route('admin.users.edit', $staff), false)
            ->assertSee(route('admin.users.status', $staff), false);

        $this->actingAs($this->admin())
            ->get(route('admin.users.staff.create'))
            ->assertOk()
            ->assertSee('Add Hospital Staff')
            ->assertSee('Assigned Departments');

        $this->actingAs($this->admin())
            ->get(route('admin.users.edit', $staff))
            ->assertOk()
            ->assertSee('Assigned Departments')
            ->assertSee('Development Department A')
            ->assertDontSee('name="role"', false);
    }

    public function test_activate_and_deactivate_use_existing_account_status_behavior(): void
    {
        $staff = $this->staff('Ada Staff', 'ada.staff@mediguide.test', ['Development Department A']);
        $departmentIds = $staff->hospitalStaff->departments()->pluck('departments.id')->all();

        $this->actingAs($this->admin())
            ->patch(route('admin.users.status', $staff), ['status' => UserStatus::Inactive->value])
            ->assertRedirect(route('admin.users'))
            ->assertSessionHas('account_status', 'Account deactivated successfully.');

        $this->post(route('logout'));

        $this->post(route('login.store'), [
            'email' => $staff->email,
            'password' => 'password',
        ]);
        $this->assertGuest();

        $this->actingAs($this->admin())
            ->patch(route('admin.users.status', $staff), ['status' => UserStatus::Active->value])
            ->assertSessionHas('account_status', 'Account activated successfully.');

        $this->assertEquals($departmentIds, $staff->fresh()->hospitalStaff->departments()->pluck('departments.id')->all());
        $this->assertTrue($staff->fresh()->hasRole(RoleName::HospitalStaff));
    }

    public function test_sidebar_hospital_staff_link_is_active_on_the_management_page(): void
    {
        $response = $this->actingAs($this->admin())
            ->get(route('admin.hospital-staff'));

        $response->assertOk()
            ->assertSeeInOrder(['Doctors', 'Hospital Staff', 'User Accounts'])
            ->assertSee(route('admin.hospital-staff'), false);

        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route('admin.hospital-staff'), '/').'"[^>]*aria-current="page"/',
            $response->getContent(),
        );
    }

    public function test_user_accounts_page_still_lists_staff_and_doctors(): void
    {
        $staff = $this->staff('Ada Staff', 'ada.staff@mediguide.test', ['Development Department A']);
        $doctor = User::factory()->role(RoleName::Doctor)->create([
            'name' => 'Dee Doctor',
            'email' => 'dee.doctor@mediguide.test',
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.users'))
            ->assertOk()
            ->assertSee('User Accounts')
            ->assertSee($staff->email)
            ->assertSee($doctor->email);
    }

    /**
     * @param  list<string>  $departmentNames
     */
    private function staff(string $name, string $email, array $departmentNames): User
    {
        $user = User::factory()->role(RoleName::HospitalStaff)->create([
            'first_name' => $name,
            'last_name' => 'Account',
            'name' => $name,
            'email' => $email,
            'status' => UserStatus::Active,
        ]);

        $profile = $user->hospitalStaff()->create();

        if ($departmentNames !== []) {
            $profile->departments()->sync(
                Department::query()->whereIn('name', $departmentNames)->pluck('id'),
            );
        }

        return $user->fresh('hospitalStaff.departments');
    }

    private function admin(): User
    {
        return User::factory()->role(RoleName::ItAdministrator)->create([
            'first_name' => 'Dev',
            'last_name' => 'Admin',
            'name' => 'Dev Admin',
        ]);
    }
}
