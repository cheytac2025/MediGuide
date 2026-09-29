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
use Database\Seeders\DevelopmentHospitalStaffSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffDashboardTest extends TestCase
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

    public function test_guests_cannot_access_the_staff_dashboard(): void
    {
        $this->get(route('staff.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_patients_cannot_access_the_staff_dashboard(): void
    {
        $this->actingAs(User::factory()->role(RoleName::Patient)->create())
            ->get(route('staff.dashboard'))
            ->assertForbidden();
    }

    public function test_doctors_cannot_access_the_staff_dashboard(): void
    {
        $this->actingAs(User::factory()->role(RoleName::Doctor)->create())
            ->get(route('staff.dashboard'))
            ->assertForbidden();
    }

    public function test_it_administrators_cannot_access_the_staff_dashboard(): void
    {
        $this->actingAs(User::factory()->role(RoleName::ItAdministrator)->create())
            ->get(route('staff.dashboard'))
            ->assertForbidden();
    }

    public function test_hospital_staff_can_access_the_staff_dashboard(): void
    {
        $this->actingAs($this->staffUser())
            ->get(route('staff.dashboard'))
            ->assertOk()
            ->assertSee('Good day, Dev!')
            ->assertSee('Pending Requests')
            ->assertSee('Today’s Appointments')
            ->assertSee('Upcoming Confirmed')
            ->assertSee('Completed Appointments')
            ->assertSee('Recent Appointment Requests')
            ->assertSee('Dashboard')
            ->assertSee('Appointments')
            ->assertDontSee('Book Appointment')
            ->assertDontSee('My Appointments');
    }

    public function test_hospital_staff_login_redirects_to_the_staff_dashboard(): void
    {
        $user = $this->staffUser();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertRedirect(route('staff.dashboard'));
    }

    public function test_patient_login_still_redirects_to_the_patient_dashboard(): void
    {
        $user = User::factory()->role(RoleName::Patient)->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertRedirect(route('patient.dashboard'));
    }

    public function test_pending_requests_count_uses_real_appointment_data(): void
    {
        $patient = $this->patientUser()->patient;
        $this->place($patient, AppointmentStatus::Pending, '2026-10-05', '09:00:00', '09:30:00');
        $this->place($patient, AppointmentStatus::Pending, '2026-10-12', '10:00:00', '10:30:00');
        $this->place($patient, AppointmentStatus::Confirmed, '2026-10-05', '11:00:00', '11:30:00');

        $this->actingAs($this->staffUser())
            ->get(route('staff.dashboard'))
            ->assertOk()
            ->assertSee('data-stat="pending">2', false);
    }

    public function test_todays_appointments_count_includes_todays_confirmed_appointments(): void
    {
        $patient = $this->patientUser()->patient;
        $this->place($patient, AppointmentStatus::Confirmed, '2026-10-05', '09:00:00', '09:30:00');
        $this->place($patient, AppointmentStatus::Confirmed, '2026-10-12', '09:00:00', '09:30:00');

        $this->actingAs($this->staffUser())
            ->get(route('staff.dashboard'))
            ->assertOk()
            ->assertSee('data-stat="today">1', false);
    }

    public function test_todays_appointments_count_excludes_cancelled_and_rejected_appointments(): void
    {
        $patient = $this->patientUser()->patient;
        $this->place($patient, AppointmentStatus::Cancelled, '2026-10-05', '09:00:00', '09:30:00');
        $this->place($patient, AppointmentStatus::Rejected, '2026-10-05', '10:00:00', '10:30:00');
        $this->place($patient, AppointmentStatus::Pending, '2026-10-05', '11:00:00', '11:30:00');

        $this->actingAs($this->staffUser())
            ->get(route('staff.dashboard'))
            ->assertOk()
            ->assertSee('data-stat="today">0', false);
    }

    public function test_upcoming_confirmed_count_uses_future_confirmed_appointments(): void
    {
        $patient = $this->patientUser()->patient;
        $this->place($patient, AppointmentStatus::Confirmed, '2026-10-05', '09:00:00', '09:30:00');
        $this->place($patient, AppointmentStatus::Confirmed, '2026-10-12', '09:00:00', '09:30:00');
        $this->place($patient, AppointmentStatus::Confirmed, '2026-10-19', '10:00:00', '10:30:00');
        $this->place($patient, AppointmentStatus::Pending, '2026-10-26', '09:00:00', '09:30:00');

        $this->actingAs($this->staffUser())
            ->get(route('staff.dashboard'))
            ->assertOk()
            ->assertSee('data-stat="upcoming">2', false);
    }

    public function test_completed_count_uses_completed_appointments(): void
    {
        $patient = $this->patientUser()->patient;
        $this->place($patient, AppointmentStatus::Completed, '2026-09-28', '09:00:00', '09:30:00');
        $this->place($patient, AppointmentStatus::Completed, '2026-09-21', '10:00:00', '10:30:00');
        $this->place($patient, AppointmentStatus::Cancelled, '2026-09-14', '09:00:00', '09:30:00');

        $this->actingAs($this->staffUser())
            ->get(route('staff.dashboard'))
            ->assertOk()
            ->assertSee('data-stat="completed">2', false);
    }

    public function test_recent_pending_requests_display_real_pending_appointment_data(): void
    {
        $user = $this->patientUser('Ana', 'Reyes');
        $this->place($user->patient, AppointmentStatus::Pending, '2026-10-12', '09:00:00', '09:30:00');

        $this->actingAs($this->staffUser())
            ->get(route('staff.dashboard'))
            ->assertOk()
            ->assertSee('Ana Reyes')
            ->assertSee('Development Clinic A')
            ->assertSee('Test Doctor 1')
            ->assertSee('Monday, October 12, 2026')
            ->assertSee('09:00 AM')
            ->assertSee('PENDING');
    }

    public function test_empty_pending_state_displays_correctly(): void
    {
        $this->actingAs($this->staffUser())
            ->get(route('staff.dashboard'))
            ->assertOk()
            ->assertSee('No pending appointment requests.')
            ->assertDontSee('mg-staff-request-card', false);
    }

    public function test_development_hospital_staff_seeder_creates_a_staff_account(): void
    {
        $this->seed(DevelopmentHospitalStaffSeeder::class);

        $user = User::query()->where('email', DevelopmentHospitalStaffSeeder::EMAIL)->first();

        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole(RoleName::HospitalStaff));
        $this->assertTrue(Hash::check(DevelopmentHospitalStaffSeeder::PASSWORD, $user->password));
    }

    public function test_development_hospital_staff_seeder_is_safe_to_rerun(): void
    {
        $this->seed(DevelopmentHospitalStaffSeeder::class);
        $this->seed(DevelopmentHospitalStaffSeeder::class);

        $this->assertSame(
            1,
            User::query()->where('email', DevelopmentHospitalStaffSeeder::EMAIL)->count(),
        );
    }

    private function place(
        Patient $patient,
        AppointmentStatus $status,
        string $date,
        string $start,
        string $end,
    ): Appointment {
        return Appointment::query()->create([
            'patient_id' => $patient->id,
            'doctor_id' => $this->doctor()->id,
            'doctor_schedule_id' => $this->mondaySchedule()->id,
            'appointment_date' => $date,
            'start_time' => $start,
            'end_time' => $end,
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
            'email' => 'staff-dashboard@example.com',
        ]);
    }

    private function patientUser(string $firstName = 'Juan', string $lastName = 'Dela Cruz'): User
    {
        $this->patientSequence++;

        $user = User::factory()->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => $firstName.' '.$lastName,
            'email' => "staff-dashboard-patient-{$this->patientSequence}@example.com",
        ]);

        $user->patient()->create([
            'date_of_birth' => '1990-01-15',
            'sex' => Sex::Male,
            'contact_number' => '0917'.str_pad((string) $this->patientSequence, 7, '0', STR_PAD_LEFT),
        ]);

        return $user->fresh(['patient']);
    }
}
