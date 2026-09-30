<?php

namespace Tests\Feature\Admin;

use App\Enums\AppointmentStatus;
use App\Enums\ClinicStatus;
use App\Enums\DayOfWeek;
use App\Enums\RoleName;
use App\Enums\Sex;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\User;
use App\Support\AiDisclaimer;
use Database\Seeders\DevelopmentClinicSeeder;
use Database\Seeders\DevelopmentDepartmentSeeder;
use Database\Seeders\DevelopmentDoctorScheduleSeeder;
use Database\Seeders\DevelopmentDoctorSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminClinicTest extends TestCase
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

    public function test_guests_cannot_access_clinic_management(): void
    {
        $this->get(route('admin.clinics'))
            ->assertRedirect(route('login'));
    }

    public function test_patients_receive_403(): void
    {
        $this->actingAs(User::factory()->role(RoleName::Patient)->create())
            ->get(route('admin.clinics'))
            ->assertForbidden();
    }

    public function test_hospital_staff_receive_403(): void
    {
        $this->actingAs(User::factory()->role(RoleName::HospitalStaff)->create())
            ->get(route('admin.clinics'))
            ->assertForbidden();
    }

    public function test_doctors_receive_403(): void
    {
        $this->actingAs(User::factory()->role(RoleName::Doctor)->create())
            ->get(route('admin.clinics'))
            ->assertForbidden();
    }

    public function test_it_admin_can_access_clinic_management(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.clinics'))
            ->assertOk()
            ->assertSee('Clinic Management')
            ->assertSee('Manage hospital clinics and their availability.')
            ->assertSee('Development Clinic A')
            ->assertSee('+ Add Clinic')
            ->assertSee('Edit')
            ->assertSee('Deactivate');
    }

    public function test_it_admin_can_access_add_clinic(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.clinics.create'))
            ->assertOk()
            ->assertSee('Add Clinic')
            ->assertSee('Development Department A');
    }

    public function test_it_admin_can_create_an_active_clinic_under_an_active_department(): void
    {
        $department = $this->department('Development Department A');

        $this->actingAs($this->admin())
            ->post(route('admin.clinics.store'), [
                'name' => 'Outpatient Review Clinic',
                'department_id' => $department->id,
                'description' => 'Development placeholder clinic.',
                'status' => ClinicStatus::Active->value,
            ])
            ->assertRedirect(route('admin.clinics'))
            ->assertSessionHas('clinic_status', 'Clinic added successfully.');

        $this->assertDatabaseHas('clinics', [
            'name' => 'Outpatient Review Clinic',
            'department_id' => $department->id,
            'status' => ClinicStatus::Active->value,
        ]);
    }

    public function test_new_clinic_belongs_to_the_selected_department(): void
    {
        $department = $this->department('Development Department B');

        $this->actingAs($this->admin())
            ->post(route('admin.clinics.store'), $this->payload($department, 'Assigned Clinic'));

        $this->assertSame(
            $department->id,
            Clinic::query()->where('name', 'Assigned Clinic')->value('department_id'),
        );
    }

    public function test_duplicate_clinic_name_in_the_same_department_is_rejected(): void
    {
        $department = $this->department('Development Department A');

        $this->actingAs($this->admin())
            ->post(route('admin.clinics.store'), $this->payload($department, 'Development Clinic A'))
            ->assertSessionHasErrors('name');
    }

    public function test_same_clinic_name_under_a_different_department_is_allowed(): void
    {
        $department = $this->department('Development Department B');

        $this->actingAs($this->admin())
            ->post(route('admin.clinics.store'), $this->payload($department, 'Development Clinic A'))
            ->assertRedirect(route('admin.clinics'));

        $this->assertSame(2, Clinic::query()->where('name', 'Development Clinic A')->count());
    }

    public function test_invalid_department_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.clinics.store'), [
                'name' => 'Orphan Clinic',
                'department_id' => 999_999,
                'description' => null,
                'status' => ClinicStatus::Active->value,
            ])
            ->assertSessionHasErrors('department_id');
    }

    public function test_active_clinic_under_an_inactive_department_is_rejected(): void
    {
        $department = $this->department('Development Department C');

        $this->actingAs($this->admin())
            ->post(route('admin.clinics.store'), $this->payload($department, 'Blocked Active Clinic'))
            ->assertSessionHasErrors('status');

        $this->assertDatabaseMissing('clinics', ['name' => 'Blocked Active Clinic']);
    }

    public function test_it_admin_can_edit_clinic_name(): void
    {
        $clinic = $this->clinic('Development Clinic B');

        $this->actingAs($this->admin())
            ->patch(route('admin.clinics.update', $clinic), [
                'name' => 'Renamed Development Clinic',
                'department_id' => $clinic->department_id,
                'description' => $clinic->description,
                'status' => $clinic->status->value,
            ])
            ->assertRedirect(route('admin.clinics'))
            ->assertSessionHas('clinic_status', 'Clinic updated successfully.');

        $this->assertSame('Renamed Development Clinic', $clinic->fresh()->name);
    }

    public function test_it_admin_can_edit_description(): void
    {
        $clinic = $this->clinic('Development Clinic B');

        $this->actingAs($this->admin())
            ->patch(route('admin.clinics.update', $clinic), [
                'name' => $clinic->name,
                'department_id' => $clinic->department_id,
                'description' => 'Updated clinic description.',
                'status' => $clinic->status->value,
            ]);

        $this->assertSame('Updated clinic description.', $clinic->fresh()->description);
    }

    public function test_it_admin_can_change_department_without_removing_related_records(): void
    {
        $clinic = $this->clinic('Development Clinic A');
        $target = $this->department('Development Department B');
        $doctor = Doctor::query()->where('clinic_id', $clinic->id)->firstOrFail();
        $appointment = $this->appointmentFor($clinic);

        $this->actingAs($this->admin())
            ->patch(route('admin.clinics.update', $clinic), [
                'name' => $clinic->name,
                'department_id' => $target->id,
                'description' => $clinic->description,
                'status' => ClinicStatus::Active->value,
            ])
            ->assertSessionHas('clinic_status');

        $this->assertSame($target->id, $clinic->fresh()->department_id);
        $this->assertDatabaseHas('doctors', ['id' => $doctor->id, 'clinic_id' => $clinic->id]);
        $this->assertDatabaseHas('appointments', ['id' => $appointment->id]);
    }

    public function test_duplicate_combination_on_edit_is_rejected(): void
    {
        $clinic = $this->clinic('Development Clinic B');
        $department = $this->department('Development Department A');

        $this->actingAs($this->admin())
            ->patch(route('admin.clinics.update', $clinic), [
                'name' => 'Development Clinic A',
                'department_id' => $department->id,
                'description' => $clinic->description,
                'status' => ClinicStatus::Active->value,
            ])
            ->assertSessionHasErrors('name');
    }

    public function test_it_admin_can_deactivate_a_clinic(): void
    {
        $clinic = $this->clinic('Development Clinic A');

        $this->actingAs($this->admin())
            ->patch(route('admin.clinics.status', $clinic), [
                'status' => ClinicStatus::Inactive->value,
            ])
            ->assertRedirect(route('admin.clinics'))
            ->assertSessionHas('clinic_status', 'Clinic deactivated successfully.');

        $this->assertSame(ClinicStatus::Inactive, $clinic->fresh()->status);
    }

    public function test_it_admin_can_activate_a_clinic(): void
    {
        $clinic = $this->clinic('Development Clinic A');
        $clinic->update(['status' => ClinicStatus::Inactive]);

        $this->actingAs($this->admin())
            ->patch(route('admin.clinics.status', $clinic), [
                'status' => ClinicStatus::Active->value,
            ])
            ->assertSessionHas('clinic_status', 'Clinic activated successfully.');

        $this->assertSame(ClinicStatus::Active, $clinic->fresh()->status);
    }

    public function test_clinic_cannot_activate_under_an_inactive_department(): void
    {
        $clinic = $this->clinic('Development Clinic C');

        $this->actingAs($this->admin())
            ->patch(route('admin.clinics.status', $clinic), [
                'status' => ClinicStatus::Active->value,
            ])
            ->assertSessionHas('clinic_error', 'This clinic cannot be activated because its department is inactive.');

        $this->assertSame(ClinicStatus::Inactive, $clinic->fresh()->status);
    }

    public function test_patients_cannot_create_a_clinic(): void
    {
        $this->actingAs(User::factory()->role(RoleName::Patient)->create())
            ->post(route('admin.clinics.store'), $this->payload($this->department('Development Department A'), 'Blocked'))
            ->assertForbidden();
    }

    public function test_staff_cannot_create_a_clinic(): void
    {
        $this->actingAs(User::factory()->role(RoleName::HospitalStaff)->create())
            ->post(route('admin.clinics.store'), $this->payload($this->department('Development Department A'), 'Blocked'))
            ->assertForbidden();
    }

    public function test_doctors_cannot_create_a_clinic(): void
    {
        $this->actingAs(User::factory()->role(RoleName::Doctor)->create())
            ->post(route('admin.clinics.store'), $this->payload($this->department('Development Department A'), 'Blocked'))
            ->assertForbidden();
    }

    public function test_non_admin_cannot_edit_a_clinic(): void
    {
        $clinic = $this->clinic('Development Clinic A');

        foreach ([RoleName::Patient, RoleName::HospitalStaff, RoleName::Doctor] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->patch(route('admin.clinics.update', $clinic), [
                    'name' => 'Hijacked Clinic',
                    'department_id' => $clinic->department_id,
                    'description' => 'no',
                    'status' => ClinicStatus::Inactive->value,
                ])
                ->assertForbidden();
        }

        $this->assertSame('Development Clinic A', $clinic->fresh()->name);
    }

    public function test_non_admin_cannot_change_clinic_status(): void
    {
        $clinic = $this->clinic('Development Clinic A');

        $this->actingAs(User::factory()->role(RoleName::Patient)->create())
            ->patch(route('admin.clinics.status', $clinic), [
                'status' => ClinicStatus::Inactive->value,
            ])
            ->assertForbidden();

        $this->assertSame(ClinicStatus::Active, $clinic->fresh()->status);
    }

    public function test_inactive_clinic_is_excluded_from_patient_booking(): void
    {
        $this->clinic('Development Clinic A')->update(['status' => ClinicStatus::Inactive]);

        $this->actingAs($this->patient())
            ->get(route('patient.book-appointment'))
            ->assertOk()
            ->assertDontSee('Development Clinic A')
            ->assertSee('Development Clinic B');
    }

    public function test_active_clinic_is_available_to_patient_booking(): void
    {
        $this->actingAs($this->patient())
            ->get(route('patient.book-appointment'))
            ->assertOk()
            ->assertSee('Development Clinic A')
            ->assertSee('Development Clinic B');
    }

    public function test_deactivation_does_not_delete_doctors_schedules_or_appointments(): void
    {
        $clinic = $this->clinic('Development Clinic A');
        $doctorIds = Doctor::query()->where('clinic_id', $clinic->id)->pluck('id');
        $scheduleCount = DoctorSchedule::query()->whereIn('doctor_id', $doctorIds)->count();
        $appointment = $this->appointmentFor($clinic);

        $this->actingAs($this->admin())
            ->patch(route('admin.clinics.status', $clinic), [
                'status' => ClinicStatus::Inactive->value,
            ]);

        $this->assertSame($doctorIds->count(), Doctor::query()->whereIn('id', $doctorIds)->count());
        $this->assertSame($scheduleCount, DoctorSchedule::query()->whereIn('doctor_id', $doctorIds)->count());
        $this->assertDatabaseHas('appointments', ['id' => $appointment->id]);
    }

    public function test_existing_appointments_remain_viewable_after_clinic_deactivation(): void
    {
        $clinic = $this->clinic('Development Clinic A');
        $patient = $this->patient();
        $appointment = $this->appointmentFor($clinic, $patient);

        $this->actingAs($this->admin())
            ->patch(route('admin.clinics.status', $clinic), [
                'status' => ClinicStatus::Inactive->value,
            ]);

        $this->actingAs($patient)
            ->get(route('patient.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('Development Clinic A')
            ->assertSee('Appointment #'.$appointment->id);
    }

    public function test_ai_recommendation_excludes_an_inactive_clinic(): void
    {
        $this->clinic('Development Clinic A')->update(['status' => ClinicStatus::Inactive]);

        $response = $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->get(route('ai-front-desk'));

        $mock = $response->viewData('frontDeskConfig')['mockRecommendation'];

        $this->assertNotSame('Development Clinic A', $mock['clinic']);
        $this->assertSame('Development Clinic B', $mock['clinic']);
    }

    public function test_status_filters_work(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.clinics', ['status' => 'active']))
            ->assertOk()
            ->assertSee('Development Clinic A')
            ->assertDontSee('Development Clinic C');

        $this->actingAs($this->admin())
            ->get(route('admin.clinics', ['status' => 'inactive']))
            ->assertOk()
            ->assertSee('Development Clinic C')
            ->assertDontSee('Development Clinic A');
    }

    public function test_invalid_status_filter_falls_back_to_all(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.clinics', ['status' => 'not-a-status']))
            ->assertOk()
            ->assertSee('Development Clinic A')
            ->assertSee('Development Clinic C');
    }

    public function test_admin_sidebar_clinics_link_and_active_state(): void
    {
        $clinic = $this->clinic('Development Clinic A');
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.clinics'))
            ->assertOk()
            ->assertSee(route('admin.clinics'), false)
            ->assertSee('aria-current="page"', false);

        $this->actingAs($admin)
            ->get(route('admin.clinics.create'))
            ->assertOk()
            ->assertSee('aria-current="page"', false);

        $this->actingAs($admin)
            ->get(route('admin.clinics.edit', $clinic))
            ->assertOk()
            ->assertSee('aria-current="page"', false);
    }

    /**
     * @return array{name: string, department_id: int, description: string, status: string}
     */
    private function payload(Department $department, string $name): array
    {
        return [
            'name' => $name,
            'department_id' => $department->id,
            'description' => 'Placeholder description.',
            'status' => ClinicStatus::Active->value,
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

    private function clinic(string $name): Clinic
    {
        return Clinic::query()->where('name', $name)->firstOrFail();
    }

    private function patient(): User
    {
        $user = User::factory()->create();

        $user->patient()->create([
            'date_of_birth' => '1992-04-01',
            'sex' => Sex::Female,
            'contact_number' => '09170000001',
        ]);

        return $user->fresh(['patient']);
    }

    private function appointmentFor(Clinic $clinic, ?User $patientUser = null): Appointment
    {
        $patientUser ??= $this->patient();
        $doctor = Doctor::query()->where('clinic_id', $clinic->id)->firstOrFail();
        $schedule = DoctorSchedule::query()
            ->where('doctor_id', $doctor->id)
            ->where('day_of_week', DayOfWeek::Monday)
            ->firstOrFail();

        return Appointment::query()->create([
            'patient_id' => $patientUser->patient->id,
            'doctor_id' => $doctor->id,
            'doctor_schedule_id' => $schedule->id,
            'appointment_date' => '2026-10-12',
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'status' => AppointmentStatus::Confirmed,
        ]);
    }
}
