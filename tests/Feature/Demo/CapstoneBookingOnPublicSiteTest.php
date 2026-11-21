<?php

namespace Tests\Feature\Demo;

use App\Enums\AppointmentStatus;
use App\Enums\ClinicStatus;
use App\Enums\DayOfWeek;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorScheduleStatus;
use App\Enums\DoctorStatus;
use App\Enums\HospitalDataSource;
use App\Enums\RoleName;
use App\Enums\Sex;
use App\Enums\UserStatus;
use App\Http\Controllers\Patient\AiBookingIntentController;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\User;
use App\Services\Ai\AiSuggestedDoctorService;
use App\Services\AppointmentBookingService;
use App\Services\Demo\DemoBookingCoverageService;
use App\Support\AiDisclaimer;
use App\Support\AiGuidanceResult;
use App\Support\BookingIntent;
use App\Support\Demo\DemoBookingEnvironment;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CapstoneBookingOnPublicSiteTest extends TestCase
{
    use RefreshDatabase;

    private Doctor $doctor;

    private DoctorSchedule $provisional;

    private DoctorSchedule $approved;

    private Doctor $approvedOnlyDoctor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-10 08:00:00', 'Asia/Manila'));
        $this->seed(RoleSeeder::class);
        $this->withoutMiddleware(PreventRequestForgery::class);

        config([
            'hospital.provisional_local_booking' => false,
            'hospital.demo_instance' => false,
            'hospital.capstone_booking' => false,
            'hospital.capstone_booking_user_ids' => [],
            'hospital.capstone_booking_accounts' => [],
        ]);
        $this->app['env'] = 'production';

        $department = Department::query()->create([
            'name' => 'Pending hospital mapping',
            'status' => DepartmentStatus::Active,
            'data_source' => HospitalDataSource::Development,
        ]);
        $clinic = Clinic::query()->create([
            'department_id' => $department->id,
            'name' => 'Pediatrician',
            'status' => ClinicStatus::Active,
        ]);
        $this->doctor = Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'display_name' => 'Dr. Capstone Pediatrician',
            'specialization' => 'Pediatrician',
            'status' => DoctorStatus::Active,
        ]);
        $this->provisional = DoctorSchedule::query()->create([
            'doctor_id' => $this->doctor->id,
            'day_of_week' => DayOfWeek::Tuesday,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'slot_duration' => 30,
            'status' => DoctorScheduleStatus::Active,
            'provisional_demonstration' => true,
        ]);

        $orthoClinic = Clinic::query()->create([
            'department_id' => $department->id,
            'name' => 'Ortho',
            'status' => ClinicStatus::Active,
        ]);
        $this->approvedOnlyDoctor = Doctor::query()->create([
            'clinic_id' => $orthoClinic->id,
            'display_name' => 'Dr. Approved Ortho',
            'specialization' => 'Ortho',
            'status' => DoctorStatus::Active,
        ]);
        $this->approved = DoctorSchedule::query()->create([
            'doctor_id' => $this->approvedOnlyDoctor->id,
            'day_of_week' => DayOfWeek::Wednesday,
            'start_time' => '12:00:00',
            'end_time' => '14:00:00',
            'slot_duration' => 30,
            'status' => DoctorScheduleStatus::Active,
            'provisional_demonstration' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_public_site_hides_provisional_slots_when_capstone_mode_is_off(): void
    {
        $booking = app(AppointmentBookingService::class);

        $this->assertFalse(DemoBookingEnvironment::provisionalSlotsVisible());
        $this->assertNull($booking->nextAvailableSlot($this->doctor->fresh(['schedules', 'clinic.department'])));
        $this->assertNotNull($booking->nextAvailableSlot($this->approvedOnlyDoctor->fresh(['schedules', 'clinic.department'])));
    }

    public function test_guests_see_demonstration_availability_and_must_authenticate_to_book(): void
    {
        $synthetic = $this->makePatient('patient.demo@mediguide.test');

        config([
            'hospital.capstone_booking' => true,
            'hospital.capstone_booking_user_ids' => [(int) $synthetic->id],
            'hospital.capstone_booking_accounts' => [],
            'hospital.provisional_local_booking' => false,
        ]);

        $booking = app(AppointmentBookingService::class);

        $this->assertTrue(DemoBookingEnvironment::provisionalSlotsVisible(null));
        $this->assertTrue(DemoBookingEnvironment::mayOfferProvisionalBookingAction(null));
        $this->assertNotNull($booking->nextAvailableSlot($this->doctor->fresh(['schedules', 'clinic.department'])));

        $cardsAsGuest = app(AiSuggestedDoctorService::class)->forGuidance(AiGuidanceResult::fromPayload([
            'type' => 'recommendation',
            'clinic_id' => $this->doctor->clinic_id,
            'service_id' => 1,
            'specialization' => 'Pediatrician',
            'reason' => 'Synthetic pediatric concern.',
        ]));
        $this->assertTrue($cardsAsGuest['doctors'][0]['bookable']);
        $this->assertStringContainsString('Demonstration availability:', $cardsAsGuest['doctors'][0]['availability']);

        $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->post(route('ai-front-desk.booking-intent'), [
                'clinic_id' => $this->doctor->clinic_id,
                'doctor_id' => $this->doctor->id,
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHas(BookingIntent::SESSION_KEY, [
                'clinic_id' => $this->doctor->clinic_id,
                'doctor_id' => $this->doctor->id,
            ])
            ->assertSessionHas('status', AiBookingIntentController::AUTH_REQUIRED_TO_BOOK_MESSAGE);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee(AiBookingIntentController::AUTH_REQUIRED_TO_BOOK_MESSAGE, false)
            ->assertSee('Create Account', false);
    }

    public function test_guest_booking_intent_resumes_for_authorized_patient_after_login(): void
    {
        Notification::fake();
        $synthetic = $this->makePatient('patient.demo@mediguide.test');

        config([
            'hospital.capstone_booking' => true,
            'hospital.capstone_booking_user_ids' => [(int) $synthetic->id],
            'hospital.provisional_local_booking' => false,
        ]);

        $this->withSession([
            AiDisclaimer::SESSION_KEY => true,
            BookingIntent::SESSION_KEY => [
                'clinic_id' => $this->doctor->clinic_id,
                'doctor_id' => $this->doctor->id,
            ],
        ])->post(route('login.store'), [
            'email' => $synthetic->email,
            'password' => 'password',
        ])->assertRedirect(route('patient.book-appointment', [
            'clinic_id' => $this->doctor->clinic_id,
            'doctor_id' => $this->doctor->id,
        ]));

        $this->actingAs($synthetic)
            ->get(route('patient.book-appointment', [
                'clinic_id' => $this->doctor->clinic_id,
                'doctor_id' => $this->doctor->id,
            ]))
            ->assertOk()
            ->assertSee('Dr. Capstone Pediatrician', false);
    }

    public function test_ordinary_patient_cannot_confirm_provisional_and_sees_explanation(): void
    {
        $synthetic = $this->makePatient('patient.demo@mediguide.test');
        $outsider = $this->makePatient('public.user@example.com');

        config([
            'hospital.capstone_booking' => true,
            'hospital.capstone_booking_user_ids' => [(int) $synthetic->id],
            'hospital.provisional_local_booking' => false,
        ]);

        $this->actingAs($outsider);
        $cards = app(AiSuggestedDoctorService::class)->forGuidance(AiGuidanceResult::fromPayload([
            'type' => 'recommendation',
            'clinic_id' => $this->doctor->clinic_id,
            'service_id' => 1,
            'specialization' => 'Pediatrician',
            'reason' => 'Synthetic pediatric concern.',
        ]));
        $this->assertFalse($cards['doctors'][0]['bookable']);
        $this->assertStringContainsString('Demonstration availability:', $cards['doctors'][0]['availability']);
        $this->assertStringContainsString('authorized demonstration patient account', $cards['doctors'][0]['availability']);

        $this->actingAs($outsider)
            ->withSession([AiDisclaimer::SESSION_KEY => true])
            ->post(route('ai-front-desk.booking-intent'), [
                'clinic_id' => $this->doctor->clinic_id,
                'doctor_id' => $this->doctor->id,
            ])
            ->assertRedirect(route('ai-front-desk'))
            ->assertSessionHas('status', AiBookingIntentController::DEMONSTRATION_ACCOUNT_REQUIRED_MESSAGE);

        $this->assertNotNull(app(AppointmentBookingService::class)->nextAvailableSlot(
            $this->approvedOnlyDoctor->fresh(['schedules', 'clinic.department']),
        ));
    }

    public function test_allowlisted_verified_patient_sees_and_books_provisional_by_user_id(): void
    {
        Notification::fake();
        $patient = $this->makePatient('patient.demo@mediguide.test');

        config([
            'hospital.capstone_booking' => true,
            'hospital.capstone_booking_user_ids' => [(int) $patient->id],
            'hospital.capstone_booking_accounts' => ['ignored@example.com'],
            'hospital.provisional_local_booking' => false,
        ]);

        $this->assertFalse(DemoBookingEnvironment::provisionalBookingAllowed());
        $this->assertTrue(DemoBookingEnvironment::isAuthorizedCapstonePatient($patient));
        $this->assertTrue(DemoBookingEnvironment::provisionalSlotsVisible($patient));
        $this->assertTrue(DemoBookingEnvironment::provisionalSlotsVisible(null));

        $this->actingAs($patient);

        $slot = app(AppointmentBookingService::class)->nextAvailableSlot(
            $this->doctor->fresh(['schedules', 'clinic.department']),
        );
        $this->assertNotNull($slot);
        $this->assertTrue($slot['provisional']);

        $appointment = app(AppointmentBookingService::class)->book(
            $patient->patient,
            $this->doctor,
            $this->provisional,
            $slot['date'],
            $slot['time'],
            'Synthetic mild fever for defense walkthrough.',
        );

        $this->assertSame(AppointmentStatus::Pending, $appointment->status);
        $this->assertSame($patient->patient->id, $appointment->patient_id);
    }

    public function test_email_allowlist_is_fallback_only_when_user_ids_empty(): void
    {
        $patient = $this->makePatient('patient.demo@mediguide.test');
        $outsider = $this->makePatient('outsider@example.com');

        config([
            'hospital.capstone_booking' => true,
            'hospital.capstone_booking_user_ids' => [],
            'hospital.capstone_booking_accounts' => ['patient.demo@mediguide.test'],
        ]);

        $this->assertTrue(DemoBookingEnvironment::isAuthorizedCapstonePatient($patient));
        $this->assertFalse(DemoBookingEnvironment::isAuthorizedCapstonePatient($outsider));
    }

    public function test_user_id_allowlist_ignores_mismatched_email(): void
    {
        $patient = $this->makePatient('patient.demo@mediguide.test');

        config([
            'hospital.capstone_booking' => true,
            'hospital.capstone_booking_user_ids' => [999999],
            'hospital.capstone_booking_accounts' => ['patient.demo@mediguide.test'],
        ]);

        $this->assertFalse(DemoBookingEnvironment::isAuthorizedCapstonePatient($patient));
    }

    public function test_unverified_or_inactive_allowlisted_user_is_rejected(): void
    {
        $unverified = $this->makePatient('patient.demo@mediguide.test', verified: false);
        $inactive = $this->makePatient('inactive.demo@mediguide.test', status: UserStatus::Inactive);

        config([
            'hospital.capstone_booking' => true,
            'hospital.capstone_booking_user_ids' => [(int) $unverified->id, (int) $inactive->id],
        ]);

        $this->assertFalse(DemoBookingEnvironment::isAuthorizedCapstonePatient($unverified));
        $this->assertFalse(DemoBookingEnvironment::isAuthorizedCapstonePatient($inactive));
    }

    public function test_ordinary_patient_cannot_confirm_provisional_even_when_synthetic_can(): void
    {
        Notification::fake();
        $synthetic = $this->makePatient('patient.demo@mediguide.test');
        $outsider = $this->makePatient('outsider@example.com');

        config([
            'hospital.capstone_booking' => true,
            'hospital.capstone_booking_user_ids' => [(int) $synthetic->id],
            'hospital.provisional_local_booking' => false,
        ]);

        $this->actingAs($synthetic);
        $booking = app(AppointmentBookingService::class);
        $slot = $booking->nextAvailableSlot($this->doctor->fresh(['schedules', 'clinic.department']));
        $this->assertNotNull($slot);

        $this->expectException(ValidationException::class);
        $booking->book(
            $outsider->patient,
            $this->doctor,
            $this->provisional,
            $slot['date'],
            $slot['time'],
        );
    }

    public function test_phase4j_pilot_is_explicitly_excluded_from_demo_coverage(): void
    {
        config(['hospital.provisional_local_booking' => true]);
        $this->app['env'] = 'testing';

        $department = Department::query()->firstOrFail();
        $clinic = Clinic::query()->create([
            'department_id' => $department->id,
            'name' => 'Pilot Clinic',
            'status' => ClinicStatus::Active,
        ]);
        $pilot = Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'display_name' => 'Phase4J DoctorPilot',
            'specialization' => 'Capstone pilot (non-clinical)',
            'status' => DoctorStatus::Active,
        ]);

        $coverage = app(DemoBookingCoverageService::class);
        $row = $coverage->inventoryRow($pilot->fresh(['clinic.department', 'schedules']));

        $this->assertSame('non_clinical_pilot', $row['exclusion_reason']);
        $this->assertFalse($row['eligible_scope']);
        $this->assertFalse($row['in_imported_catalog']);
    }

    public function test_banner_for_guests_and_authorized_capstone_patient(): void
    {
        $synthetic = $this->makePatient('patient.demo@mediguide.test');
        $outsider = $this->makePatient('public.user@example.com');

        config([
            'hospital.capstone_booking' => true,
            'hospital.capstone_booking_user_ids' => [(int) $synthetic->id],
        ]);

        $this->assertTrue(DemoBookingEnvironment::shouldShowBanner(null));
        $this->assertFalse(DemoBookingEnvironment::shouldShowBanner($outsider));
        $this->assertTrue(DemoBookingEnvironment::shouldShowBanner($synthetic));
    }

    private function makePatient(
        string $email,
        bool $verified = true,
        UserStatus $status = UserStatus::Active,
    ): User {
        $user = User::factory()->role(RoleName::Patient)->create([
            'email' => $email,
            'email_verified_at' => $verified ? now() : null,
            'status' => $status,
        ]);
        $user->patient()->create([
            'date_of_birth' => '1990-01-01',
            'sex' => Sex::Female,
            'contact_number' => '0917'.substr(md5($email), 0, 7),
        ]);

        return $user->fresh(['patient', 'role']);
    }
}
