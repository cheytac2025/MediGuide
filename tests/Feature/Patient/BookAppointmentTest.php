<?php

namespace Tests\Feature\Patient;

use App\Enums\AppointmentStatus;
use App\Enums\ClinicStatus;
use App\Enums\DayOfWeek;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorStatus;
use App\Enums\RoleName;
use App\Enums\Sex;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\User;
use App\Services\AppointmentBookingService;
use Database\Seeders\DevelopmentClinicSeeder;
use Database\Seeders\DevelopmentDepartmentSeeder;
use Database\Seeders\DevelopmentDoctorScheduleSeeder;
use Database\Seeders\DevelopmentDoctorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BookAppointmentTest extends TestCase
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

    public function test_guests_cannot_access_the_booking_page(): void
    {
        $this->get(route('patient.book-appointment'))
            ->assertRedirect(route('login'));
    }

    public function test_patients_can_access_the_booking_page_from_the_dashboard(): void
    {
        $user = $this->patientUser();

        $this->actingAs($user)
            ->get(route('patient.dashboard'))
            ->assertOk()
            ->assertSee(route('patient.book-appointment'), false);

        $this->actingAs($user)
            ->get(route('patient.book-appointment'))
            ->assertOk()
            ->assertSee('Book Appointment')
            ->assertSee('Select a clinic');
    }

    public function test_non_patients_cannot_access_the_booking_page(): void
    {
        $user = User::factory()->role(RoleName::HospitalStaff)->create();

        $this->actingAs($user)
            ->get(route('patient.book-appointment'))
            ->assertForbidden();
    }

    public function test_active_clinics_are_displayed(): void
    {
        $this->actingAs($this->patientUser())
            ->get(route('patient.book-appointment'))
            ->assertOk()
            ->assertSee('Development Clinic A')
            ->assertSee('Development Clinic B')
            ->assertDontSee('Queen Mary Clinic');
    }

    public function test_inactive_clinics_are_excluded(): void
    {
        Clinic::query()->where('name', 'Development Clinic A')->firstOrFail()->update([
            'status' => ClinicStatus::Inactive,
        ]);

        $this->actingAs($this->patientUser())
            ->get(route('patient.book-appointment'))
            ->assertOk()
            ->assertDontSee('Development Clinic A')
            ->assertSee('Development Clinic B')
            ->assertDontSee('Development Clinic C');
    }

    public function test_clinics_in_an_inactive_department_are_excluded(): void
    {
        Department::query()->where('name', 'Development Department A')->firstOrFail()->update([
            'status' => DepartmentStatus::Inactive,
        ]);

        $this->actingAs($this->patientUser())
            ->get(route('patient.book-appointment'))
            ->assertOk()
            ->assertDontSee('Development Clinic A')
            ->assertSee('Development Clinic B');
    }

    public function test_active_doctors_for_the_selected_clinic_are_displayed(): void
    {
        $clinic = Clinic::query()->where('name', 'Development Clinic A')->firstOrFail();

        $this->actingAs($this->patientUser())
            ->get(route('patient.book-appointment', ['clinic_id' => $clinic->id]))
            ->assertOk()
            ->assertSee('Test Doctor 1')
            ->assertSee('Development Specialization A')
            ->assertDontSee('Test Doctor 2');
    }

    public function test_inactive_doctors_are_excluded(): void
    {
        $clinic = Clinic::query()->where('name', 'Development Clinic A')->firstOrFail();

        Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'display_name' => 'Inactive Development Doctor',
            'specialization' => 'Should Not Appear',
            'status' => DoctorStatus::Inactive,
        ]);

        $this->actingAs($this->patientUser())
            ->get(route('patient.book-appointment', ['clinic_id' => $clinic->id]))
            ->assertOk()
            ->assertSee('Test Doctor 1')
            ->assertDontSee('Inactive Development Doctor')
            ->assertDontSee('Should Not Appear');
    }

    public function test_valid_schedule_dates_are_offered(): void
    {
        $this->actingAs($this->patientUser())
            ->get(route('patient.book-appointment', $this->doctorQuery()))
            ->assertOk()
            ->assertSee('October 5, 2026')
            ->assertSee('October 7, 2026')
            ->assertDontSee('October 6, 2026');
    }

    public function test_past_dates_are_rejected_and_a_same_day_without_future_slots_is_not_offered(): void
    {
        $user = $this->patientUser();

        $this->actingAs($user)
            ->get(route('patient.book-appointment', [
                ...$this->doctorQuery(),
                'appointment_date' => '2026-09-28',
            ]))
            ->assertOk()
            ->assertSee('The selected appointment date is no longer available.')
            ->assertDontSee('09:00 AM');

        Carbon::setTestNow('2026-10-05 12:00:00');

        $this->actingAs($user)
            ->get(route('patient.book-appointment', $this->doctorQuery()))
            ->assertOk()
            ->assertDontSee('October 5, 2026')
            ->assertSee('October 7, 2026');
    }

    public function test_available_time_slots_are_displayed(): void
    {
        $this->actingAs($this->patientUser())
            ->get(route('patient.book-appointment', [
                ...$this->doctorQuery(),
                'appointment_date' => '2026-10-05',
            ]))
            ->assertOk()
            ->assertSee('09:00 AM')
            ->assertSee('09:30 AM')
            ->assertSee('10:00 AM')
            ->assertSee('10:30 AM');
    }

    public function test_already_booked_slots_are_unavailable(): void
    {
        $doctor = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();
        $schedule = $this->mondaySchedule($doctor);

        app(AppointmentBookingService::class)->book(
            $this->patientUser()->patient,
            $doctor,
            $schedule,
            '2026-10-05',
            '09:00',
        );

        $this->actingAs($this->patientUser())
            ->get(route('patient.book-appointment', [
                ...$this->doctorQuery(),
                'appointment_date' => '2026-10-05',
            ]))
            ->assertOk()
            ->assertDontSee('09:00 AM')
            ->assertSee('09:30 AM');
    }

    public function test_a_valid_booking_stores_the_concern_as_pending_and_redirects(): void
    {
        $user = $this->patientUser();
        $payload = $this->bookingPayload('Sore throat since yesterday');

        $this->actingAs($user)
            ->get(route('patient.book-appointment', [
                'clinic_id' => $payload['clinic_id'],
                'doctor_id' => $payload['doctor_id'],
                'appointment_date' => $payload['appointment_date'],
                'start_time' => $payload['start_time'],
            ]))
            ->assertOk()
            ->assertSee($user->name)
            ->assertSee($user->email)
            ->assertSee('09170000001')
            ->assertSee('Briefly describe your concern...');

        $this->actingAs($user)
            ->post(route('patient.book-appointment.review'), $payload)
            ->assertRedirect(route('patient.book-appointment', ['step' => 'review']));

        $this->actingAs($user)
            ->get(route('patient.book-appointment', ['step' => 'review']))
            ->assertOk()
            ->assertSee('Development Clinic A')
            ->assertSee('Test Doctor 1')
            ->assertSee('Development Specialization A')
            ->assertSee('Monday, October 5, 2026')
            ->assertSee('09:00 AM')
            ->assertSee('Sore throat since yesterday')
            ->assertSee('Pending')
            ->assertSee('Your appointment request will be submitted as pending and may require hospital confirmation.')
            ->assertSee('Confirm Appointment')
            ->assertSee('data-mg-confirm-button', false)
            ->assertSee('Submitting...', false);

        $this->actingAs($user)
            ->post(route('patient.book-appointment.store'), $payload)
            ->assertRedirect(route('patient.appointments'))
            ->assertSessionHas('appointment_submitted');

        $this->assertDatabaseHas('appointments', [
            'patient_id' => $user->patient->id,
            'doctor_id' => $payload['doctor_id'],
            'doctor_schedule_id' => $payload['doctor_schedule_id'],
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'patient_concern' => 'Sore throat since yesterday',
            'status' => AppointmentStatus::Pending->value,
        ]);

        $this->actingAs($user)
            ->get(route('patient.appointments'))
            ->assertOk()
            ->assertSee('Your appointment request has been submitted successfully.')
            ->assertSee('Monday, October 5, 2026')
            ->assertSee('09:00 AM')
            ->assertSee('Test Doctor 1')
            ->assertSee('Development Clinic A')
            ->assertSee('Pending');
    }

    public function test_an_invalid_slot_shows_a_friendly_error(): void
    {
        $user = $this->patientUser();

        $this->actingAs($user)
            ->post(route('patient.book-appointment.store'), $this->bookingPayload(start: '09:15'))
            ->assertRedirect()
            ->assertSessionHas('booking_error', 'That time slot is no longer available. Please choose another time.');

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_a_second_submission_does_not_create_a_duplicate_appointment(): void
    {
        $user = $this->patientUser();
        $payload = $this->bookingPayload();

        $this->actingAs($user)
            ->post(route('patient.book-appointment.store'), $payload)
            ->assertRedirect(route('patient.appointments'));

        $this->actingAs($user)
            ->post(route('patient.book-appointment.store'), $payload)
            ->assertRedirect()
            ->assertSessionHas('booking_error', 'That time slot is no longer available. Please choose another time.');

        $this->assertSame(1, Appointment::query()->count());
        $this->assertSame(AppointmentStatus::Pending, Appointment::query()->firstOrFail()->status);
    }

    /**
     * @return array{clinic_id: int, doctor_id: int}
     */
    private function doctorQuery(): array
    {
        $clinic = Clinic::query()->where('name', 'Development Clinic A')->firstOrFail();
        $doctor = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();

        return [
            'clinic_id' => $clinic->id,
            'doctor_id' => $doctor->id,
        ];
    }

    /**
     * @return array{clinic_id: int, doctor_id: int, doctor_schedule_id: int, appointment_date: string, start_time: string, patient_concern: string|null}
     */
    private function bookingPayload(?string $concern = null, string $start = '09:00'): array
    {
        $doctor = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();

        return [
            ...$this->doctorQuery(),
            'doctor_schedule_id' => $this->mondaySchedule($doctor)->id,
            'appointment_date' => '2026-10-05',
            'start_time' => $start,
            'patient_concern' => $concern,
        ];
    }

    private function mondaySchedule(Doctor $doctor): DoctorSchedule
    {
        return DoctorSchedule::query()
            ->where('doctor_id', $doctor->id)
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
            'email' => "book-patient-{$this->patientSequence}@example.com",
        ]);

        $user->patient()->create([
            'date_of_birth' => '1990-01-15',
            'sex' => Sex::Male,
            'contact_number' => '0917'.str_pad((string) $this->patientSequence, 7, '0', STR_PAD_LEFT),
        ]);

        return $user;
    }
}
