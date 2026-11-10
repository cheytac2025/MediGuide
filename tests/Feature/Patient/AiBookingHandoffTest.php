<?php

namespace Tests\Feature\Patient;

use App\Enums\AppointmentStatus;
use App\Enums\ClinicStatus;
use App\Enums\DayOfWeek;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorScheduleStatus;
use App\Enums\DoctorStatus;
use App\Enums\HospitalDataSource;
use App\Enums\RoleName;
use App\Enums\Sex;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\User;
use App\Services\AiHospitalContextService;
use App\Services\AppointmentBookingService;
use App\Support\AiDisclaimer;
use App\Support\BookingIntent;
use Database\Seeders\DevelopmentClinicSeeder;
use Database\Seeders\DevelopmentDepartmentSeeder;
use Database\Seeders\DevelopmentDoctorAccountSeeder;
use Database\Seeders\DevelopmentDoctorScheduleSeeder;
use Database\Seeders\DevelopmentDoctorSeeder;
use Database\Seeders\DevelopmentHospitalStaffSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiBookingHandoffTest extends TestCase
{
    use RefreshDatabase;

    private int $patientSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_ai_recommendation_resolves_to_an_active_development_clinic(): void
    {
        $clinic = Clinic::query()->where('name', 'Development Clinic A')->firstOrFail();
        $this->fakeGuidanceRecommendation($clinic);

        $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->postJson(route('ai-front-desk.guidance'), [
                'message' => "I've had a headache since yesterday.",
            ])
            ->assertOk()
            ->assertJsonPath('type', 'recommendation')
            ->assertJsonPath('clinic.id', $clinic->id)
            ->assertJsonPath('clinic.name', 'Development Clinic A')
            ->assertJsonPath('data_source', 'development');

        $this->assertSame(ClinicStatus::Active, $clinic->status);
    }

    public function test_recommended_clinic_belongs_to_an_active_department(): void
    {
        $clinic = Clinic::query()->with('department')->where('name', 'Development Clinic A')->firstOrFail();
        $this->fakeGuidanceRecommendation($clinic);

        $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->postJson(route('ai-front-desk.guidance'), [
                'message' => "I've had a headache since yesterday.",
            ])
            ->assertOk()
            ->assertJsonPath('clinic.department', 'Development Department A');

        $this->assertSame(DepartmentStatus::Active, $clinic->department->status);
    }

    public function test_view_available_doctors_requires_disclaimer_acknowledgement(): void
    {
        $clinic = $this->clinicA();

        $this->get(route('ai-front-desk.clinics.doctors', $clinic))
            ->assertRedirect(route('ai-disclaimer'));
    }

    public function test_guests_can_view_available_doctors_after_disclaimer(): void
    {
        $clinic = $this->clinicA();

        $response = $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->getJson(route('ai-front-desk.clinics.doctors', $clinic));

        $response->assertOk()
            ->assertJsonPath('clinic.name', 'Development Clinic A')
            ->assertJsonPath('is_authenticated_patient', false)
            ->assertJsonFragment([
                'display_name' => 'Test Doctor 1',
                'specialization' => DevelopmentDoctorSeeder::SPECIALIZATION_A,
                'clinic' => 'Development Clinic A',
                'bookable' => true,
            ]);
    }

    public function test_active_doctors_from_the_recommended_clinic_are_displayed(): void
    {
        $clinic = $this->clinicA();

        $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->getJson(route('ai-front-desk.clinics.doctors', $clinic))
            ->assertOk()
            ->assertJsonCount(1, 'doctors')
            ->assertJsonPath('doctors.0.display_name', 'Test Doctor 1');
    }

    public function test_inactive_doctors_are_excluded(): void
    {
        $clinic = $this->clinicA();

        Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'display_name' => 'Inactive AI Doctor',
            'specialization' => 'Should Not Appear',
            'status' => DoctorStatus::Inactive,
        ]);

        $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->getJson(route('ai-front-desk.clinics.doctors', $clinic))
            ->assertOk()
            ->assertJsonMissing(['display_name' => 'Inactive AI Doctor'])
            ->assertJsonMissing(['specialization' => 'Should Not Appear']);
    }

    public function test_doctors_from_another_clinic_are_excluded(): void
    {
        $clinic = $this->clinicA();

        $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->getJson(route('ai-front-desk.clinics.doctors', $clinic))
            ->assertOk()
            ->assertJsonMissing(['display_name' => 'Test Doctor 2'])
            ->assertJsonFragment(['display_name' => 'Test Doctor 1']);
    }

    public function test_doctors_under_inactive_clinic_or_department_are_excluded(): void
    {
        $clinic = $this->clinicA();

        $clinic->update(['status' => ClinicStatus::Inactive]);

        $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->getJson(route('ai-front-desk.clinics.doctors', $clinic))
            ->assertNotFound();

        $clinic->update(['status' => ClinicStatus::Active]);
        Department::query()->where('name', 'Development Department A')->firstOrFail()->update([
            'status' => DepartmentStatus::Inactive,
        ]);

        $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->getJson(route('ai-front-desk.clinics.doctors', $clinic->fresh()))
            ->assertNotFound();
    }

    public function test_doctor_without_active_schedule_cannot_be_booked(): void
    {
        $clinic = $this->clinicA();

        $doctor = Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'display_name' => 'Unscheduled Development Doctor',
            'specialization' => DevelopmentDoctorSeeder::SPECIALIZATION_A,
            'status' => DoctorStatus::Active,
        ]);

        $response = $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->getJson(route('ai-front-desk.clinics.doctors', $clinic));

        $response->assertOk()
            ->assertJsonFragment([
                'display_name' => 'Unscheduled Development Doctor',
                'bookable' => false,
                'availability' => 'No available schedule',
            ]);

        $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->post(route('ai-front-desk.booking-intent'), [
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
            ])
            ->assertRedirect(route('ai-front-desk'));

        $this->assertNull(session(BookingIntent::SESSION_KEY));
    }

    public function test_authenticated_patient_booking_intent_redirects_to_booking_with_ids(): void
    {
        $user = $this->patientUser();
        $clinic = $this->clinicA();
        $doctor = $this->doctor1();

        $this->actingAs($user)
            ->withSession([AiDisclaimer::SESSION_KEY => true])
            ->post(route('ai-front-desk.booking-intent'), [
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
            ])
            ->assertRedirect(route('patient.book-appointment', [
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
            ]));

        $this->actingAs($user)
            ->get(route('patient.book-appointment', [
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
            ]))
            ->assertOk()
            ->assertSee('Development Clinic A')
            ->assertSee('Test Doctor 1');
    }

    public function test_mocked_recommendation_books_the_suggested_doctor_for_the_same_patient(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 08:00:00', 'Asia/Manila'));

        try {
            $user = $this->patientUser();
            $clinic = $this->clinicA();
            $doctor = $this->doctor1();
            $booking = app(AppointmentBookingService::class);
            $slot = $booking->nextAvailableSlot($doctor);

            $this->assertNotNull($slot);

            $this->fakeGuidanceServiceRecommendation(DevelopmentDoctorSeeder::SPECIALIZATION_A);

            $guidance = $this->actingAs($user)
                ->withSession([AiDisclaimer::SESSION_KEY => true])
                ->postJson(route('ai-front-desk.guidance'), [
                    'message' => "I've had a headache since yesterday.",
                ]);

            $guidance->assertOk()
                ->assertJsonPath('type', 'recommendation')
                ->assertJsonPath('specialization', DevelopmentDoctorSeeder::SPECIALIZATION_A)
                ->assertJsonPath('doctors.0.id', $doctor->id)
                ->assertJsonPath('doctors.0.clinic_id', $clinic->id)
                ->assertJsonPath('doctors.0.bookable', true)
                ->assertJsonPath('doctors.0.next_available', $booking->formatSlotLabel($slot['date'], $slot['time']))
                ->assertJsonPath('book_appointment_url', route('patient.book-appointment'))
                ->assertJsonPath('is_authenticated_patient', true)
                ->assertJsonMissingPath('doctor_id');

            Http::assertSentCount(1);

            $schedule = DoctorSchedule::query()
                ->where('doctor_id', $doctor->id)
                ->where('day_of_week', strtolower(Carbon::parse($slot['date'])->format('l')))
                ->where('status', DoctorScheduleStatus::Active)
                ->firstOrFail();

            $selection = [
                'clinic_id' => $guidance->json('doctors.0.clinic_id'),
                'doctor_id' => $guidance->json('doctors.0.id'),
                'appointment_date' => $slot['date'],
                'start_time' => $slot['time'],
            ];

            $this->actingAs($user)
                ->get(route('patient.book-appointment', $selection))
                ->assertOk()
                ->assertSee('Development Clinic A')
                ->assertSee('Test Doctor 1')
                ->assertSee('value="'.$schedule->id.'"', false)
                ->assertSee($slot['time'], false);

            $payload = [
                ...$selection,
                'doctor_schedule_id' => $schedule->id,
                'patient_concern' => 'Synthetic navigation follow-up.',
            ];

            $this->actingAs($user)
                ->post(route('patient.book-appointment.review'), $payload)
                ->assertRedirect(route('patient.book-appointment', ['step' => 'review']));

            $this->actingAs($user)
                ->get(route('patient.book-appointment', ['step' => 'review']))
                ->assertOk()
                ->assertSee('Development Clinic A')
                ->assertSee('Test Doctor 1')
                ->assertSee('Synthetic navigation follow-up.')
                ->assertSee('Confirm Appointment');

            $this->actingAs($user)
                ->post(route('patient.book-appointment.store'), $payload)
                ->assertRedirect(route('patient.appointments'));

            $appointment = Appointment::query()->sole();

            $this->assertSame($user->patient->id, $appointment->patient_id);
            $this->assertSame($doctor->id, $appointment->doctor_id);
            $this->assertSame($schedule->id, $appointment->doctor_schedule_id);
            $this->assertSame($slot['date'], $appointment->appointment_date->toDateString());
            $this->assertSame($slot['time'].':00', $appointment->start_time);
            $this->assertSame(AppointmentStatus::Pending, $appointment->status);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_guest_mocked_recommendation_survives_login_booking_staff_confirm_and_doctor_visibility(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-10 08:00:00', 'Asia/Manila'));

        try {
            $this->seed(DevelopmentDoctorAccountSeeder::class);
            $this->seed(DevelopmentHospitalStaffSeeder::class);

            $patient = $this->patientUser();
            $clinic = $this->clinicA();
            $doctor = $this->doctor1();
            $booking = app(AppointmentBookingService::class);
            $slot = $booking->nextAvailableSlot($doctor);

            $this->assertNotNull($slot);

            $this->fakeGuidanceServiceRecommendation(DevelopmentDoctorSeeder::SPECIALIZATION_A);

            $guidance = $this->withSession([AiDisclaimer::SESSION_KEY => true])
                ->postJson(route('ai-front-desk.guidance'), [
                    'message' => 'This is a synthetic demonstration. I have had a mild headache since yesterday.',
                ]);

            $guidance->assertOk()
                ->assertJsonPath('type', 'recommendation')
                ->assertJsonPath('specialization', DevelopmentDoctorSeeder::SPECIALIZATION_A)
                ->assertJsonPath('doctors.0.id', $doctor->id)
                ->assertJsonPath('doctors.0.clinic_id', $clinic->id)
                ->assertJsonPath('doctors.0.bookable', true)
                ->assertJsonPath('is_authenticated_patient', false);

            $this->withSession([AiDisclaimer::SESSION_KEY => true])
                ->post(route('ai-front-desk.booking-intent'), [
                    'clinic_id' => $guidance->json('doctors.0.clinic_id'),
                    'doctor_id' => $guidance->json('doctors.0.id'),
                ])
                ->assertRedirect(route('login'))
                ->assertSessionHas(BookingIntent::SESSION_KEY, [
                    'clinic_id' => $clinic->id,
                    'doctor_id' => $doctor->id,
                ]);

            $this->post(route('login.store'), [
                'email' => $patient->email,
                'password' => 'password',
            ])->assertRedirect(route('patient.book-appointment', [
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
            ]));

            $schedule = DoctorSchedule::query()
                ->where('doctor_id', $doctor->id)
                ->where('day_of_week', strtolower(Carbon::parse($slot['date'])->format('l')))
                ->where('status', DoctorScheduleStatus::Active)
                ->firstOrFail();

            $selection = [
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
                'appointment_date' => $slot['date'],
                'start_time' => $slot['time'],
            ];

            $this->actingAs($patient)
                ->get(route('patient.book-appointment', $selection))
                ->assertOk()
                ->assertSee('Development Clinic A')
                ->assertSee('Test Doctor 1');

            $payload = [
                ...$selection,
                'doctor_schedule_id' => $schedule->id,
                'patient_concern' => 'Synthetic browser verification follow-up.',
            ];

            $this->actingAs($patient)
                ->post(route('patient.book-appointment.review'), $payload)
                ->assertRedirect(route('patient.book-appointment', ['step' => 'review']));

            $this->actingAs($patient)
                ->post(route('patient.book-appointment.store'), $payload)
                ->assertRedirect(route('patient.appointments'));

            $appointment = Appointment::query()->sole();
            $this->assertSame($patient->patient->id, $appointment->patient_id);
            $this->assertSame($doctor->id, $appointment->doctor_id);
            $this->assertSame(AppointmentStatus::Pending, $appointment->status);

            $staff = User::query()->where('email', DevelopmentHospitalStaffSeeder::EMAIL)->firstOrFail();

            $this->actingAs($staff)
                ->patch(route('staff.appointments.confirm', $appointment))
                ->assertRedirect(route('staff.appointments.show', $appointment));

            $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);

            $doctorUser = User::query()->where('email', DevelopmentDoctorAccountSeeder::EMAIL)->firstOrFail();

            $this->actingAs($doctorUser)
                ->get(route('doctor.appointments'))
                ->assertOk()
                ->assertSee('Test Doctor 1')
                ->assertSee(route('doctor.appointments.show', $appointment), false);

            $this->actingAs($doctorUser)
                ->get(route('doctor.appointments.show', $appointment))
                ->assertOk()
                ->assertSee('CONFIRMED')
                ->assertSee('Development Clinic A')
                ->assertSee('Patient User'.$this->patientSequence);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_guest_book_appointment_stores_intent_and_redirects_to_login(): void
    {
        $clinic = $this->clinicA();
        $doctor = $this->doctor1();

        $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->post(route('ai-front-desk.booking-intent'), [
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHas(BookingIntent::SESSION_KEY, [
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
            ]);
    }

    public function test_successful_patient_login_with_booking_intent_redirects_to_booking(): void
    {
        $user = $this->patientUser();
        $clinic = $this->clinicA();
        $doctor = $this->doctor1();

        $response = $this->withSession([
            AiDisclaimer::SESSION_KEY => true,
            BookingIntent::SESSION_KEY => [
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
            ],
        ])->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('patient.book-appointment', [
            'clinic_id' => $clinic->id,
            'doctor_id' => $doctor->id,
        ]));
        $response->assertSessionMissing(BookingIntent::SESSION_KEY);
    }

    public function test_clinic_and_doctor_are_preselected_after_login_handoff(): void
    {
        $user = $this->patientUser();
        $clinic = $this->clinicA();
        $doctor = $this->doctor1();

        $this->withSession([
            BookingIntent::SESSION_KEY => [
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
            ],
        ])->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('patient.book-appointment', [
            'clinic_id' => $clinic->id,
            'doctor_id' => $doctor->id,
        ]));

        $this->actingAs($user)
            ->get(route('patient.book-appointment', [
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
            ]))
            ->assertOk()
            ->assertSee('Development Clinic A')
            ->assertSee('Test Doctor 1')
            ->assertSee('Select a date');
    }

    public function test_invalid_booking_intent_is_safely_ignored(): void
    {
        $user = $this->patientUser();

        $response = $this->withSession([
            BookingIntent::SESSION_KEY => [
                'clinic_id' => 999999,
                'doctor_id' => 999999,
            ],
        ])->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('patient.dashboard'));
        $response->assertSessionMissing(BookingIntent::SESSION_KEY);
    }

    public function test_doctor_that_does_not_belong_to_clinic_is_ignored(): void
    {
        $user = $this->patientUser();
        $clinicA = $this->clinicA();
        $doctorB = Doctor::query()->where('display_name', 'Test Doctor 2')->firstOrFail();

        $this->withSession([
            BookingIntent::SESSION_KEY => [
                'clinic_id' => $clinicA->id,
                'doctor_id' => $doctorB->id,
            ],
        ])->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('patient.dashboard'));

        $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->post(route('ai-front-desk.booking-intent'), [
                'clinic_id' => $clinicA->id,
                'doctor_id' => $doctorB->id,
            ])
            ->assertRedirect(route('ai-front-desk'));
    }

    public function test_non_patient_login_does_not_resume_patient_booking(): void
    {
        $staff = User::factory()->role(RoleName::HospitalStaff)->create();
        $clinic = $this->clinicA();
        $doctor = $this->doctor1();

        $this->withSession([
            BookingIntent::SESSION_KEY => [
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
            ],
        ])->post(route('login.store'), [
            'email' => $staff->email,
            'password' => 'password',
        ])
            ->assertRedirect(route('staff.dashboard'))
            ->assertSessionMissing(BookingIntent::SESSION_KEY);
    }

    public function test_booking_intent_survives_registration_then_login(): void
    {
        $clinic = $this->clinicA();
        $doctor = $this->doctor1();

        $this->withSession([
            AiDisclaimer::SESSION_KEY => true,
            BookingIntent::SESSION_KEY => [
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
            ],
        ])->post(route('register.store'), [
            'first_name' => 'Maria',
            'middle_name' => null,
            'last_name' => 'Santos',
            'date_of_birth' => '2000-01-15',
            'sex' => Sex::Female->value,
            'contact_number' => '09181234567',
            'email' => 'maria.santos@example.com',
            'password' => 'MediGuide@2026',
            'password_confirmation' => 'MediGuide@2026',
        ])
            ->assertRedirect(route('login'))
            ->assertSessionHas(BookingIntent::SESSION_KEY, [
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
            ]);

        $user = User::query()->where('email', 'maria.santos@example.com')->firstOrFail();

        $this->withSession([
            BookingIntent::SESSION_KEY => [
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
            ],
        ])->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'MediGuide@2026',
        ])->assertRedirect(route('patient.book-appointment', [
            'clinic_id' => $clinic->id,
            'doctor_id' => $doctor->id,
        ]));
    }

    public function test_booking_intent_is_not_cleared_when_returning_to_public_home_with_acknowledged_disclaimer(): void
    {
        $clinic = $this->clinicA();
        $doctor = $this->doctor1();

        $this->withSession([
            AiDisclaimer::SESSION_KEY => true,
            BookingIntent::SESSION_KEY => [
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
            ],
        ])->get(route('home'))
            ->assertRedirect(route('ai-front-desk'))
            ->assertSessionHas(BookingIntent::SESSION_KEY, [
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
            ]);
    }

    public function test_booking_intent_is_not_cleared_when_returning_to_public_home_without_disclaimer(): void
    {
        $clinic = $this->clinicA();
        $doctor = $this->doctor1();

        $this->withSession([
            BookingIntent::SESSION_KEY => [
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
            ],
        ])->get(route('home'))
            ->assertOk()
            ->assertSessionHas(BookingIntent::SESSION_KEY, [
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
            ]);
    }

    public function test_ai_doctors_endpoint_cannot_bypass_disclaimer(): void
    {
        $clinic = $this->clinicA();

        $this->get(route('ai-front-desk.clinics.doctors', $clinic))
            ->assertRedirect(route('ai-disclaimer'));

        $this->post(route('ai-front-desk.booking-intent'), [
            'clinic_id' => $clinic->id,
            'doctor_id' => $this->doctor1()->id,
        ])->assertRedirect(route('ai-disclaimer'));
    }

    public function test_next_available_slot_comes_from_the_booking_service(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-10 08:00:00', 'Asia/Manila'));

        $clinic = $this->clinicA();
        $doctor = $this->doctor1();
        $booking = app(AppointmentBookingService::class);
        $slot = $booking->nextAvailableSlot($doctor);

        $this->assertNotNull($slot);

        $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->getJson(route('ai-front-desk.clinics.doctors', $clinic))
            ->assertOk()
            ->assertJsonFragment([
                'id' => $doctor->id,
                'display_name' => 'Test Doctor 1',
                'bookable' => true,
                'next_available' => $booking->formatSlotLabel($slot['date'], $slot['time']),
                'availability' => $booking->formatSlotLabel($slot['date'], $slot['time']),
            ]);
    }

    public function test_an_active_schedule_without_a_bookable_slot_is_not_offered(): void
    {
        config(['hospital.provisional_local_booking' => false]);
        Carbon::setTestNow(Carbon::parse('2026-11-10 08:00:00', 'Asia/Manila'));

        $clinic = $this->clinicA();
        $doctor = Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'display_name' => 'Poster Only Doctor',
            'specialization' => DevelopmentDoctorSeeder::SPECIALIZATION_A,
            'status' => DoctorStatus::Active,
        ]);
        DoctorSchedule::query()->create([
            'doctor_id' => $doctor->id,
            'day_of_week' => DayOfWeek::Wednesday,
            'start_time' => '13:00:00',
            'end_time' => '14:00:00',
            'slot_duration' => 30,
            'status' => DoctorScheduleStatus::Active,
            'provisional_demonstration' => true,
        ]);

        $card = collect($this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->getJson(route('ai-front-desk.clinics.doctors', $clinic))
            ->assertOk()
            ->json('doctors'))
            ->firstWhere('display_name', 'Poster Only Doctor');

        $this->assertIsArray($card);
        $this->assertFalse($card['bookable']);
        $this->assertNull($card['next_available']);
        $this->assertNotSame('Schedule available', $card['availability']);
    }

    public function test_imported_mode_hides_development_clinics_and_doctors(): void
    {
        config(['hospital.data_source' => HospitalDataSource::Imported->value]);

        $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->getJson(route('ai-front-desk.clinics.doctors', $this->clinicA()))
            ->assertNotFound()
            ->assertJsonMissing(['display_name' => 'Test Doctor 1']);
    }

    public function test_empty_clinic_returns_no_available_doctors_message(): void
    {
        $department = Department::query()->create([
            'name' => 'Empty Development Department',
            'status' => DepartmentStatus::Active,
        ]);
        $clinic = Clinic::query()->create([
            'department_id' => $department->id,
            'name' => 'Empty Development Clinic',
            'status' => ClinicStatus::Active,
        ]);

        $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->getJson(route('ai-front-desk.clinics.doctors', $clinic))
            ->assertOk()
            ->assertJsonPath('message', 'No available doctors for this clinic at the moment.')
            ->assertJsonCount(0, 'doctors');
    }

    private function fakeGuidanceRecommendation(Clinic $clinic): void
    {
        $this->fakeAnthropicPayload([
            'type' => 'recommendation',
            'clinic_id' => $clinic->id,
            'reason' => 'The stored clinic description supports this service.',
            'confidence' => null,
        ]);
    }

    private function fakeGuidanceServiceRecommendation(string $specialization): int
    {
        $serviceId = null;

        foreach (app(AiHospitalContextService::class)->availableContext()->services as $service) {
            if ($service->specialization === $specialization) {
                $serviceId = $service->serviceId;
                break;
            }
        }

        $this->assertNotNull($serviceId);

        $this->fakeAnthropicPayload([
            'type' => 'recommendation',
            'service_id' => $serviceId,
            'reason' => 'Navigation suggestion based on the listed hospital specialization '.$specialization.'.',
            'confidence' => 0.7,
        ]);

        return $serviceId;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fakeAnthropicPayload(array $payload): void
    {
        config([
            'services.anthropic.key' => 'test-anthropic-key',
            'services.anthropic.model' => 'claude-sonnet-5',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode($payload, JSON_THROW_ON_ERROR),
                ]],
            ]),
        ]);
    }

    private function clinicA(): Clinic
    {
        return Clinic::query()->where('name', 'Development Clinic A')->firstOrFail();
    }

    private function doctor1(): Doctor
    {
        return Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();
    }

    private function patientUser(): User
    {
        $this->patientSequence++;

        $user = User::factory()->create([
            'first_name' => 'Patient',
            'last_name' => 'User'.$this->patientSequence,
            'name' => 'Patient User'.$this->patientSequence,
            'email' => "patient{$this->patientSequence}@example.com",
        ]);

        $user->patient()->create([
            'date_of_birth' => '1990-01-01',
            'sex' => Sex::Male,
            'contact_number' => '0917000000'.$this->patientSequence,
        ]);

        return $user->fresh(['patient', 'role']);
    }
}
