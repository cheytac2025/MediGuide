<?php

namespace Tests\Feature\Admin;

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\ClinicStatus;
use App\Enums\DayOfWeek;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorStatus;
use App\Enums\RoleName;
use App\Enums\Sex;
use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\User;
use App\Services\AiHospitalContextService;
use Database\Seeders\DevelopmentClinicSeeder;
use Database\Seeders\DevelopmentDepartmentSeeder;
use Database\Seeders\DevelopmentDoctorScheduleSeeder;
use Database\Seeders\DevelopmentDoctorSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminDepartmentTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_guests_cannot_access_department_management(): void
    {
        $this->get(route('admin.departments'))->assertRedirect(route('login'));
    }

    public function test_patients_receive_403(): void
    {
        $this->actingAs(User::factory()->role(RoleName::Patient)->create())
            ->get(route('admin.departments'))
            ->assertForbidden();
    }

    public function test_hospital_staff_receive_403(): void
    {
        $this->actingAs(User::factory()->role(RoleName::HospitalStaff)->create())
            ->get(route('admin.departments'))
            ->assertForbidden();
    }

    public function test_doctors_receive_403(): void
    {
        $this->actingAs(User::factory()->role(RoleName::Doctor)->create())
            ->get(route('admin.departments'))
            ->assertForbidden();
    }

    public function test_it_admin_can_view_department_management(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.departments'))
            ->assertOk()
            ->assertSee('Department Management')
            ->assertSee('Manage hospital departments and their availability.')
            ->assertSee('Development Department A')
            ->assertSee('+ Add Department')
            ->assertSee('Edit')
            ->assertSee('Deactivate')
            ->assertSee('clinic')
            ->assertSee('assigned staff');
    }

    public function test_it_admin_can_create_a_department(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.departments.store'), [
                'name' => '  Outpatient Services  ',
                'description' => '  Placeholder department for local development.  ',
                'status' => DepartmentStatus::Active->value,
            ])
            ->assertRedirect(route('admin.departments'))
            ->assertSessionHas('department_status', 'Department added successfully.');

        $department = Department::query()->where('name', 'Outpatient Services')->firstOrFail();

        $this->assertSame('Placeholder department for local development.', $department->description);
        $this->assertSame(DepartmentStatus::Active, $department->status);
        $this->assertSame(0, $department->hospitalStaff()->count());
        $this->assertSame(1, AuditEvent::query()->where('action', AuditAction::DepartmentCreated->value)->count());
    }

    public function test_duplicate_names_are_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.departments.store'), $this->payload('Development Department A'))
            ->assertSessionHasErrors('name');
    }

    public function test_case_insensitive_duplicates_are_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.departments.store'), $this->payload('development department a'))
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Department::query()->whereRaw('LOWER(name) = ?', ['development department a'])->count());
    }

    public function test_invalid_fields_are_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.departments.store'), [
                'name' => '',
                'description' => str_repeat('a', 2001),
                'status' => 'not-a-status',
                'role_id' => 1,
            ])
            ->assertSessionHasErrors(['name', 'description', 'status']);
    }

    public function test_it_admin_can_edit_a_department_without_changing_relationships(): void
    {
        $department = $this->department('Development Department A');
        $clinicIds = $department->clinics()->pluck('id')->sort()->values();
        $staffIds = $department->hospitalStaff()->pluck('hospital_staff.id')->sort()->values();
        $appointment = $this->appointmentFor($department);

        $this->actingAs($this->admin())
            ->patch(route('admin.departments.update', $department), [
                'name' => 'Renamed Development Department',
                'description' => 'Updated department description.',
                'status' => DepartmentStatus::Active->value,
            ])
            ->assertRedirect(route('admin.departments'))
            ->assertSessionHas('department_status', 'Department updated successfully.');

        $department->refresh();
        $this->assertSame('Renamed Development Department', $department->name);
        $this->assertSame('Updated department description.', $department->description);
        $this->assertSame($department->id, $appointment->fresh()->doctor->clinic->department_id);
        $this->assertSame($clinicIds->all(), $department->clinics()->pluck('id')->sort()->values()->all());
        $this->assertSame($staffIds->all(), $department->hospitalStaff()->pluck('hospital_staff.id')->sort()->values()->all());
        $this->assertSame(1, AuditEvent::query()->where('action', AuditAction::DepartmentUpdated->value)->count());
    }

    public function test_it_admin_can_activate_a_department(): void
    {
        $department = $this->department('Development Department C');

        $this->actingAs($this->admin())
            ->patch(route('admin.departments.status', $department), [
                'status' => DepartmentStatus::Active->value,
            ])
            ->assertRedirect(route('admin.departments'))
            ->assertSessionHas('department_status', 'Department activated successfully.');

        $this->assertSame(DepartmentStatus::Active, $department->fresh()->status);
    }

    public function test_it_admin_can_deactivate_a_department_when_allowed(): void
    {
        $department = $this->department('Development Department A');
        $appointment = $this->appointmentFor($department);

        $this->actingAs($this->admin())
            ->get(route('admin.departments'))
            ->assertOk()
            ->assertSee('pending or confirmed future appointment');

        $this->actingAs($this->admin())
            ->patch(route('admin.departments.status', $department), [
                'status' => DepartmentStatus::Inactive->value,
            ])
            ->assertRedirect(route('admin.departments'))
            ->assertSessionHas('department_status', 'Department deactivated successfully.');

        $this->assertSame(DepartmentStatus::Inactive, $department->fresh()->status);
        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'doctor_id' => $appointment->doctor_id,
            'status' => AppointmentStatus::Confirmed->value,
        ]);
        $this->assertSame(1, AuditEvent::query()->where('action', AuditAction::DepartmentStatusChanged->value)->count());
    }

    public function test_existing_appointments_remain_unchanged_after_edit_and_deactivation(): void
    {
        $department = $this->department('Development Department A');
        $appointment = $this->appointmentFor($department);

        $this->actingAs($this->admin())
            ->patch(route('admin.departments.update', $department), [
                'name' => $department->name,
                'description' => $department->description,
                'status' => DepartmentStatus::Inactive->value,
            ]);

        $fresh = $appointment->fresh();
        $this->assertSame($appointment->id, $fresh->id);
        $this->assertSame($appointment->patient_id, $fresh->patient_id);
        $this->assertSame($appointment->doctor_id, $fresh->doctor_id);
        $this->assertSame($appointment->doctor_schedule_id, $fresh->doctor_schedule_id);
        $this->assertSame($appointment->appointment_date->toDateString(), $fresh->appointment_date->toDateString());
        $this->assertSame($appointment->start_time, $fresh->start_time);
        $this->assertSame($appointment->end_time, $fresh->end_time);
        $this->assertSame($appointment->status, $fresh->status);
    }

    public function test_inactive_departments_are_excluded_from_ai_and_new_booking_eligibility(): void
    {
        $department = $this->department('Development Department A');
        $clinic = Clinic::query()->where('name', 'Development Clinic A')->firstOrFail();

        $this->actingAs($this->admin())
            ->patch(route('admin.departments.status', $department), [
                'status' => DepartmentStatus::Inactive->value,
            ]);

        $context = app(AiHospitalContextService::class)->availableContext();
        $this->assertFalse($context->includesClinic($clinic->id));
        $this->assertTrue(app(AiHospitalContextService::class)->eligibleDoctorsForSpecialization('Development Specialization A')->isEmpty());

        $this->actingAs($this->patient())
            ->get(route('patient.book-appointment'))
            ->assertOk()
            ->assertDontSee('Development Clinic A')
            ->assertSee('Development Clinic B');
    }

    public function test_hospital_staff_department_scoping_remains_intact_after_department_changes(): void
    {
        $departmentA = $this->department('Development Department A');
        $created = Department::query()->create([
            'name' => 'Unassigned Department',
            'status' => DepartmentStatus::Active,
        ]);

        $staff = User::factory()->role(RoleName::HospitalStaff)->create();
        $staff->hospitalStaff()->create()->departments()->sync([$departmentA->id]);

        $this->actingAs($this->admin())
            ->patch(route('admin.departments.update', $created), [
                'name' => 'Still Unassigned Department',
                'description' => null,
                'status' => DepartmentStatus::Active->value,
            ]);

        $this->actingAs($staff)
            ->get(route('staff.doctor-schedules'))
            ->assertOk()
            ->assertSee('Test Doctor 1')
            ->assertDontSee('Test Doctor 2');

        $this->assertSame(
            [$departmentA->id],
            $staff->fresh('hospitalStaff.departments')->hospitalStaff->departments->pluck('id')->all(),
        );
    }

    public function test_non_admin_cannot_create_edit_or_change_status(): void
    {
        $department = $this->department('Development Department A');
        $payload = $this->payload('Hijacked Department');

        foreach ([RoleName::Patient, RoleName::HospitalStaff, RoleName::Doctor] as $role) {
            $user = User::factory()->role($role)->create();

            $this->actingAs($user)->post(route('admin.departments.store'), $payload)->assertForbidden();
            $this->actingAs($user)->patch(route('admin.departments.update', $department), [
                'name' => 'Hijacked Department',
                'description' => 'no',
                'status' => DepartmentStatus::Inactive->value,
            ])->assertForbidden();
            $this->actingAs($user)->patch(route('admin.departments.status', $department), [
                'status' => DepartmentStatus::Inactive->value,
            ])->assertForbidden();
        }

        $this->assertSame('Development Department A', $department->fresh()->name);
        $this->assertSame(DepartmentStatus::Active, $department->fresh()->status);
    }

    public function test_status_filters_and_sidebar_active_state(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.departments', ['status' => 'active']))
            ->assertOk()
            ->assertSee('Development Department A')
            ->assertDontSee('Development Department C');

        $this->actingAs($admin)
            ->get(route('admin.departments', ['status' => 'inactive']))
            ->assertOk()
            ->assertSee('Development Department C')
            ->assertDontSee('Development Department A');

        $this->actingAs($admin)
            ->get(route('admin.departments'))
            ->assertOk()
            ->assertSee(route('admin.departments'), false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('Departments')
            ->assertSeeInOrder(['Dashboard', 'Departments', 'Clinics', 'Doctors', 'Hospital Staff', 'User Accounts']);
    }

    public function test_mobile_layout_uses_existing_admin_cards_without_a_wide_table(): void
    {
        $html = $this->actingAs($this->admin())
            ->get(route('admin.departments'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="viewport"', $html);
        $this->assertStringContainsString('mg-staff-appt-page', $html);
        $this->assertStringContainsString('mg-staff-request-card', $html);
        $this->assertStringNotContainsString('<table', $html);
        $this->assertStringContainsString('overflow-x: hidden', (string) file_get_contents(public_path('css/mediguide.css')));
    }

    /**
     * @return array{name: string, description: string, status: string}
     */
    private function payload(string $name): array
    {
        return [
            'name' => $name,
            'description' => 'Placeholder description.',
            'status' => DepartmentStatus::Active->value,
        ];
    }

    private function admin(): User
    {
        return User::factory()->role(RoleName::ItAdministrator)->create();
    }

    private function department(string $name): Department
    {
        return Department::query()->where('name', $name)->firstOrFail();
    }

    private function patient(): User
    {
        $user = User::factory()->create();
        $user->patient()->create([
            'date_of_birth' => '1992-04-01',
            'sex' => Sex::Female,
            'contact_number' => '09170000021',
        ]);

        return $user->fresh(['patient']);
    }

    private function appointmentFor(Department $department): Appointment
    {
        $clinic = Clinic::query()
            ->where('department_id', $department->id)
            ->where('status', ClinicStatus::Active)
            ->firstOrFail();
        $doctor = Doctor::query()
            ->where('clinic_id', $clinic->id)
            ->where('status', DoctorStatus::Active)
            ->firstOrFail();
        $schedule = DoctorSchedule::query()
            ->where('doctor_id', $doctor->id)
            ->where('day_of_week', DayOfWeek::Monday)
            ->firstOrFail();

        return Appointment::query()->create([
            'patient_id' => $this->patient()->patient->id,
            'doctor_id' => $doctor->id,
            'doctor_schedule_id' => $schedule->id,
            'appointment_date' => '2026-10-12',
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'status' => AppointmentStatus::Confirmed,
        ]);
    }
}
