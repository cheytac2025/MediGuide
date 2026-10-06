<?php

namespace Tests\Feature\Patient;

use App\Enums\ClinicStatus;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorStatus;
use App\Enums\RoleName;
use App\Enums\Sex;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\User;
use App\Support\AiDisclaimer;
use App\Support\BookingIntent;
use Database\Seeders\DevelopmentClinicSeeder;
use Database\Seeders\DevelopmentDepartmentSeeder;
use Database\Seeders\DevelopmentDoctorScheduleSeeder;
use Database\Seeders\DevelopmentDoctorSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_ai_recommendation_resolves_to_an_active_development_clinic(): void
    {
        $clinic = Clinic::query()->where('name', 'Development Clinic A')->firstOrFail();
        $this->fakeGuidanceRecommendation($clinic);

        $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->postJson(route('ai-front-desk.guidance'), [
                'message' => 'I have a headache.',
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
                'message' => 'I have a headache.',
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
                'specialization' => 'Development Specialization A',
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
            'specialization' => 'Development Specialization A',
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
                    'text' => json_encode([
                        'type' => 'recommendation',
                        'clinic_id' => $clinic->id,
                        'reason' => 'The stored clinic description supports this service.',
                        'confidence' => null,
                    ], JSON_THROW_ON_ERROR),
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
