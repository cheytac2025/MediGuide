<?php

namespace Tests\Feature\Staff;

use App\Enums\AppointmentStatus;
use App\Enums\DayOfWeek;
use App\Enums\DoctorScheduleStatus;
use App\Enums\RoleName;
use App\Enums\Sex;
use App\Enums\UserStatus;
use App\Models\Appointment;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\HospitalStaff;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DevelopmentClinicSeeder;
use Database\Seeders\DevelopmentDepartmentSeeder;
use Database\Seeders\DevelopmentDoctorScheduleSeeder;
use Database\Seeders\DevelopmentDoctorSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HospitalStaffDepartmentScopeTest extends TestCase
{
    use RefreshDatabase;

    private int $patientSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05 08:00:00');

        $this->seed(RoleSeeder::class);
        $this->seed(DevelopmentDepartmentSeeder::class);
        $this->seed(DevelopmentClinicSeeder::class);
        $this->seed(DevelopmentDoctorSeeder::class);
        $this->seed(DevelopmentDoctorScheduleSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_admin_can_create_staff_with_one_department(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.users.staff.store'), $this->staffPayload())
            ->assertRedirect(route('admin.users'))
            ->assertSessionHas('account_status');

        $user = User::query()->where('email', 'mira.scope@mediguide.test')->firstOrFail();

        $this->assertTrue($user->hasRole(RoleName::HospitalStaff));
        $this->assertNotNull($user->hospitalStaff);
        $this->assertEquals(
            ['Development Department A'],
            $user->hospitalStaff->departments->pluck('name')->all(),
        );
    }

    public function test_it_admin_can_create_staff_with_multiple_departments(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.users.staff.store'), $this->staffPayload([
                'email' => 'multi.scope@mediguide.test',
                'departments' => [
                    $this->departmentId('Development Department A'),
                    $this->departmentId('Development Department C'),
                ],
            ]))
            ->assertRedirect(route('admin.users'));

        $user = User::query()->where('email', 'multi.scope@mediguide.test')->firstOrFail();

        $this->assertEqualsCanonicalizing(
            ['Development Department A', 'Development Department C'],
            $user->hospitalStaff->departments->pluck('name')->all(),
        );
        $this->assertSame(2, $user->hospitalStaff->departments()->count());
    }

    public function test_staff_creation_requires_at_least_one_department(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.users.staff.store'), $this->staffPayload([
                'departments' => [],
            ]))
            ->assertSessionHasErrors('departments');

        $this->assertDatabaseMissing('users', ['email' => 'mira.scope@mediguide.test']);
    }

    public function test_invalid_department_id_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.users.staff.store'), $this->staffPayload([
                'departments' => [999999],
            ]))
            ->assertSessionHasErrors('departments.0');

        $this->assertDatabaseMissing('users', ['email' => 'mira.scope@mediguide.test']);
    }

    public function test_it_admin_can_edit_staff_department_assignments(): void
    {
        $staff = $this->staff(['Development Department A'], 'edit-scope@example.com');

        $this->actingAs($this->admin())
            ->get(route('admin.users.edit', $staff))
            ->assertOk()
            ->assertSee('Assigned Departments');

        $this->actingAs($this->admin())
            ->patch(route('admin.users.update', $staff), [
                'first_name' => 'Scope',
                'middle_name' => null,
                'last_name' => 'Staff',
                'email' => $staff->email,
                'status' => UserStatus::Active->value,
                'role_id' => Role::query()->where('slug', RoleName::Doctor->value)->value('id'),
                'departments' => [
                    $this->departmentId('Development Department B'),
                    $this->departmentId('Development Department C'),
                ],
            ])
            ->assertRedirect(route('admin.users'));

        $staff->refresh();
        $this->assertTrue($staff->hasRole(RoleName::HospitalStaff));
        $this->assertEqualsCanonicalizing(
            ['Development Department B', 'Development Department C'],
            $staff->hospitalStaff->departments->pluck('name')->all(),
        );
    }

    public function test_duplicate_department_assignment_is_not_created(): void
    {
        $departmentId = $this->departmentId('Development Department A');

        $this->actingAs($this->admin())
            ->post(route('admin.users.staff.store'), $this->staffPayload([
                'departments' => [$departmentId, $departmentId],
            ]))
            ->assertSessionHasErrors('departments.1');

        $this->assertSame(0, HospitalStaff::query()->count());

        $staff = $this->staff(['Development Department A'], 'duplicate-scope@example.com');

        $this->actingAs($this->admin())
            ->patch(route('admin.users.update', $staff), [
                'first_name' => 'Scope',
                'middle_name' => null,
                'last_name' => 'Staff',
                'email' => $staff->email,
                'status' => UserStatus::Active->value,
                'departments' => [$departmentId],
            ])
            ->assertRedirect(route('admin.users'));

        $this->assertSame(1, $staff->hospitalStaff->departments()->count());
    }

    public function test_doctor_edit_page_does_not_expose_staff_department_assignments(): void
    {
        $doctorUser = $this->doctorUser();

        $this->actingAs($this->admin())
            ->get(route('admin.users.edit', $doctorUser))
            ->assertOk()
            ->assertDontSee('Assigned Departments')
            ->assertDontSee('departments[]', false);

        $this->actingAs($this->admin())
            ->patch(route('admin.users.update', $doctorUser), [
                'first_name' => 'Ana',
                'middle_name' => null,
                'last_name' => 'Cruz',
                'email' => $doctorUser->email,
                'status' => UserStatus::Active->value,
                'role_id' => Role::query()->where('slug', RoleName::HospitalStaff->value)->value('id'),
                'departments' => [$this->departmentId('Development Department A')],
            ])
            ->assertRedirect(route('admin.users'));

        $doctorUser->refresh();
        $this->assertTrue($doctorUser->hasRole(RoleName::Doctor));
        $this->assertNull($doctorUser->hospitalStaff);
    }

    public function test_staff_appointment_list_is_limited_to_assigned_departments(): void
    {
        $this->place($this->doctor('Test Doctor 1'), AppointmentStatus::Pending, 'Ada');
        $hidden = [
            'pending' => $this->place($this->doctor('Test Doctor 2'), AppointmentStatus::Pending, 'Bea'),
            'confirmed' => $this->place($this->doctor('Test Doctor 2'), AppointmentStatus::Confirmed, 'Cara'),
            'completed' => $this->place($this->doctor('Test Doctor 2'), AppointmentStatus::Completed, 'Dina'),
            'cancelled' => $this->place($this->doctor('Test Doctor 2'), AppointmentStatus::Cancelled, 'Eva'),
            'rejected' => $this->place($this->doctor('Test Doctor 2'), AppointmentStatus::Rejected, 'Faye'),
        ];
        $staff = $this->staff(['Development Department A']);

        $this->actingAs($staff)
            ->get(route('staff.appointments', ['status' => 'pending']))
            ->assertOk()
            ->assertSee('Ada Scope')
            ->assertDontSee('Bea Scope');

        $this->actingAs($staff)
            ->get(route('staff.appointments', ['status' => 'all']))
            ->assertOk()
            ->assertSee('Ada Scope')
            ->assertDontSee('Bea Scope')
            ->assertDontSee('Cara Scope');

        foreach ($hidden as $filter => $appointment) {
            $this->actingAs($staff)
                ->get(route('staff.appointments', ['status' => $filter]))
                ->assertOk()
                ->assertDontSee($appointment->patient->user->name);
        }
    }

    public function test_dashboard_counts_exclude_unassigned_department_appointments(): void
    {
        $doctorA = $this->doctor('Test Doctor 1');
        $doctorB = $this->doctor('Test Doctor 2');

        $this->place($doctorA, AppointmentStatus::Pending, 'Ada', '2026-10-12');
        $this->place($doctorB, AppointmentStatus::Pending, 'Bea', '2026-10-13');
        $this->place($doctorA, AppointmentStatus::Confirmed, 'Cara', '2026-10-05');
        $this->place($doctorB, AppointmentStatus::Confirmed, 'Dina', '2026-10-05');
        $this->place($doctorA, AppointmentStatus::Confirmed, 'Eva', '2026-10-19');
        $this->place($doctorB, AppointmentStatus::Confirmed, 'Faye', '2026-10-19');
        $this->place($doctorA, AppointmentStatus::Completed, 'Gina', '2026-09-28');
        $this->place($doctorB, AppointmentStatus::Completed, 'Hana', '2026-09-28');

        $this->actingAs($this->staff(['Development Department A']))
            ->get(route('staff.dashboard'))
            ->assertOk()
            ->assertSee('data-stat="pending">1', false)
            ->assertSee('data-stat="today">1', false)
            ->assertSee('data-stat="upcoming">1', false)
            ->assertSee('data-stat="completed">1', false);
    }

    public function test_recent_pending_requests_exclude_unassigned_departments(): void
    {
        $this->place($this->doctor('Test Doctor 1'), AppointmentStatus::Pending, 'Ada');
        $this->place($this->doctor('Test Doctor 2'), AppointmentStatus::Pending, 'Bea');

        $this->actingAs($this->staff(['Development Department A']))
            ->get(route('staff.dashboard'))
            ->assertOk()
            ->assertSee('Ada Scope')
            ->assertDontSee('Bea Scope');
    }

    public function test_staff_can_open_appointment_details_in_assigned_department(): void
    {
        $appointment = $this->place($this->doctor('Test Doctor 1'), AppointmentStatus::Pending, 'Ada');

        $this->actingAs($this->staff(['Development Department A']))
            ->get(route('staff.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('Ada Scope')
            ->assertSee('Development Department A');
    }

    public function test_staff_cannot_open_appointment_details_in_unassigned_department(): void
    {
        $appointment = $this->place($this->doctor('Test Doctor 2'), AppointmentStatus::Pending, 'Bea');

        $this->actingAs($this->staff(['Development Department A']))
            ->get(route('staff.appointments.show', $appointment))
            ->assertNotFound();
    }

    public function test_staff_can_confirm_pending_appointment_in_assigned_department(): void
    {
        $appointment = $this->place($this->doctor('Test Doctor 1'), AppointmentStatus::Pending, 'Ada');

        $this->actingAs($this->staff(['Development Department A']))
            ->patch(route('staff.appointments.confirm', $appointment))
            ->assertRedirect(route('staff.appointments.show', $appointment));

        $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);
    }

    public function test_staff_cannot_confirm_appointment_in_unassigned_department(): void
    {
        $appointment = $this->place($this->doctor('Test Doctor 2'), AppointmentStatus::Pending, 'Bea');

        $this->actingAs($this->staff(['Development Department A']))
            ->patch(route('staff.appointments.confirm', $appointment))
            ->assertNotFound();

        $this->assertSame(AppointmentStatus::Pending, $appointment->fresh()->status);
    }

    public function test_staff_can_reject_pending_appointment_in_assigned_department(): void
    {
        $appointment = $this->place($this->doctor('Test Doctor 1'), AppointmentStatus::Pending, 'Ada');

        $this->actingAs($this->staff(['Development Department A']))
            ->patch(route('staff.appointments.reject', $appointment))
            ->assertRedirect(route('staff.appointments.show', $appointment));

        $this->assertSame(AppointmentStatus::Rejected, $appointment->fresh()->status);
    }

    public function test_staff_cannot_reject_appointment_in_unassigned_department(): void
    {
        $appointment = $this->place($this->doctor('Test Doctor 2'), AppointmentStatus::Pending, 'Bea');

        $this->actingAs($this->staff(['Development Department A']))
            ->patch(route('staff.appointments.reject', $appointment))
            ->assertNotFound();

        $this->assertSame(AppointmentStatus::Pending, $appointment->fresh()->status);
    }

    public function test_staff_doctor_list_contains_only_assigned_department_doctors(): void
    {
        $this->actingAs($this->staff(['Development Department A']))
            ->get(route('staff.doctor-schedules'))
            ->assertOk()
            ->assertSee('Test Doctor 1')
            ->assertDontSee('Test Doctor 2')
            ->assertDontSee('Test Doctor 3');
    }

    public function test_staff_can_open_schedule_management_for_assigned_department_doctor(): void
    {
        $doctor = $this->doctor('Test Doctor 1');

        $this->actingAs($this->staff(['Development Department A']))
            ->get(route('staff.doctor-schedules.show', $doctor))
            ->assertOk()
            ->assertSee('Test Doctor 1');
    }

    public function test_staff_cannot_open_schedule_management_for_unassigned_department_doctor(): void
    {
        $doctor = $this->doctor('Test Doctor 2');

        $this->actingAs($this->staff(['Development Department A']))
            ->get(route('staff.doctor-schedules.show', $doctor))
            ->assertNotFound();
    }

    public function test_staff_can_add_schedule_to_assigned_department_doctor(): void
    {
        $doctor = $this->doctor('Test Doctor 1');

        $this->actingAs($this->staff(['Development Department A']))
            ->post(route('staff.doctor-schedules.store', $doctor), $this->schedulePayload())
            ->assertRedirect(route('staff.doctor-schedules.show', $doctor));

        $this->assertDatabaseHas('doctor_schedules', [
            'doctor_id' => $doctor->id,
            'day_of_week' => DayOfWeek::Friday->value,
            'start_time' => '09:00:00',
        ]);
    }

    public function test_staff_cannot_add_schedule_to_unassigned_department_doctor(): void
    {
        $doctor = $this->doctor('Test Doctor 2');
        $before = DoctorSchedule::query()->where('doctor_id', $doctor->id)->count();

        $this->actingAs($this->staff(['Development Department A']))
            ->post(route('staff.doctor-schedules.store', $doctor), $this->schedulePayload())
            ->assertNotFound();

        $this->assertSame($before, DoctorSchedule::query()->where('doctor_id', $doctor->id)->count());
    }

    public function test_staff_can_edit_schedule_in_assigned_department(): void
    {
        $schedule = $this->scheduleFor('Test Doctor 1');

        $this->actingAs($this->staff(['Development Department A']))
            ->patch(route('staff.doctor-schedules.update', $schedule), $this->editPayload($schedule))
            ->assertRedirect(route('staff.doctor-schedules.show', $schedule->doctor_id));

        $this->assertSame(15, $schedule->fresh()->slot_duration);
    }

    public function test_staff_cannot_edit_schedule_in_unassigned_department(): void
    {
        $schedule = $this->scheduleFor('Test Doctor 2');

        $this->actingAs($this->staff(['Development Department A']))
            ->patch(route('staff.doctor-schedules.update', $schedule), $this->editPayload($schedule))
            ->assertNotFound();

        $this->assertSame(30, $schedule->fresh()->slot_duration);
    }

    public function test_staff_cannot_activate_or_deactivate_schedule_in_unassigned_department(): void
    {
        $schedule = $this->scheduleFor('Test Doctor 2');
        $staff = $this->staff(['Development Department A']);

        $this->actingAs($staff)
            ->patch(route('staff.doctor-schedules.status', $schedule), [
                'status' => DoctorScheduleStatus::Inactive->value,
            ])
            ->assertNotFound();

        $this->assertSame(DoctorScheduleStatus::Active, $schedule->fresh()->status);

        $inactive = DoctorSchedule::query()->create([
            'doctor_id' => $schedule->doctor_id,
            'day_of_week' => DayOfWeek::Friday,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'slot_duration' => 30,
            'status' => DoctorScheduleStatus::Inactive,
        ]);

        $this->actingAs($staff)
            ->patch(route('staff.doctor-schedules.status', $inactive), [
                'status' => DoctorScheduleStatus::Active->value,
            ])
            ->assertNotFound();

        $this->assertSame(DoctorScheduleStatus::Inactive, $inactive->fresh()->status);
    }

    public function test_staff_with_multiple_departments_can_manage_all_assigned_departments(): void
    {
        $appointmentA = $this->place($this->doctor('Test Doctor 1'), AppointmentStatus::Pending, 'Ada');
        $appointmentB = $this->place($this->doctor('Test Doctor 2'), AppointmentStatus::Pending, 'Bea');
        $staff = $this->staff([
            'Development Department A',
            'Development Department B',
        ], 'multi-ops@example.com');

        $this->actingAs($staff)
            ->get(route('staff.appointments'))
            ->assertOk()
            ->assertSee('Ada Scope')
            ->assertSee('Bea Scope');

        $this->actingAs($staff)
            ->get(route('staff.doctor-schedules'))
            ->assertOk()
            ->assertSee('Test Doctor 1')
            ->assertSee('Test Doctor 2');

        $this->actingAs($staff)
            ->get(route('staff.doctor-schedules.show', $this->doctor('Test Doctor 2')))
            ->assertOk();

        $this->actingAs($staff)
            ->patch(route('staff.appointments.confirm', $appointmentA))
            ->assertRedirect();

        $this->actingAs($staff)
            ->patch(route('staff.appointments.reject', $appointmentB))
            ->assertRedirect();

        $this->assertSame(AppointmentStatus::Confirmed, $appointmentA->fresh()->status);
        $this->assertSame(AppointmentStatus::Rejected, $appointmentB->fresh()->status);
    }

    public function test_staff_with_zero_assignments_sees_no_operational_data(): void
    {
        $this->place($this->doctor('Test Doctor 1'), AppointmentStatus::Pending, 'Ada');
        $this->place($this->doctor('Test Doctor 2'), AppointmentStatus::Confirmed, 'Bea', '2026-10-05');
        $staff = $this->staff([]);

        $this->actingAs($staff)
            ->get(route('staff.dashboard'))
            ->assertOk()
            ->assertSee('data-stat="pending">0', false)
            ->assertSee('data-stat="today">0', false)
            ->assertSee('data-stat="upcoming">0', false)
            ->assertSee('data-stat="completed">0', false)
            ->assertDontSee('Ada Scope')
            ->assertDontSee('Bea Scope');

        $this->actingAs($staff)
            ->get(route('staff.appointments'))
            ->assertOk()
            ->assertDontSee('Ada Scope')
            ->assertDontSee('Bea Scope');

        $this->actingAs($staff)
            ->get(route('staff.doctor-schedules'))
            ->assertOk()
            ->assertDontSee('Test Doctor 1')
            ->assertDontSee('Test Doctor 2');
    }

    public function test_staff_with_zero_assignments_cannot_access_resources_directly(): void
    {
        $appointment = $this->place($this->doctor('Test Doctor 1'), AppointmentStatus::Pending, 'Ada');
        $doctor = $this->doctor('Test Doctor 1');
        $schedule = $this->scheduleFor('Test Doctor 1');
        $staff = $this->staff([]);

        $this->actingAs($staff)
            ->get(route('staff.appointments.show', $appointment))
            ->assertNotFound();

        $this->actingAs($staff)
            ->patch(route('staff.appointments.confirm', $appointment))
            ->assertNotFound();

        $this->actingAs($staff)
            ->patch(route('staff.appointments.reject', $appointment))
            ->assertNotFound();

        $this->actingAs($staff)
            ->get(route('staff.doctor-schedules.show', $doctor))
            ->assertNotFound();

        $this->actingAs($staff)
            ->post(route('staff.doctor-schedules.store', $doctor), $this->schedulePayload())
            ->assertNotFound();

        $this->actingAs($staff)
            ->patch(route('staff.doctor-schedules.update', $schedule), $this->editPayload($schedule))
            ->assertNotFound();

        $this->actingAs($staff)
            ->patch(route('staff.doctor-schedules.status', $schedule), [
                'status' => DoctorScheduleStatus::Inactive->value,
            ])
            ->assertNotFound();

        $this->assertSame(AppointmentStatus::Pending, $appointment->fresh()->status);
        $this->assertSame(30, $schedule->fresh()->slot_duration);
        $this->assertSame(DoctorScheduleStatus::Active, $schedule->fresh()->status);
    }

    public function test_patient_doctor_and_it_admin_remain_forbidden_on_staff_routes(): void
    {
        $appointment = $this->place($this->doctor('Test Doctor 1'), AppointmentStatus::Pending, 'Ada');
        $doctor = $this->doctor('Test Doctor 1');

        foreach ([RoleName::Patient, RoleName::Doctor, RoleName::ItAdministrator] as $role) {
            $actor = User::factory()->role($role)->create();

            $this->actingAs($actor)
                ->get(route('staff.appointments.show', $appointment))
                ->assertForbidden();

            $this->actingAs($actor)
                ->patch(route('staff.appointments.confirm', $appointment))
                ->assertForbidden();

            $this->actingAs($actor)
                ->get(route('staff.doctor-schedules.show', $doctor))
                ->assertForbidden();

            $this->actingAs($actor)
                ->post(route('staff.doctor-schedules.store', $doctor), $this->schedulePayload())
                ->assertForbidden();
        }

        $this->assertSame(AppointmentStatus::Pending, $appointment->fresh()->status);
    }

    public function test_existing_staff_appointment_status_rules_still_apply_inside_scope(): void
    {
        $appointment = $this->place($this->doctor('Test Doctor 1'), AppointmentStatus::Confirmed, 'Ada');

        $this->actingAs($this->staff(['Development Department A']))
            ->patch(route('staff.appointments.confirm', $appointment))
            ->assertRedirect(route('staff.appointments.show', $appointment))
            ->assertSessionHas('appointment_error', 'This appointment is no longer pending and cannot be confirmed.');

        $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);
    }

    public function test_existing_schedule_appointment_protection_still_applies_inside_scope(): void
    {
        $schedule = $this->scheduleFor('Test Doctor 1');
        $this->place($this->doctor('Test Doctor 1'), AppointmentStatus::Pending, 'Ada', '2026-10-12');

        $this->actingAs($this->staff(['Development Department A']))
            ->patch(route('staff.doctor-schedules.status', $schedule), [
                'status' => DoctorScheduleStatus::Inactive->value,
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(DoctorScheduleStatus::Active, $schedule->fresh()->status);
    }

    /**
     * @param  list<string>  $departmentNames
     */
    private function staff(array $departmentNames, string $email = 'scope-staff@example.com'): User
    {
        $user = User::factory()->role(RoleName::HospitalStaff)->create([
            'first_name' => 'Scope',
            'last_name' => 'Staff',
            'name' => 'Scope Staff',
            'email' => $email,
        ]);

        $profile = $user->hospitalStaff()->create();

        if ($departmentNames !== []) {
            $profile->departments()->sync(
                Department::query()->whereIn('name', $departmentNames)->pluck('id'),
            );
        }

        return $user;
    }

    private function admin(): User
    {
        return User::factory()->role(RoleName::ItAdministrator)->create([
            'first_name' => 'Dev',
            'last_name' => 'Admin',
            'name' => 'Dev Admin',
        ]);
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
            'email' => 'mira.scope@mediguide.test',
            'password' => 'MediGuide@Staff1',
            'password_confirmation' => 'MediGuide@Staff1',
            'status' => UserStatus::Active->value,
            'departments' => [$this->departmentId('Development Department A')],
        ], $overrides);
    }

    private function departmentId(string $name): int
    {
        return (int) Department::query()->where('name', $name)->value('id');
    }

    private function doctor(string $displayName): Doctor
    {
        return Doctor::query()->where('display_name', $displayName)->firstOrFail();
    }

    private function doctorUser(): User
    {
        $user = User::factory()->role(RoleName::Doctor)->create([
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'name' => 'Ana Cruz',
            'email' => 'ana.scope-doctor@mediguide.test',
            'status' => UserStatus::Active,
        ]);

        $this->doctor('Test Doctor 1')->update(['user_id' => $user->id]);

        return $user->fresh(['doctor']);
    }

    private function scheduleFor(string $displayName): DoctorSchedule
    {
        return DoctorSchedule::query()
            ->where('doctor_id', $this->doctor($displayName)->id)
            ->orderBy('id')
            ->firstOrFail();
    }

    private function place(
        Doctor $doctor,
        AppointmentStatus $status,
        string $firstName,
        string $date = '2026-10-12',
    ): Appointment {
        $this->patientSequence++;

        $user = User::factory()->create([
            'first_name' => $firstName,
            'last_name' => 'Scope',
            'name' => $firstName.' Scope',
            'email' => "scope-patient-{$this->patientSequence}@example.com",
        ]);

        $patient = $user->patient()->create([
            'date_of_birth' => '1990-01-15',
            'sex' => Sex::Female,
            'contact_number' => '0918'.str_pad((string) $this->patientSequence, 7, '0', STR_PAD_LEFT),
        ]);

        $schedule = $this->scheduleFor($doctor->display_name);

        return Appointment::query()->create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'doctor_schedule_id' => $schedule->id,
            'appointment_date' => $date,
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'status' => $status,
        ]);
    }

    /**
     * @return array{day_of_week: string, start_time: string, end_time: string, slot_duration: int, status: string}
     */
    private function schedulePayload(): array
    {
        return [
            'day_of_week' => DayOfWeek::Friday->value,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'slot_duration' => 30,
            'status' => DoctorScheduleStatus::Active->value,
        ];
    }

    /**
     * @return array{day_of_week: string, start_time: string, end_time: string, slot_duration: int, status: string}
     */
    private function editPayload(DoctorSchedule $schedule): array
    {
        return [
            'day_of_week' => $schedule->day_of_week->value,
            'start_time' => substr((string) $schedule->start_time, 0, 5),
            'end_time' => substr((string) $schedule->end_time, 0, 5),
            'slot_duration' => 15,
            'status' => $schedule->status->value,
        ];
    }
}
