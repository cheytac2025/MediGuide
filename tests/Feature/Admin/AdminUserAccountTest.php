<?php

namespace Tests\Feature\Admin;

use App\Enums\AppointmentStatus;
use App\Enums\DayOfWeek;
use App\Enums\DoctorStatus;
use App\Enums\RoleName;
use App\Enums\Sex;
use App\Enums\UserStatus;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DevelopmentClinicSeeder;
use Database\Seeders\DevelopmentDepartmentSeeder;
use Database\Seeders\DevelopmentDoctorScheduleSeeder;
use Database\Seeders\DevelopmentDoctorSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(DevelopmentDepartmentSeeder::class);
        $this->seed(DevelopmentClinicSeeder::class);
        $this->seed(DevelopmentDoctorSeeder::class);
        $this->seed(DevelopmentDoctorScheduleSeeder::class);
    }

    public function test_guests_cannot_access_user_accounts(): void
    {
        $this->get(route('admin.users'))->assertRedirect(route('login'));
    }

    public function test_patients_hospital_staff_and_doctors_receive_403(): void
    {
        foreach ([RoleName::Patient, RoleName::HospitalStaff, RoleName::Doctor] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get(route('admin.users'))
                ->assertForbidden();
        }
    }

    public function test_it_admin_can_access_user_accounts_and_add_staff(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.users'))
            ->assertOk()
            ->assertSee('User Accounts')
            ->assertSee('+ Add Hospital Staff')
            ->assertSee('Doctor accounts are created through Doctor Management.')
            ->assertDontSee('+ Add Doctor')
            ->assertDontSee('two_factor_secret');

        $this->actingAs($this->admin())
            ->get(route('admin.users.staff.create'))
            ->assertOk()
            ->assertSee('Add Hospital Staff');
    }

    public function test_it_admin_creates_hospital_staff_and_cannot_override_the_role(): void
    {
        $doctorRole = Role::query()->where('slug', RoleName::Doctor->value)->firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('admin.users.staff.store'), $this->staffPayload([
                'role' => RoleName::Doctor->value,
                'role_id' => $doctorRole->id,
                'role_slug' => RoleName::ItAdministrator->value,
            ]))
            ->assertRedirect(route('admin.users'))
            ->assertSessionHas('account_status', 'Hospital Staff account created successfully.');

        $user = User::query()->where('email', 'mira.santos@mediguide.test')->firstOrFail();

        $this->assertTrue($user->hasRole(RoleName::HospitalStaff));
        $this->assertFalse($user->hasRole(RoleName::Doctor));
        $this->assertTrue(Hash::check('MediGuide@Staff1', $user->password));
        $this->assertNotSame('MediGuide@Staff1', $user->getRawOriginal('password'));
        $this->assertNull($user->doctor);
    }

    public function test_duplicate_staff_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'mira.santos@mediguide.test']);

        $this->actingAs($this->admin())
            ->post(route('admin.users.staff.store'), $this->staffPayload())
            ->assertSessionHasErrors('email');
    }

    public function test_it_admin_can_edit_staff_name_and_email(): void
    {
        $staff = $this->staffAccount();

        $this->actingAs($this->admin())
            ->patch(route('admin.users.update', $staff), [
                'first_name' => 'Mina',
                'middle_name' => null,
                'last_name' => 'Reyes',
                'email' => 'mina.reyes@mediguide.test',
                'status' => UserStatus::Active->value,
                'role_id' => Role::query()->where('slug', RoleName::Doctor->value)->value('id'),
            ])
            ->assertRedirect(route('admin.users'))
            ->assertSessionHas('account_status', 'Account updated successfully.');

        $staff->refresh();
        $this->assertSame('Mina Reyes', $staff->name);
        $this->assertSame('mina.reyes@mediguide.test', $staff->email);
        $this->assertTrue($staff->hasRole(RoleName::HospitalStaff));
    }

    public function test_email_uniqueness_is_preserved_on_edit(): void
    {
        $staff = $this->staffAccount();
        $other = User::factory()->create(['email' => 'taken@mediguide.test']);

        $this->actingAs($this->admin())
            ->patch(route('admin.users.update', $staff), [
                'first_name' => 'Mira',
                'middle_name' => null,
                'last_name' => 'Santos',
                'email' => $other->email,
                'status' => UserStatus::Active->value,
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_inactive_staff_cannot_log_in_until_reactivated(): void
    {
        $staff = $this->staffAccount();

        $this->actingAs($this->admin())
            ->patch(route('admin.users.status', $staff), ['status' => UserStatus::Inactive->value])
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

        $this->post(route('logout'));

        $this->post(route('login.store'), [
            'email' => $staff->email,
            'password' => 'password',
        ])->assertRedirect(route('staff.dashboard'));
    }

    public function test_doctor_accounts_appear_and_user_status_is_separate_from_doctor_status(): void
    {
        $doctorUser = $this->linkedDoctorUser();

        $this->actingAs($this->admin())
            ->get(route('admin.users', ['filter' => 'doctors']))
            ->assertOk()
            ->assertSee($doctorUser->email)
            ->assertSee('Test Doctor 1');

        $scheduleCount = DoctorSchedule::query()->where('doctor_id', $doctorUser->doctor->id)->count();
        $appointment = $this->appointmentFor($doctorUser->doctor);

        $this->actingAs($this->admin())
            ->patch(route('admin.users.status', $doctorUser), ['status' => UserStatus::Inactive->value]);

        $doctorUser->refresh();
        $this->assertSame(UserStatus::Inactive, $doctorUser->status);
        $this->assertSame(DoctorStatus::Active, $doctorUser->doctor->fresh()->status);
        $this->assertSame($scheduleCount, DoctorSchedule::query()->where('doctor_id', $doctorUser->doctor->id)->count());
        $this->assertDatabaseHas('appointments', ['id' => $appointment->id]);
        $this->assertDatabaseHas('doctors', ['id' => $doctorUser->doctor->id, 'user_id' => $doctorUser->id]);

        $this->post(route('logout'));
        $this->post(route('login.store'), [
            'email' => $doctorUser->email,
            'password' => 'password',
        ]);
        $this->assertGuest();

        $this->actingAs($this->admin())
            ->patch(route('admin.users.status', $doctorUser), ['status' => UserStatus::Active->value]);

        $this->post(route('logout'));
        $this->post(route('login.store'), [
            'email' => $doctorUser->email,
            'password' => 'password',
        ])->assertRedirect(route('doctor.dashboard'));

        $this->assertSame(DoctorStatus::Active, $doctorUser->doctor->fresh()->status);
    }

    public function test_public_registration_cannot_create_privileged_accounts(): void
    {
        foreach ([RoleName::HospitalStaff, RoleName::Doctor, RoleName::ItAdministrator] as $role) {
            $roleModel = Role::query()->where('slug', $role->value)->firstOrFail();

            $this->post(route('register.store'), [
                'first_name' => 'Juan',
                'middle_name' => null,
                'last_name' => 'Dela Cruz',
                'date_of_birth' => '2015-07-24',
                'sex' => Sex::Male->value,
                'contact_number' => '09171234567',
                'email' => $role->value.'@example.com',
                'password' => 'MediGuide@2026',
                'password_confirmation' => 'MediGuide@2026',
                'role_id' => $roleModel->id,
                'role' => $role->value,
                'role_slug' => $role->value,
                'status' => UserStatus::Inactive->value,
            ])->assertRedirect(route('login'));

            $user = User::query()->where('email', $role->value.'@example.com')->firstOrFail();
            $this->assertTrue($user->hasRole(RoleName::Patient));
            $this->assertSame(UserStatus::Active, $user->status);
        }
    }

    public function test_it_admin_cannot_deactivate_their_own_account(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patch(route('admin.users.status', $admin), ['status' => UserStatus::Inactive->value])
            ->assertForbidden();

        $this->assertSame(UserStatus::Active, $admin->fresh()->status);
    }

    public function test_non_admins_cannot_create_edit_or_change_account_status(): void
    {
        $staff = $this->staffAccount();

        foreach ([RoleName::Patient, RoleName::HospitalStaff, RoleName::Doctor] as $role) {
            $actor = User::factory()->role($role)->create();

            $this->actingAs($actor)
                ->post(route('admin.users.staff.store'), $this->staffPayload(['email' => 'other@mediguide.test']))
                ->assertForbidden();

            $this->actingAs($actor)
                ->patch(route('admin.users.update', $staff), [
                    'first_name' => 'Hijack',
                    'middle_name' => null,
                    'last_name' => 'User',
                    'email' => 'hijack@mediguide.test',
                    'status' => UserStatus::Inactive->value,
                ])
                ->assertForbidden();

            $this->actingAs($actor)
                ->patch(route('admin.users.status', $staff), ['status' => UserStatus::Inactive->value])
                ->assertForbidden();
        }

        $this->assertSame(UserStatus::Active, $staff->fresh()->status);
        $this->assertSame('Mira Santos', $staff->fresh()->name);
    }

    public function test_filters_and_sidebar_work(): void
    {
        $staff = $this->staffAccount();
        $doctor = $this->linkedDoctorUser();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.users', ['filter' => 'staff']))
            ->assertOk()
            ->assertSee($staff->email)
            ->assertDontSee($doctor->email);

        $this->actingAs($admin)
            ->get(route('admin.users', ['filter' => 'not-real']))
            ->assertOk()
            ->assertSee($staff->email)
            ->assertSee($doctor->email);

        $this->actingAs($admin)
            ->get(route('admin.users'))
            ->assertOk()
            ->assertSee(route('admin.users'), false)
            ->assertSee('aria-current="page"', false);

        $this->actingAs($admin)
            ->get(route('admin.users.staff.create'))
            ->assertSee('aria-current="page"', false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function staffPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Mira',
            'middle_name' => null,
            'last_name' => 'Santos',
            'email' => 'mira.santos@mediguide.test',
            'password' => 'MediGuide@Staff1',
            'password_confirmation' => 'MediGuide@Staff1',
            'status' => UserStatus::Active->value,
        ], $overrides);
    }

    private function admin(): User
    {
        return User::factory()->role(RoleName::ItAdministrator)->create([
            'first_name' => 'Dev',
            'last_name' => 'Admin',
            'name' => 'Dev Admin',
        ]);
    }

    private function staffAccount(): User
    {
        return User::factory()->role(RoleName::HospitalStaff)->create([
            'first_name' => 'Mira',
            'last_name' => 'Santos',
            'name' => 'Mira Santos',
            'email' => 'mira.staff@mediguide.test',
            'status' => UserStatus::Active,
        ]);
    }

    private function linkedDoctorUser(): User
    {
        $user = User::factory()->role(RoleName::Doctor)->create([
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'name' => 'Ana Cruz',
            'email' => 'ana.doctor@mediguide.test',
            'status' => UserStatus::Active,
        ]);

        Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail()->update([
            'user_id' => $user->id,
        ]);

        return $user->fresh(['doctor']);
    }

    private function appointmentFor(Doctor $doctor): Appointment
    {
        $patient = User::factory()->create();
        $patient->patient()->create([
            'date_of_birth' => '1990-01-01',
            'sex' => Sex::Female,
            'contact_number' => '09170000021',
        ]);

        $schedule = DoctorSchedule::query()
            ->where('doctor_id', $doctor->id)
            ->where('day_of_week', DayOfWeek::Monday)
            ->firstOrFail();

        return Appointment::query()->create([
            'patient_id' => $patient->patient->id,
            'doctor_id' => $doctor->id,
            'doctor_schedule_id' => $schedule->id,
            'appointment_date' => '2026-10-12',
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'status' => AppointmentStatus::Confirmed,
        ]);
    }
}
