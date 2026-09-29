<?php

namespace Tests\Feature\Patient;

use App\Enums\AppointmentStatus;
use App\Enums\DayOfWeek;
use App\Enums\RoleName;
use App\Enums\Sex;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\User;
use App\Services\AppointmentBookingService;
use Database\Seeders\DevelopmentClinicSeeder;
use Database\Seeders\DevelopmentDepartmentSeeder;
use Database\Seeders\DevelopmentDoctorScheduleSeeder;
use Database\Seeders\DevelopmentDoctorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MyAppointmentsTest extends TestCase
{
    use RefreshDatabase;

    private int $patientSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05 08:00:00');

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

    public function test_guests_cannot_access_my_appointments(): void
    {
        $this->get(route('patient.appointments'))
            ->assertRedirect(route('login'));
    }

    public function test_patients_can_access_my_appointments(): void
    {
        $this->actingAs($this->patientUser())
            ->get(route('patient.appointments'))
            ->assertOk()
            ->assertSee('My Appointments')
            ->assertSee('View and manage your appointment requests.')
            ->assertSee('Book New Appointment');
    }

    public function test_non_patients_cannot_access_my_appointments(): void
    {
        $user = User::factory()->role(RoleName::HospitalStaff)->create();

        $this->actingAs($user)
            ->get(route('patient.appointments'))
            ->assertForbidden();
    }

    public function test_a_patient_sees_only_their_own_appointments(): void
    {
        $owner = $this->patientUser();
        $other = $this->patientUser();

        $this->place($owner->patient, AppointmentStatus::Pending, '2026-10-05', '09:00:00', '09:30:00', 'Only patient A');
        $this->place($other->patient, AppointmentStatus::Pending, '2026-10-05', '10:00:00', '10:30:00', 'Only patient B');

        $this->actingAs($owner)
            ->get(route('patient.appointments'))
            ->assertOk()
            ->assertSee('Only patient A')
            ->assertDontSee('Only patient B');

        $this->actingAs($other)
            ->get(route('patient.appointments'))
            ->assertOk()
            ->assertSee('Only patient B')
            ->assertDontSee('Only patient A');
    }

    public function test_a_patient_cannot_view_another_patients_appointment(): void
    {
        $owner = $this->patientUser();
        $other = $this->patientUser();
        $appointment = $this->place($owner->patient, AppointmentStatus::Pending, '2026-10-05', '09:00:00', '09:30:00', 'Private concern');

        $this->actingAs($other)
            ->get(route('patient.appointments.show', $appointment))
            ->assertNotFound();
    }

    public function test_a_patient_can_view_their_own_appointment_details(): void
    {
        $user = $this->patientUser();
        $appointment = $this->place($user->patient, AppointmentStatus::Pending, '2026-10-05', '09:00:00', '09:30:00', 'Follow-up concern');

        $this->actingAs($user)
            ->get(route('patient.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('Appointment #'.$appointment->id)
            ->assertSee('Pending hospital confirmation')
            ->assertSee('Development Clinic A')
            ->assertSee('Development Department A')
            ->assertSee('Test Doctor 1')
            ->assertSee('Development Specialization A')
            ->assertSee('Monday, October 5, 2026')
            ->assertSee('09:00 AM')
            ->assertSee('09:30 AM')
            ->assertSee('Follow-up concern')
            ->assertSee('Are you sure you want to cancel this appointment?')
            ->assertSee('Keep Appointment')
            ->assertSee('Cancel Appointment');
    }

    public function test_pending_appointments_appear_under_upcoming(): void
    {
        $user = $this->patientUser();
        $this->place($user->patient, AppointmentStatus::Pending, '2026-10-05', '09:00:00', '09:30:00', 'Pending visit');

        $this->actingAs($user)
            ->get(route('patient.appointments'))
            ->assertOk()
            ->assertSeeInOrder(['Upcoming', 'Pending hospital confirmation', 'Pending visit', 'History']);
    }

    public function test_confirmed_appointments_appear_under_upcoming(): void
    {
        $user = $this->patientUser();
        $this->place($user->patient, AppointmentStatus::Confirmed, '2026-10-05', '10:00:00', '10:30:00', 'Confirmed visit');

        $this->actingAs($user)
            ->get(route('patient.appointments'))
            ->assertOk()
            ->assertSeeInOrder(['Upcoming', 'Confirmed visit', 'History'])
            ->assertSee('Confirmed');
    }

    public function test_completed_appointments_appear_in_history(): void
    {
        $user = $this->patientUser();
        $this->place($user->patient, AppointmentStatus::Completed, '2026-09-28', '09:00:00', '09:30:00', 'Completed visit');

        $this->actingAs($user)
            ->get(route('patient.appointments'))
            ->assertOk()
            ->assertSeeInOrder(['History', 'Completed', 'Completed visit']);
    }

    public function test_cancelled_appointments_appear_in_history(): void
    {
        $user = $this->patientUser();
        $this->place($user->patient, AppointmentStatus::Cancelled, '2026-09-28', '09:00:00', '09:30:00', 'Cancelled visit');

        $this->actingAs($user)
            ->get(route('patient.appointments'))
            ->assertOk()
            ->assertSeeInOrder(['History', 'Cancelled', 'Cancelled visit']);
    }

    public function test_rejected_appointments_appear_in_history(): void
    {
        $user = $this->patientUser();
        $this->place($user->patient, AppointmentStatus::Rejected, '2026-09-28', '09:00:00', '09:30:00', 'Rejected visit');

        $this->actingAs($user)
            ->get(route('patient.appointments'))
            ->assertOk()
            ->assertSeeInOrder(['History', 'Rejected', 'Rejected visit']);
    }

    public function test_upcoming_appointments_are_sorted_by_date_and_time(): void
    {
        $user = $this->patientUser();
        $this->place($user->patient, AppointmentStatus::Confirmed, '2026-10-12', '09:00:00', '09:30:00', 'Sort next week');
        $this->place($user->patient, AppointmentStatus::Pending, '2026-10-05', '11:00:00', '11:30:00', 'Sort late morning');
        $this->place($user->patient, AppointmentStatus::Pending, '2026-10-05', '09:00:00', '09:30:00', 'Sort early morning');

        $this->actingAs($user)
            ->get(route('patient.appointments'))
            ->assertOk()
            ->assertSeeInOrder(['Sort early morning', 'Sort late morning', 'Sort next week']);
    }

    public function test_history_appointments_are_sorted_by_date_and_time_descending(): void
    {
        $user = $this->patientUser();
        $this->place($user->patient, AppointmentStatus::Completed, '2026-10-01', '09:00:00', '09:30:00', 'History oldest');
        $this->place($user->patient, AppointmentStatus::Rejected, '2026-10-03', '09:00:00', '09:30:00', 'History middle early');
        $this->place($user->patient, AppointmentStatus::Rejected, '2026-10-03', '15:00:00', '15:30:00', 'History middle late');
        $this->place($user->patient, AppointmentStatus::Cancelled, '2026-10-08', '09:00:00', '09:30:00', 'History newest');

        $this->actingAs($user)
            ->get(route('patient.appointments'))
            ->assertOk()
            ->assertSeeInOrder([
                'History newest',
                'History middle late',
                'History middle early',
                'History oldest',
            ]);
    }

    public function test_a_pending_appointment_can_be_cancelled(): void
    {
        $user = $this->patientUser();
        $appointment = $this->place($user->patient, AppointmentStatus::Pending, '2026-10-05', '09:00:00', '09:30:00');

        $this->actingAs($user)
            ->patch(route('patient.appointments.cancel', $appointment))
            ->assertRedirect(route('patient.appointments.show', $appointment));

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->fresh()->status);
    }

    public function test_a_confirmed_appointment_can_be_cancelled(): void
    {
        $user = $this->patientUser();
        $appointment = $this->place($user->patient, AppointmentStatus::Confirmed, '2026-10-05', '10:00:00', '10:30:00');

        $this->actingAs($user)
            ->patch(route('patient.appointments.cancel', $appointment))
            ->assertRedirect(route('patient.appointments.show', $appointment));

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->fresh()->status);
    }

    public function test_a_completed_appointment_cannot_be_cancelled(): void
    {
        $user = $this->patientUser();
        $appointment = $this->place($user->patient, AppointmentStatus::Completed, '2026-09-28', '09:00:00', '09:30:00');

        $this->actingAs($user)
            ->patch(route('patient.appointments.cancel', $appointment))
            ->assertRedirect(route('patient.appointments.show', $appointment))
            ->assertSessionHas('appointment_error', 'This appointment can no longer be cancelled.');

        $this->assertSame(AppointmentStatus::Completed, $appointment->fresh()->status);
    }

    public function test_a_cancelled_appointment_cannot_be_cancelled_again(): void
    {
        $user = $this->patientUser();
        $appointment = $this->place($user->patient, AppointmentStatus::Cancelled, '2026-09-28', '09:00:00', '09:30:00');

        $this->actingAs($user)
            ->patch(route('patient.appointments.cancel', $appointment))
            ->assertSessionHas('appointment_error');

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->fresh()->status);
        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_a_rejected_appointment_cannot_be_cancelled(): void
    {
        $user = $this->patientUser();
        $appointment = $this->place($user->patient, AppointmentStatus::Rejected, '2026-09-28', '09:00:00', '09:30:00');

        $this->actingAs($user)
            ->patch(route('patient.appointments.cancel', $appointment))
            ->assertSessionHas('appointment_error');

        $this->assertSame(AppointmentStatus::Rejected, $appointment->fresh()->status);
    }

    public function test_a_patient_cannot_cancel_another_patients_appointment(): void
    {
        $owner = $this->patientUser();
        $other = $this->patientUser();
        $appointment = $this->place($owner->patient, AppointmentStatus::Pending, '2026-10-05', '09:00:00', '09:30:00');

        $this->actingAs($other)
            ->patch(route('patient.appointments.cancel', $appointment))
            ->assertNotFound();

        $this->assertSame(AppointmentStatus::Pending, $appointment->fresh()->status);
    }

    public function test_cancellation_keeps_the_appointment_and_marks_it_cancelled(): void
    {
        $user = $this->patientUser();
        $appointment = $this->place($user->patient, AppointmentStatus::Pending, '2026-10-05', '11:00:00', '11:30:00', 'Keep this row');

        $this->actingAs($user)
            ->patch(route('patient.appointments.cancel', $appointment))
            ->assertRedirect(route('patient.appointments.show', $appointment));

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'patient_id' => $user->patient->id,
            'patient_concern' => 'Keep this row',
            'status' => AppointmentStatus::Cancelled->value,
        ]);
        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_cancelling_an_appointment_releases_the_doctor_slot(): void
    {
        $user = $this->patientUser();
        $doctor = $this->doctor();
        $schedule = $this->mondaySchedule();
        $appointment = $this->place($user->patient, AppointmentStatus::Pending, '2026-10-05', '09:00:00', '09:30:00');
        $booking = app(AppointmentBookingService::class);

        $this->assertNotContains('09:00', $booking->getAvailableSlots($doctor, $schedule, '2026-10-05'));

        $this->actingAs($user)
            ->patch(route('patient.appointments.cancel', $appointment))
            ->assertRedirect(route('patient.appointments.show', $appointment));

        $this->assertContains('09:00', $booking->getAvailableSlots($doctor, $schedule, '2026-10-05'));

        $other = $this->patientUser();
        $rebooked = $booking->book($other->patient, $doctor, $schedule, '2026-10-05', '09:00');

        $this->assertSame(AppointmentStatus::Pending, $rebooked->status);
        $this->assertSame('09:00:00', $rebooked->start_time);
    }

    public function test_a_successful_booking_appears_in_my_appointments(): void
    {
        $user = $this->patientUser();
        $doctor = $this->doctor();
        $schedule = $this->mondaySchedule();

        app(AppointmentBookingService::class)->book(
            $user->patient,
            $doctor,
            $schedule,
            '2026-10-05',
            '09:30',
            'Booked through the service',
        );

        $this->actingAs($user)
            ->withSession([
                'appointment_submitted' => [
                    'message' => 'Your appointment request has been submitted successfully.',
                ],
            ])
            ->get(route('patient.appointments'))
            ->assertOk()
            ->assertSee('Your appointment request has been submitted successfully.')
            ->assertSeeInOrder([
                'Upcoming',
                'Development Clinic A',
                'Test Doctor 1',
                'Pending hospital confirmation',
                'Booked through the service',
            ]);
    }

    public function test_an_empty_state_is_shown_when_the_patient_has_no_appointments(): void
    {
        $this->actingAs($this->patientUser())
            ->get(route('patient.appointments'))
            ->assertOk()
            ->assertSee('No appointments yet.')
            ->assertSee('Book an Appointment')
            ->assertDontSee('Upcoming')
            ->assertDontSee('History');
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

    private function patientUser(): User
    {
        $this->patientSequence++;

        $user = User::factory()->create([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'name' => 'Juan Dela Cruz',
            'email' => "appointments-patient-{$this->patientSequence}@example.com",
        ]);

        $user->patient()->create([
            'date_of_birth' => '1990-01-15',
            'sex' => Sex::Male,
            'contact_number' => '0918'.str_pad((string) $this->patientSequence, 7, '0', STR_PAD_LEFT),
        ]);

        return $user->fresh(['patient']);
    }
}
