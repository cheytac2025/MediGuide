<?php

namespace Tests\Feature\Doctor;

use App\Enums\AppointmentStatus;
use App\Enums\RoleName;
use App\Enums\Sex;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\DevelopmentClinicSeeder;
use Database\Seeders\DevelopmentDepartmentSeeder;
use Database\Seeders\DevelopmentDoctorAccountSeeder;
use Database\Seeders\DevelopmentDoctorScheduleSeeder;
use Database\Seeders\DevelopmentDoctorSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DoctorDashboardTest extends TestCase
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

    public function test_guests_cannot_access_the_doctor_dashboard(): void
    {
        $this->get(route('doctor.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_patients_cannot_access_the_doctor_dashboard(): void
    {
        $this->actingAs(User::factory()->role(RoleName::Patient)->create())
            ->get(route('doctor.dashboard'))
            ->assertForbidden();
    }

    public function test_hospital_staff_cannot_access_the_doctor_dashboard(): void
    {
        $this->actingAs(User::factory()->role(RoleName::HospitalStaff)->create())
            ->get(route('doctor.dashboard'))
            ->assertForbidden();
    }

    public function test_it_administrators_cannot_access_the_doctor_dashboard(): void
    {
        $this->actingAs(User::factory()->role(RoleName::ItAdministrator)->create())
            ->get(route('doctor.dashboard'))
            ->assertForbidden();
    }

    public function test_doctors_can_access_the_doctor_dashboard(): void
    {
        $this->actingAs($this->linkedDoctorUser())
            ->get(route('doctor.dashboard'))
            ->assertOk()
            ->assertSee('Good day, Dev!')
            ->assertSee('Today’s Appointments')
            ->assertSee('Upcoming Confirmed')
            ->assertSee('Completed Appointments')
            ->assertSee('Dashboard')
            ->assertSee('Appointments')
            ->assertSee('My Schedule')
            ->assertDontSee('Book Appointment')
            ->assertDontSee('Confirm Appointment')
            ->assertDontSee('Reject Appointment');
    }

    public function test_doctor_login_redirects_to_the_doctor_dashboard(): void
    {
        $user = $this->linkedDoctorUser();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('doctor.dashboard'));
    }

    public function test_unlinked_doctor_role_user_is_redirected_to_unavailable_dashboard(): void
    {
        $user = User::factory()->role(RoleName::Doctor)->create([
            'first_name' => 'Unlinked',
            'last_name' => 'Doctor',
            'name' => 'Unlinked Doctor',
        ]);

        $this->actingAs($user)
            ->get(route('doctor.dashboard'))
            ->assertRedirect(route('dashboard.unavailable'));

        $this->get(route('dashboard.unavailable'))
            ->assertOk()
            ->assertSee('Your dashboard is currently under development.');
    }

    public function test_doctor_sees_only_their_own_confirmed_appointments_on_the_dashboard(): void
    {
        $doctorUser = $this->linkedDoctorUser();
        $otherDoctor = Doctor::query()->where('display_name', 'Test Doctor 2')->firstOrFail();
        $patient = $this->patientUser();

        $mine = $this->place($patient, $this->testDoctorOne(), AppointmentStatus::Confirmed, '2026-10-05', '09:00:00', '09:30:00');
        $theirs = $this->place($patient, $otherDoctor, AppointmentStatus::Confirmed, '2026-10-05', '10:00:00', '10:30:00');

        $response = $this->actingAs($doctorUser)->get(route('doctor.dashboard'));

        $response->assertOk();
        $response->assertSee($patient->user->name);
        $response->assertSee('View Details');
        $response->assertSee(route('doctor.appointments.show', $mine), false);
        $response->assertDontSee(route('doctor.appointments.show', $theirs), false);
    }

    public function test_todays_count_uses_only_this_doctors_confirmed_appointments(): void
    {
        $doctorUser = $this->linkedDoctorUser();
        $otherDoctor = Doctor::query()->where('display_name', 'Test Doctor 2')->firstOrFail();
        $patient = $this->patientUser();

        $this->place($patient, $this->testDoctorOne(), AppointmentStatus::Confirmed, '2026-10-05', '09:00:00', '09:30:00');
        $this->place($patient, $this->testDoctorOne(), AppointmentStatus::Confirmed, '2026-10-05', '09:30:00', '10:00:00');
        $this->place($patient, $otherDoctor, AppointmentStatus::Confirmed, '2026-10-05', '10:00:00', '10:30:00');

        $this->actingAs($doctorUser)
            ->get(route('doctor.dashboard'))
            ->assertOk()
            ->assertSee('data-stat="today">2', false);
    }

    public function test_upcoming_count_uses_only_this_doctors_confirmed_appointments(): void
    {
        $doctorUser = $this->linkedDoctorUser();
        $otherDoctor = Doctor::query()->where('display_name', 'Test Doctor 2')->firstOrFail();
        $patient = $this->patientUser();

        $this->place($patient, $this->testDoctorOne(), AppointmentStatus::Confirmed, '2026-10-06', '09:00:00', '09:30:00');
        $this->place($patient, $otherDoctor, AppointmentStatus::Confirmed, '2026-10-06', '09:00:00', '09:30:00');

        $this->actingAs($doctorUser)
            ->get(route('doctor.dashboard'))
            ->assertOk()
            ->assertSee('data-stat="upcoming">1', false);
    }

    public function test_pending_appointment_is_not_shown_as_active_confirmed_appointment(): void
    {
        $doctorUser = $this->linkedDoctorUser();
        $patient = $this->patientUser();

        $this->place($patient, $this->testDoctorOne(), AppointmentStatus::Pending, '2026-10-05', '09:00:00', '09:30:00');

        $this->actingAs($doctorUser)
            ->get(route('doctor.dashboard'))
            ->assertOk()
            ->assertSee('data-stat="today">0', false)
            ->assertDontSee('View Details');
    }

    public function test_cancelled_appointment_is_not_shown_as_active_appointment(): void
    {
        $doctorUser = $this->linkedDoctorUser();
        $patient = $this->patientUser();

        $this->place($patient, $this->testDoctorOne(), AppointmentStatus::Cancelled, '2026-10-05', '09:00:00', '09:30:00');

        $this->actingAs($doctorUser)
            ->get(route('doctor.dashboard'))
            ->assertOk()
            ->assertSee('data-stat="today">0', false);
    }

    public function test_rejected_appointment_is_not_shown_as_active_appointment(): void
    {
        $doctorUser = $this->linkedDoctorUser();
        $patient = $this->patientUser();

        $this->place($patient, $this->testDoctorOne(), AppointmentStatus::Rejected, '2026-10-05', '09:00:00', '09:30:00');

        $this->actingAs($doctorUser)
            ->get(route('doctor.dashboard'))
            ->assertOk()
            ->assertSee('data-stat="today">0', false);
    }

    public function test_doctor_can_access_their_own_appointment_details(): void
    {
        $doctorUser = $this->linkedDoctorUser();
        $patient = $this->patientUser();
        $appointment = $this->place(
            $patient,
            $this->testDoctorOne(),
            AppointmentStatus::Confirmed,
            '2026-10-05',
            '09:00:00',
            '09:30:00',
            'Routine follow-up',
        );

        $this->actingAs($doctorUser)
            ->get(route('doctor.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('Appointment #'.$appointment->id)
            ->assertSee($patient->user->name)
            ->assertSee('Routine follow-up')
            ->assertDontSee('Confirm Appointment')
            ->assertDontSee('Reject Appointment');
    }

    public function test_doctor_cannot_access_another_doctors_appointment_details(): void
    {
        $doctorUser = $this->linkedDoctorUser();
        $otherDoctor = Doctor::query()->where('display_name', 'Test Doctor 2')->firstOrFail();
        $patient = $this->patientUser();
        $appointment = $this->place($patient, $otherDoctor, AppointmentStatus::Confirmed, '2026-10-05', '09:00:00', '09:30:00');

        $this->actingAs($doctorUser)
            ->get(route('doctor.appointments.show', $appointment))
            ->assertNotFound();
    }

    public function test_doctor_can_access_my_schedule(): void
    {
        $this->actingAs($this->linkedDoctorUser())
            ->get(route('doctor.schedule'))
            ->assertOk()
            ->assertSee('My Schedule')
            ->assertSee('MONDAY')
            ->assertSee('WEDNESDAY');
    }

    public function test_doctor_sees_only_their_own_schedules(): void
    {
        $this->actingAs($this->linkedDoctorUser())
            ->get(route('doctor.schedule'))
            ->assertOk()
            ->assertSee('09:00 AM')
            ->assertDontSee('TUESDAY');
    }

    public function test_doctor_cannot_see_another_doctors_schedules(): void
    {
        $doctorTwoUser = User::factory()->role(RoleName::Doctor)->create([
            'email' => 'doctor-two@example.com',
        ]);

        Doctor::query()->where('display_name', 'Test Doctor 2')->firstOrFail()->update([
            'user_id' => $doctorTwoUser->id,
        ]);

        $this->actingAs($doctorTwoUser)
            ->get(route('doctor.schedule'))
            ->assertOk()
            ->assertSee('TUESDAY')
            ->assertDontSee('MONDAY');
    }

    public function test_doctor_schedule_page_is_read_only(): void
    {
        $this->actingAs($this->linkedDoctorUser())
            ->get(route('doctor.schedule'))
            ->assertOk()
            ->assertSee('read-only')
            ->assertDontSee('Add Schedule')
            ->assertDontSee('Edit')
            ->assertDontSee('Deactivate')
            ->assertDontSee('Delete');
    }

    public function test_upcoming_appointments_preview_shows_empty_state(): void
    {
        $this->actingAs($this->linkedDoctorUser())
            ->get(route('doctor.dashboard'))
            ->assertOk()
            ->assertSee('No upcoming appointments.');
    }

    public function test_development_doctor_account_seeder_works(): void
    {
        $this->seed(DevelopmentDoctorAccountSeeder::class);

        $user = User::query()->where('email', DevelopmentDoctorAccountSeeder::EMAIL)->first();

        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole(RoleName::Doctor));
        $this->assertTrue(Hash::check(DevelopmentDoctorAccountSeeder::PASSWORD, $user->password));
        $this->assertSame(
            Doctor::query()->where('display_name', 'Test Doctor 1')->value('user_id'),
            $user->id,
        );
    }

    public function test_development_doctor_account_seeder_is_safe_to_rerun(): void
    {
        $this->seed(DevelopmentDoctorAccountSeeder::class);
        $this->seed(DevelopmentDoctorAccountSeeder::class);

        $this->assertSame(
            1,
            User::query()->where('email', DevelopmentDoctorAccountSeeder::EMAIL)->count(),
        );
    }

    private function linkedDoctorUser(): User
    {
        $user = User::factory()->role(RoleName::Doctor)->create([
            'first_name' => 'Dev',
            'last_name' => 'Doctor',
            'name' => 'Dev Doctor',
            'email' => 'doctor-dashboard@example.com',
        ]);

        $this->testDoctorOne()->update(['user_id' => $user->id]);

        return $user->fresh(['doctor']);
    }

    private function testDoctorOne(): Doctor
    {
        return Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();
    }

    private function scheduleFor(Doctor $doctor): DoctorSchedule
    {
        return DoctorSchedule::query()
            ->where('doctor_id', $doctor->id)
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->firstOrFail();
    }

    private function place(
        Patient $patient,
        Doctor $doctor,
        AppointmentStatus $status,
        string $date,
        string $start,
        string $end,
        ?string $concern = null,
    ): Appointment {
        return Appointment::query()->create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'doctor_schedule_id' => $this->scheduleFor($doctor)->id,
            'appointment_date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'patient_concern' => $concern,
            'status' => $status,
        ]);
    }

    private function patientUser(string $firstName = 'Ana', string $lastName = 'Reyes'): Patient
    {
        $this->patientSequence++;

        $user = User::factory()->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => $firstName.' '.$lastName,
            'email' => "doctor-dashboard-patient-{$this->patientSequence}@example.com",
        ]);

        return $user->patient()->create([
            'date_of_birth' => '1990-01-15',
            'sex' => Sex::Female,
            'contact_number' => '0917'.str_pad((string) $this->patientSequence, 7, '0', STR_PAD_LEFT),
        ]);
    }
}
