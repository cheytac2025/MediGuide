<?php

namespace Tests\Feature\Patient;

use App\Enums\AppointmentStatus;
use App\Enums\ClinicStatus;
use App\Enums\DayOfWeek;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorScheduleStatus;
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
            ->assertSee('October 2026')
            ->assertSee($this->dateLink('2026-10-05'), false)
            ->assertSee($this->dateLink('2026-10-07'), false)
            ->assertDontSee($this->dateLink('2026-10-06'), false)
            ->assertSee('aria-label="Tuesday, October 6, 2026, unavailable"', false)
            ->assertSee('aria-label="Friday, October 2, 2026, unavailable"', false)
            ->assertSee('Select an available date to see open times.');
    }

    public function test_calendar_month_navigation_stays_within_the_booking_horizon(): void
    {
        $this->assertSame(7, config('appointments.booking_window_days'));

        $user = $this->patientUser();

        $this->actingAs($user)
            ->get(route('patient.book-appointment', $this->doctorQuery()))
            ->assertOk()
            ->assertSee('aria-label="Previous month" disabled', false)
            ->assertSee('aria-label="Next month" disabled', false)
            ->assertDontSee('month=2026-11', false)
            ->assertSee($this->dateLink('2026-10-05'), false)
            ->assertSee($this->dateLink('2026-10-12'), false)
            ->assertDontSee($this->dateLink('2026-10-19'), false)
            ->assertSee('aria-label="Monday, October 19, 2026, unavailable"', false);

        $this->actingAs($user)
            ->get(route('patient.book-appointment', [...$this->doctorQuery(), 'month' => '2026-11']))
            ->assertOk()
            ->assertSee('October 2026')
            ->assertDontSee('November 2026');

        $this->actingAs($user)
            ->get(route('patient.book-appointment', [...$this->doctorQuery(), 'month' => '2027-03']))
            ->assertOk()
            ->assertSee('October 2026');
    }

    public function test_booking_window_allows_today_and_day_seven_but_rejects_day_eight(): void
    {
        $user = $this->patientUser();
        $service = app(AppointmentBookingService::class);
        $doctor = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();
        $schedule = $this->mondaySchedule($doctor);

        $this->assertSame(
            '2026-10-12',
            $service->lastBookableDate()->toDateString(),
        );
        $this->assertContains('09:00', $service->getAvailableSlots($doctor, $schedule, '2026-10-05'));
        $this->assertContains('09:00', $service->getAvailableSlots($doctor, $schedule, '2026-10-12'));
        $this->assertSame([], $service->getAvailableSlots($doctor, $schedule, '2026-10-13'));
        $this->assertSame([], $service->getAvailableSlots($doctor, $schedule, '2026-10-19'));

        $this->actingAs($user)
            ->post(route('patient.book-appointment.store'), $this->bookingPayload())
            ->assertRedirect(route('patient.appointments'));

        $this->actingAs($this->patientUser())
            ->post(route('patient.book-appointment.store'), [
                ...$this->bookingPayload(),
                'appointment_date' => '2026-10-12',
            ])
            ->assertRedirect(route('patient.appointments'));

        $this->actingAs($this->patientUser())
            ->post(route('patient.book-appointment.store'), [
                ...$this->bookingPayload(),
                'appointment_date' => '2026-10-19',
            ])
            ->assertRedirect()
            ->assertSessionHas('booking_error', 'The selected appointment date is no longer available.');

        $this->actingAs($this->patientUser())
            ->post(route('patient.book-appointment.review'), [
                ...$this->bookingPayload(),
                'appointment_date' => '2026-10-19',
            ])
            ->assertRedirect()
            ->assertSessionHas('booking_error', 'The selected appointment date is no longer available.');

        $this->assertSame(2, Appointment::query()->count());
        $this->assertSame(0, Appointment::query()->whereDate('appointment_date', '2026-10-19')->count());
    }

    public function test_an_expired_time_today_stays_rejected_inside_the_booking_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'Asia/Manila'));

        $this->actingAs($this->patientUser())
            ->get(route('patient.book-appointment', $this->doctorQuery()))
            ->assertOk()
            ->assertDontSee($this->dateLink('2026-10-05'), false)
            ->assertSee('aria-label="Monday, October 5, 2026, unavailable"', false)
            ->assertSee($this->dateLink('2026-10-12'), false)
            ->assertDontSee($this->dateLink('2026-10-19'), false);

        $this->actingAs($this->patientUser())
            ->post(route('patient.book-appointment.store'), $this->bookingPayload())
            ->assertRedirect()
            ->assertSessionHas('booking_error', AppointmentBookingService::PASSED_SLOT_MESSAGE);

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_booking_window_crosses_the_month_boundary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-26 08:00:00', 'Asia/Manila'));

        $doctor = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();
        $tuesdayDoctor = Doctor::query()->where('display_name', 'Test Doctor 2')->firstOrFail();
        $service = app(AppointmentBookingService::class);

        $this->assertSame('2026-11-02', $service->lastBookableDate()->toDateString());
        $this->assertContains('09:00', $service->getAvailableSlots($doctor, $this->mondaySchedule($doctor), '2026-11-02'));
        $this->assertSame([], $service->getAvailableSlots($doctor, $this->mondaySchedule($doctor), '2026-11-09'));
        $this->assertSame(
            [],
            $service->getAvailableSlots(
                $tuesdayDoctor,
                $this->scheduleOn($tuesdayDoctor, DayOfWeek::Tuesday, '09:00:00'),
                '2026-11-03',
            ),
        );

        $this->actingAs($this->patientUser())
            ->get(route('patient.book-appointment', $this->doctorQuery()))
            ->assertOk()
            ->assertSee('October 2026')
            ->assertSee('month=2026-11', false)
            ->assertSee($this->dateLink('2026-10-26'), false)
            ->assertDontSee($this->dateLink('2026-11-02'), false);

        $this->actingAs($this->patientUser())
            ->get(route('patient.book-appointment', [...$this->doctorQuery(), 'month' => '2026-11']))
            ->assertOk()
            ->assertSee('November 2026')
            ->assertSee('month=2026-10', false)
            ->assertSee('aria-label="Next month" disabled', false)
            ->assertSee($this->dateLink('2026-11-02'), false)
            ->assertDontSee($this->dateLink('2026-11-09'), false)
            ->assertSee('aria-label="Monday, November 9, 2026, unavailable"', false);

        $tuesdayQuery = [
            'clinic_id' => $tuesdayDoctor->clinic_id,
            'doctor_id' => $tuesdayDoctor->id,
        ];

        $this->actingAs($this->patientUser())
            ->get(route('patient.book-appointment', [...$tuesdayQuery, 'month' => '2026-11']))
            ->assertOk()
            ->assertDontSee(e(route('patient.book-appointment', [...$tuesdayQuery, 'appointment_date' => '2026-11-03'])).'"', false)
            ->assertSee('aria-label="Tuesday, November 3, 2026, unavailable"', false);

        $this->actingAs($this->patientUser())
            ->post(route('patient.book-appointment.store'), [
                ...$tuesdayQuery,
                'doctor_schedule_id' => $this->scheduleOn($tuesdayDoctor, DayOfWeek::Tuesday, '09:00:00')->id,
                'appointment_date' => '2026-11-03',
                'start_time' => '09:00',
                'patient_concern' => null,
            ])
            ->assertRedirect()
            ->assertSessionHas('booking_error', 'The selected appointment date is no longer available.');

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_booking_window_crosses_the_year_boundary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-28 08:00:00', 'Asia/Manila'));

        $doctor = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();
        $service = app(AppointmentBookingService::class);
        $monday = $this->mondaySchedule($doctor);
        $wednesday = $this->wednesdaySchedule($doctor);

        $this->assertSame('2027-01-04', $service->lastBookableDate()->toDateString());
        $this->assertContains('09:00', $service->getAvailableSlots($doctor, $monday, '2026-12-28'));
        $this->assertContains('13:00', $service->getAvailableSlots($doctor, $wednesday, '2026-12-30'));
        $this->assertContains('09:00', $service->getAvailableSlots($doctor, $monday, '2027-01-04'));
        $this->assertSame([], $service->getAvailableSlots($doctor, $wednesday, '2027-01-06'));

        $this->actingAs($this->patientUser())
            ->get(route('patient.book-appointment', $this->doctorQuery()))
            ->assertOk()
            ->assertSee('December 2026')
            ->assertSee('month=2027-01', false)
            ->assertSee($this->dateLink('2026-12-28'), false)
            ->assertSee($this->dateLink('2026-12-30'), false);

        $this->actingAs($this->patientUser())
            ->get(route('patient.book-appointment', [...$this->doctorQuery(), 'month' => '2027-01']))
            ->assertOk()
            ->assertSee('January 2027')
            ->assertSee('month=2026-12', false)
            ->assertSee('aria-label="Next month" disabled', false)
            ->assertSee($this->dateLink('2027-01-04'), false)
            ->assertDontSee($this->dateLink('2027-01-06'), false)
            ->assertSee('aria-label="Wednesday, January 6, 2027, unavailable"', false);

        $this->actingAs($this->patientUser())
            ->get(route('patient.book-appointment', [...$this->doctorQuery(), 'month' => '2027-06']))
            ->assertOk()
            ->assertSee('December 2026')
            ->assertDontSee('June 2027');

        $this->actingAs($this->patientUser())
            ->post(route('patient.book-appointment.store'), [
                ...$this->bookingPayload(),
                'doctor_schedule_id' => $monday->id,
                'appointment_date' => '2027-01-04',
            ])
            ->assertRedirect(route('patient.appointments'));

        $this->actingAs($this->patientUser())
            ->post(route('patient.book-appointment.store'), [
                ...$this->bookingPayload(),
                'doctor_schedule_id' => $wednesday->id,
                'appointment_date' => '2027-01-06',
                'start_time' => '13:00',
            ])
            ->assertRedirect()
            ->assertSessionHas('booking_error', 'The selected appointment date is no longer available.');

        $this->assertSame(1, Appointment::query()->count());
        $this->assertSame('2027-01-04', Appointment::query()->firstOrFail()->appointment_date->toDateString());
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
            ->assertDontSee($this->dateLink('2026-10-05'), false)
            ->assertSee('aria-label="Monday, October 5, 2026, unavailable"', false)
            ->assertSee($this->dateLink('2026-10-07'), false);
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
            ->assertDontSee('start_time=09%3A00"', false)
            ->assertSee('aria-label="09:00 AM, unavailable" disabled', false)
            ->assertSee('start_time=09%3A30"', false);
    }

    public function test_a_fully_booked_date_is_disabled_in_the_calendar(): void
    {
        $doctor = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();
        $schedule = $this->mondaySchedule($doctor);
        $booking = app(AppointmentBookingService::class);

        foreach (['09:00', '09:30', '10:00', '10:30', '11:00', '11:30'] as $start) {
            $booking->book($this->patientUser()->patient, $doctor, $schedule, '2026-10-12', $start);
        }

        $user = $this->patientUser();

        $this->actingAs($user)
            ->get(route('patient.book-appointment', $this->doctorQuery()))
            ->assertOk()
            ->assertDontSee($this->dateLink('2026-10-12'), false)
            ->assertSee('aria-label="Monday, October 12, 2026, unavailable"', false);

        $this->actingAs($user)
            ->get(route('patient.book-appointment', [...$this->doctorQuery(), 'appointment_date' => '2026-10-12']))
            ->assertOk()
            ->assertSee('The selected appointment date is no longer available.')
            ->assertDontSee('start_time=', false);
    }

    public function test_hospital_clock_is_asia_manila(): void
    {
        $this->assertSame('Asia/Manila', config('app.timezone'));

        Carbon::setTestNow(Carbon::parse('2026-10-07 05:15:00', 'UTC'));

        $this->assertSame('2026-10-07 13:15', now(config('app.timezone'))->format('Y-m-d H:i'));
    }

    public function test_one_pm_today_is_disabled_when_the_clock_is_past_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-07 05:15:00', 'UTC'));

        $slots = $this->wednesdaySlots();

        $this->assertNotContains('13:00', $slots);
        $this->assertContains('13:30', $slots);

        $this->actingAs($this->patientUser())
            ->get(route('patient.book-appointment', $this->wednesdayQuery()))
            ->assertOk()
            ->assertSee('aria-label="01:00 PM, unavailable" disabled', false)
            ->assertDontSee('start_time=13%3A00"', false)
            ->assertSee('start_time=13%3A30"', false);
    }

    public function test_one_pm_today_is_disabled_when_the_clock_is_exactly_one_pm(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-07 13:00:00', config('app.timezone')));

        $slots = $this->wednesdaySlots();

        $this->assertNotContains('13:00', $slots);
        $this->assertContains('13:30', $slots);

        $this->actingAs($this->patientUser())
            ->get(route('patient.book-appointment', $this->wednesdayQuery()))
            ->assertOk()
            ->assertSee('aria-label="01:00 PM, unavailable" disabled', false)
            ->assertDontSee('start_time=13%3A00"', false);
    }

    public function test_one_pm_today_is_available_at_twelve_thirty(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-07 12:30:00', config('app.timezone')));

        $this->assertContains('13:00', $this->wednesdaySlots());

        $user = $this->patientUser();

        $this->actingAs($user)
            ->get(route('patient.book-appointment', $this->wednesdayQuery()))
            ->assertOk()
            ->assertSee('start_time=13%3A00"', false)
            ->assertDontSee('aria-label="01:00 PM, unavailable"', false);

        $this->actingAs($user)
            ->post(route('patient.book-appointment.store'), $this->wednesdayPayload())
            ->assertRedirect(route('patient.appointments'));

        $this->assertDatabaseHas('appointments', [
            'patient_id' => $user->patient->id,
            'start_time' => '13:00:00',
            'status' => AppointmentStatus::Pending->value,
        ]);
        $this->assertSame('2026-10-07', Appointment::query()->firstOrFail()->appointment_date->toDateString());
    }

    public function test_tomorrows_one_pm_stays_available_after_todays_one_pm_has_passed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-07 13:15:00', config('app.timezone')));

        $this->assertNotContains('13:00', $this->wednesdaySlots());

        $doctor = Doctor::query()->where('display_name', 'Test Doctor 2')->firstOrFail();
        $schedule = DoctorSchedule::query()
            ->where('doctor_id', $doctor->id)
            ->where('day_of_week', DayOfWeek::Thursday)
            ->where('start_time', '13:00:00')
            ->firstOrFail();

        $tomorrow = app(AppointmentBookingService::class)->getAvailableSlots($doctor, $schedule, '2026-10-08');

        $this->assertContains('13:00', $tomorrow);

        $this->actingAs($this->patientUser())
            ->get(route('patient.book-appointment', [
                'clinic_id' => $doctor->clinic_id,
                'doctor_id' => $doctor->id,
                'appointment_date' => '2026-10-08',
            ]))
            ->assertOk()
            ->assertSee('start_time=13%3A00"', false)
            ->assertDontSee('aria-label="01:00 PM, unavailable"', false);
    }

    public function test_a_manually_submitted_past_time_is_rejected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-07 13:15:00', config('app.timezone')));

        $this->actingAs($this->patientUser())
            ->post(route('patient.book-appointment.store'), $this->wednesdayPayload())
            ->assertRedirect()
            ->assertSessionHas('booking_error', AppointmentBookingService::PASSED_SLOT_MESSAGE);

        $this->actingAs($this->patientUser())
            ->post(route('patient.book-appointment.review'), $this->wednesdayPayload())
            ->assertRedirect()
            ->assertSessionHas('booking_error', AppointmentBookingService::PASSED_SLOT_MESSAGE);

        $this->actingAs($this->patientUser())
            ->get(route('patient.book-appointment', [
                ...$this->wednesdayQuery(),
                'start_time' => '13:00',
            ]))
            ->assertOk()
            ->assertSee(AppointmentBookingService::PASSED_SLOT_MESSAGE)
            ->assertDontSee('start_time=13%3A00"', false);

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_slots_at_or_before_the_hospital_clock_are_unavailable_at_every_time_of_day(): void
    {
        $doctor = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();
        $schedule = DoctorSchedule::query()->create([
            'doctor_id' => $doctor->id,
            'day_of_week' => DayOfWeek::Friday,
            'start_time' => '08:00:00',
            'end_time' => '17:30:00',
            'slot_duration' => 30,
            'status' => DoctorScheduleStatus::Active,
        ]);
        $service = app(AppointmentBookingService::class);
        $today = '2026-10-09';
        $nextFriday = '2026-10-16';
        $cases = [
            '08:00' => ['08:00' => false, '08:30' => true, '12:00' => true, '13:00' => true, '14:30' => true, '17:00' => true],
            '08:15' => ['08:00' => false, '08:30' => true, '12:00' => true, '17:00' => true],
            '12:00' => ['08:00' => false, '12:00' => false, '12:30' => true, '13:00' => true, '14:30' => true, '17:00' => true],
            '12:30' => ['12:00' => false, '13:00' => true, '14:30' => true, '17:00' => true],
            '13:00' => ['08:00' => false, '12:00' => false, '13:00' => false, '13:30' => true, '14:30' => true, '17:00' => true],
            '13:15' => ['13:00' => false, '13:30' => true, '14:30' => true, '17:00' => true],
            '14:30' => ['08:00' => false, '12:00' => false, '13:00' => false, '14:30' => false, '15:00' => true, '17:00' => true],
            '17:00' => ['08:00' => false, '12:00' => false, '13:00' => false, '14:30' => false, '17:00' => false],
        ];

        foreach ($cases as $clock => $expected) {
            Carbon::setTestNow(Carbon::parse("{$today} {$clock}:00", 'Asia/Manila'));
            $open = $service->getAvailableSlots($doctor, $schedule, $today);

            foreach ($expected as $start => $available) {
                if ($available) {
                    $this->assertContains($start, $open, "{$start} should stay open at {$clock}.");
                } else {
                    $this->assertNotContains($start, $open, "{$start} should be closed at {$clock}.");
                }
            }

            $future = $service->getAvailableSlots($doctor, $schedule, $nextFriday);

            $this->assertContains('08:00', $future, "Next Friday 8:00 AM should stay open at {$clock}.");
            $this->assertContains('12:00', $future);
            $this->assertContains('13:00', $future);
            $this->assertContains('14:30', $future);
            $this->assertContains('17:00', $future);
        }

        Carbon::setTestNow(Carbon::parse("{$today} 14:30:00", 'Asia/Manila'));

        $this->actingAs($this->patientUser())
            ->get(route('patient.book-appointment', [
                ...$this->doctorQuery(),
                'appointment_date' => $today,
            ]))
            ->assertOk()
            ->assertSee('aria-label="08:00 AM, unavailable" disabled', false)
            ->assertSee('aria-label="12:00 PM, unavailable" disabled', false)
            ->assertSee('aria-label="01:00 PM, unavailable" disabled', false)
            ->assertSee('aria-label="02:30 PM, unavailable" disabled', false)
            ->assertDontSee('start_time=14%3A30"', false)
            ->assertSee('start_time=15%3A00"', false)
            ->assertSee('data-slot-at="2026-10-09T15:00:00+08:00"', false)
            ->assertSee($this->dateLink($nextFriday), false);

        $payload = [
            ...$this->doctorQuery(),
            'doctor_schedule_id' => $schedule->id,
            'appointment_date' => $today,
            'start_time' => '14:30',
            'patient_concern' => null,
        ];

        $this->actingAs($this->patientUser())
            ->post(route('patient.book-appointment.store'), $payload)
            ->assertRedirect()
            ->assertSessionHas('booking_error', AppointmentBookingService::PASSED_SLOT_MESSAGE);

        $this->actingAs($this->patientUser())
            ->post(route('patient.book-appointment.store'), [
                ...$payload,
                'appointment_date' => $nextFriday,
                'start_time' => '08:00',
            ])
            ->assertRedirect(route('patient.appointments'));

        $booked = Appointment::query()->firstOrFail();

        $this->assertSame($nextFriday, $booked->appointment_date->toDateString());
        $this->assertSame('08:00:00', $booked->start_time);
        $this->assertSame(AppointmentStatus::Pending, $booked->status);
        $this->assertSame(0, Appointment::query()->where('start_time', '14:30:00')->count());
    }

    public function test_today_is_disabled_when_every_remaining_slot_has_started(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-09 17:00:00', 'Asia/Manila'));

        $doctor = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();

        DoctorSchedule::query()->create([
            'doctor_id' => $doctor->id,
            'day_of_week' => DayOfWeek::Friday,
            'start_time' => '08:00:00',
            'end_time' => '17:30:00',
            'slot_duration' => 30,
            'status' => DoctorScheduleStatus::Active,
        ]);

        $this->actingAs($this->patientUser())
            ->get(route('patient.book-appointment', $this->doctorQuery()))
            ->assertOk()
            ->assertDontSee($this->dateLink('2026-10-09'), false)
            ->assertSee('aria-label="Friday, October 9, 2026, unavailable"', false)
            ->assertSee($this->dateLink('2026-10-16'), false);

        $this->actingAs($this->patientUser())
            ->get(route('patient.book-appointment', [
                ...$this->doctorQuery(),
                'appointment_date' => '2026-10-09',
                'start_time' => '17:00',
            ]))
            ->assertOk()
            ->assertSee(AppointmentBookingService::PASSED_SLOT_MESSAGE)
            ->assertDontSee('start_time=17%3A00"', false);
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
            'status' => AppointmentStatus::Pending->value,
        ]);
        $this->assertSame('Sore throat since yesterday', Appointment::query()->sole()->patient_concern);
        $this->assertDatabaseMissing('appointments', ['patient_concern' => 'Sore throat since yesterday']);

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

    private function dateLink(string $date): string
    {
        return e(route('patient.book-appointment', [...$this->doctorQuery(), 'appointment_date' => $date])).'"';
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

    /**
     * @return list<string>
     */
    private function wednesdaySlots(): array
    {
        $doctor = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();

        return app(AppointmentBookingService::class)->getAvailableSlots(
            $doctor,
            $this->wednesdaySchedule($doctor),
            '2026-10-07',
        );
    }

    /**
     * @return array{clinic_id: int, doctor_id: int, appointment_date: string}
     */
    private function wednesdayQuery(): array
    {
        return [
            ...$this->doctorQuery(),
            'appointment_date' => '2026-10-07',
        ];
    }

    /**
     * @return array{clinic_id: int, doctor_id: int, doctor_schedule_id: int, appointment_date: string, start_time: string, patient_concern: null}
     */
    private function wednesdayPayload(): array
    {
        $doctor = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();

        return [
            ...$this->doctorQuery(),
            'doctor_schedule_id' => $this->wednesdaySchedule($doctor)->id,
            'appointment_date' => '2026-10-07',
            'start_time' => '13:00',
            'patient_concern' => null,
        ];
    }

    private function wednesdaySchedule(Doctor $doctor): DoctorSchedule
    {
        return DoctorSchedule::query()
            ->where('doctor_id', $doctor->id)
            ->where('day_of_week', DayOfWeek::Wednesday)
            ->where('start_time', '13:00:00')
            ->firstOrFail();
    }

    private function scheduleOn(Doctor $doctor, DayOfWeek $day, string $start): DoctorSchedule
    {
        return DoctorSchedule::query()
            ->where('doctor_id', $doctor->id)
            ->where('day_of_week', $day)
            ->where('start_time', $start)
            ->firstOrFail();
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
