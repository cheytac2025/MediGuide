<?php

namespace Tests\Feature\Staff;

use App\Enums\AppointmentStatus;
use App\Enums\DayOfWeek;
use App\Enums\RoleName;
use App\Enums\Sex;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\DevelopmentClinicSeeder;
use Database\Seeders\DevelopmentDepartmentSeeder;
use Database\Seeders\DevelopmentDoctorScheduleSeeder;
use Database\Seeders\DevelopmentDoctorSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StaffAppointmentTest extends TestCase
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

    public function test_guests_cannot_access_staff_appointments(): void
    {
        $this->get(route('staff.appointments'))
            ->assertRedirect(route('login'));
    }

    public function test_patients_cannot_access_staff_appointments(): void
    {
        $this->actingAs(User::factory()->role(RoleName::Patient)->create())
            ->get(route('staff.appointments'))
            ->assertForbidden();
    }

    public function test_doctors_cannot_access_staff_appointments(): void
    {
        $this->actingAs(User::factory()->role(RoleName::Doctor)->create())
            ->get(route('staff.appointments'))
            ->assertForbidden();
    }

    public function test_it_administrators_cannot_access_staff_appointments(): void
    {
        $this->actingAs(User::factory()->role(RoleName::ItAdministrator)->create())
            ->get(route('staff.appointments'))
            ->assertForbidden();
    }

    public function test_hospital_staff_can_access_staff_appointments(): void
    {
        $this->actingAs($this->staffUser())
            ->get(route('staff.appointments'))
            ->assertOk()
            ->assertSee('Appointments')
            ->assertSee('View and manage patient appointment requests.')
            ->assertSee('All')
            ->assertSee('PENDING')
            ->assertSee('CONFIRMED');
    }

    public function test_staff_sidebar_appointments_link_works(): void
    {
        $this->actingAs($this->staffUser())
            ->get(route('staff.dashboard'))
            ->assertOk()
            ->assertSee(route('staff.appointments'), false);
    }

    public function test_staff_can_view_real_appointment_records(): void
    {
        $patient = $this->patientUser('Ana', 'Reyes');
        $appointment = $this->place($patient->patient, AppointmentStatus::Pending, '2026-10-12', '09:00:00', '09:30:00');

        $this->actingAs($this->staffUser())
            ->get(route('staff.appointments'))
            ->assertOk()
            ->assertSee('Appointment #'.$appointment->id)
            ->assertSee('Ana Reyes')
            ->assertSee('Development Clinic A')
            ->assertSee('Test Doctor 1')
            ->assertSee('Monday, October 12, 2026')
            ->assertSee('09:00 AM')
            ->assertSee('Pending Review')
            ->assertSee('View Details');
    }

    public function test_pending_filter_shows_pending_appointments(): void
    {
        $patient = $this->patientUser()->patient;
        $pending = $this->place($patient, AppointmentStatus::Pending, '2026-10-12', '09:00:00', '09:30:00');
        $confirmed = $this->place($patient, AppointmentStatus::Confirmed, '2026-10-12', '10:00:00', '10:30:00');

        $this->actingAs($this->staffUser())
            ->get(route('staff.appointments', ['status' => 'pending']))
            ->assertOk()
            ->assertSee('Appointment #'.$pending->id)
            ->assertDontSee('Appointment #'.$confirmed->id);
    }

    public function test_pending_filter_excludes_other_statuses(): void
    {
        $patient = $this->patientUser()->patient;
        $this->place($patient, AppointmentStatus::Confirmed, '2026-10-12', '09:00:00', '09:30:00');
        $this->place($patient, AppointmentStatus::Completed, '2026-09-28', '09:00:00', '09:30:00');
        $this->place($patient, AppointmentStatus::Cancelled, '2026-09-21', '09:00:00', '09:30:00');
        $this->place($patient, AppointmentStatus::Rejected, '2026-09-14', '09:00:00', '09:30:00');

        $this->actingAs($this->staffUser())
            ->get(route('staff.appointments', ['status' => 'pending']))
            ->assertOk()
            ->assertSee('No pending appointments.');
    }

    #[DataProvider('statusFilters')]
    public function test_status_filters_work(AppointmentStatus $status, string $date, string $emptyMessage): void
    {
        $patient = $this->patientUser()->patient;
        $match = $this->place($patient, $status, $date, '09:00:00', '09:30:00');
        $other = $this->place(
            $patient,
            $status === AppointmentStatus::Pending ? AppointmentStatus::Confirmed : AppointmentStatus::Pending,
            '2026-10-19',
            '10:00:00',
            '10:30:00',
        );

        $this->actingAs($this->staffUser())
            ->get(route('staff.appointments', ['status' => $status->value]))
            ->assertOk()
            ->assertSee('Appointment #'.$match->id)
            ->assertDontSee('Appointment #'.$other->id)
            ->assertDontSee($emptyMessage);
    }

    public function test_all_filter_includes_appropriate_records(): void
    {
        $patient = $this->patientUser()->patient;
        $pending = $this->place($patient, AppointmentStatus::Pending, '2026-10-12', '09:00:00', '09:30:00');
        $confirmed = $this->place($patient, AppointmentStatus::Confirmed, '2026-10-12', '10:00:00', '10:30:00');
        $completed = $this->place($patient, AppointmentStatus::Completed, '2026-09-28', '09:00:00', '09:30:00');

        $this->actingAs($this->staffUser())
            ->get(route('staff.appointments'))
            ->assertOk()
            ->assertSee('Appointment #'.$pending->id)
            ->assertSee('Appointment #'.$confirmed->id)
            ->assertSee('Appointment #'.$completed->id);
    }

    public function test_invalid_status_filter_is_handled_safely(): void
    {
        $patient = $this->patientUser()->patient;
        $appointment = $this->place($patient, AppointmentStatus::Pending, '2026-10-12', '09:00:00', '09:30:00');

        $this->actingAs($this->staffUser())
            ->get(route('staff.appointments', ['status' => 'not-a-status']))
            ->assertOk()
            ->assertSee('Appointment #'.$appointment->id)
            ->assertDontSee('No appointments.');
    }

    public function test_staff_can_view_appointment_details(): void
    {
        $patient = $this->patientUser('Ana', 'Reyes');
        $appointment = $this->place(
            $patient->patient,
            AppointmentStatus::Pending,
            '2026-10-12',
            '09:00:00',
            '09:30:00',
            'Persistent headache',
        );

        $this->actingAs($this->staffUser())
            ->get(route('staff.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('Appointment Details')
            ->assertSee('Appointment #'.$appointment->id)
            ->assertSee('Pending Review')
            ->assertSee('Ana Reyes')
            ->assertSee($patient->email)
            ->assertSee($patient->patient->contact_number)
            ->assertSee('Development Clinic A')
            ->assertSee('Development Department A')
            ->assertSee('Test Doctor 1')
            ->assertSee('Development Specialization A')
            ->assertSee('Monday, October 12, 2026')
            ->assertSee('09:00 AM')
            ->assertSee('09:30 AM')
            ->assertSee('Persistent headache')
            ->assertDontSee('Confirm Appointment')
            ->assertDontSee('Reject Appointment')
            ->assertDontSee('Complete Appointment');
    }

    #[DataProvider('nonStaffRoles')]
    public function test_non_staff_roles_cannot_access_appointment_details(RoleName $role): void
    {
        $appointment = $this->place(
            $this->patientUser()->patient,
            AppointmentStatus::Pending,
            '2026-10-12',
            '09:00:00',
            '09:30:00',
        );

        $this->actingAs(User::factory()->role($role)->create())
            ->get(route('staff.appointments.show', $appointment))
            ->assertForbidden();
    }

    public function test_empty_state_displays_correctly(): void
    {
        $this->actingAs($this->staffUser())
            ->get(route('staff.appointments', ['status' => 'pending']))
            ->assertOk()
            ->assertSee('No pending appointments.')
            ->assertDontSee('View Details');
    }

    /**
     * @return array<string, array{0: AppointmentStatus, 1: string, 2: string}>
     */
    public static function statusFilters(): array
    {
        return [
            'confirmed' => [AppointmentStatus::Confirmed, '2026-10-12', 'No confirmed appointments.'],
            'completed' => [AppointmentStatus::Completed, '2026-09-28', 'No completed appointments.'],
            'cancelled' => [AppointmentStatus::Cancelled, '2026-09-21', 'No cancelled appointments.'],
            'rejected' => [AppointmentStatus::Rejected, '2026-09-14', 'No rejected appointments.'],
        ];
    }

    /**
     * @return array<string, array{0: RoleName}>
     */
    public static function nonStaffRoles(): array
    {
        return [
            'patient' => [RoleName::Patient],
            'doctor' => [RoleName::Doctor],
            'it administrator' => [RoleName::ItAdministrator],
        ];
    }

    private function place(
        Patient $patient,
        AppointmentStatus $status,
        string $date,
        string $start,
        string $end,
        ?string $concern = null,
    ): Appointment {
        return Appointment::query()->create([
            'patient_id' => $patient->id,
            'doctor_id' => $this->doctor()->id,
            'doctor_schedule_id' => $this->mondaySchedule()->id,
            'appointment_date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'patient_concern' => $concern,
            'status' => $status,
        ]);
    }

    private function doctor(): Doctor
    {
        return Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();
    }

    private function mondaySchedule(): DoctorSchedule
    {
        return DoctorSchedule::query()
            ->where('doctor_id', $this->doctor()->id)
            ->where('day_of_week', DayOfWeek::Monday)
            ->where('start_time', '09:00:00')
            ->firstOrFail();
    }

    private function staffUser(): User
    {
        return User::factory()->role(RoleName::HospitalStaff)->create([
            'first_name' => 'Dev',
            'last_name' => 'Staff',
            'name' => 'Dev Staff',
            'email' => 'staff-appointments@example.com',
        ]);
    }

    private function patientUser(string $firstName = 'Juan', string $lastName = 'Dela Cruz'): User
    {
        $this->patientSequence++;

        $user = User::factory()->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => $firstName.' '.$lastName,
            'email' => "staff-appt-patient-{$this->patientSequence}@example.com",
        ]);

        $user->patient()->create([
            'date_of_birth' => '1990-01-15',
            'sex' => Sex::Male,
            'contact_number' => '0917'.str_pad((string) $this->patientSequence, 7, '0', STR_PAD_LEFT),
        ]);

        return $user->fresh(['patient']);
    }
}
