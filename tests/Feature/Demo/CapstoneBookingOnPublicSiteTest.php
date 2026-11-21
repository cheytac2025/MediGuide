<?php

namespace Tests\Feature\Demo;

use App\Enums\AppointmentStatus;
use App\Enums\DayOfWeek;
use App\Enums\DoctorScheduleStatus;
use App\Enums\DoctorStatus;
use App\Enums\RoleName;
use App\Enums\Sex;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\User;
use App\Services\Ai\AiSuggestedDoctorService;
use App\Services\AppointmentBookingService;
use App\Support\AiGuidanceResult;
use App\Support\Demo\DemoBookingEnvironment;
use Database\Seeders\RoleSeeder;
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

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-10 08:00:00', 'Asia/Manila'));
        $this->seed(RoleSeeder::class);

        // Simulate production public site: provisional flag OFF.
        config([
            'hospital.provisional_local_booking' => false,
            'hospital.demo_instance' => false,
            'hospital.capstone_booking' => false,
            'hospital.capstone_booking_accounts' => [],
        ]);
        $this->app['env'] = 'production';

        $department = Department::query()->create([
            'name' => 'Pending hospital mapping',
            'status' => 'active',
        ]);
        $clinic = Clinic::query()->create([
            'department_id' => $department->id,
            'name' => 'Pediatrician',
            'status' => 'active',
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
    }

    public function test_capstone_mode_shows_provisional_slots_without_enabling_global_provisional(): void
    {
        config([
            'hospital.capstone_booking' => true,
            'hospital.capstone_booking_accounts' => ['patient.demo@mediguide.test'],
            'hospital.provisional_local_booking' => false,
        ]);

        $this->assertFalse(DemoBookingEnvironment::provisionalBookingAllowed());
        $this->assertTrue(DemoBookingEnvironment::provisionalSlotsVisible());

        $slot = app(AppointmentBookingService::class)->nextAvailableSlot(
            $this->doctor->fresh(['schedules', 'clinic.department']),
        );
        $this->assertNotNull($slot);
        $this->assertTrue($slot['provisional']);

        $cards = app(AiSuggestedDoctorService::class)->forGuidance(AiGuidanceResult::fromPayload([
            'type' => 'recommendation',
            'clinic_id' => $this->doctor->clinic_id,
            'service_id' => 1,
            'specialization' => 'Pediatrician',
            'reason' => 'Synthetic pediatric concern.',
        ]));
        $this->assertTrue($cards['doctors'][0]['bookable']);
        $this->assertStringContainsString('Demonstration availability:', $cards['doctors'][0]['availability']);
    }

    public function test_only_allowlisted_synthetic_account_can_confirm_provisional_booking(): void
    {
        Notification::fake();
        config([
            'hospital.capstone_booking' => true,
            'hospital.capstone_booking_accounts' => ['patient.demo@mediguide.test'],
            'hospital.provisional_local_booking' => false,
        ]);

        $booking = app(AppointmentBookingService::class);
        $slot = $booking->nextAvailableSlot($this->doctor->fresh(['schedules', 'clinic.department']));
        $this->assertNotNull($slot);

        $outsider = $this->makePatient('outsider@example.com');
        $this->expectException(ValidationException::class);
        $booking->book(
            $outsider->patient,
            $this->doctor,
            $this->provisional,
            $slot['date'],
            $slot['time'],
        );
    }

    public function test_allowlisted_account_can_complete_ai_handoff_booking(): void
    {
        Notification::fake();
        config([
            'hospital.capstone_booking' => true,
            'hospital.capstone_booking_accounts' => ['patient.demo@mediguide.test'],
            'hospital.provisional_local_booking' => false,
        ]);

        $patient = $this->makePatient('patient.demo@mediguide.test');
        $this->assertTrue(DemoBookingEnvironment::provisionalBookingPermitted($patient));

        $booking = app(AppointmentBookingService::class);
        $slot = $booking->nextAvailableSlot($this->doctor->fresh(['schedules', 'clinic.department']));
        $this->assertNotNull($slot);

        $appointment = $booking->book(
            $patient->patient,
            $this->doctor,
            $this->provisional,
            $slot['date'],
            $slot['time'],
            'Synthetic mild fever for defense walkthrough.',
        );

        $this->assertSame(AppointmentStatus::Pending, $appointment->status);
        $this->assertSame($patient->patient->id, $appointment->patient_id);
        $this->assertTrue((bool) $this->provisional->fresh()->provisional_demonstration);
    }

    public function test_non_allowlisted_user_cannot_book_even_when_slots_are_visible(): void
    {
        config([
            'hospital.capstone_booking' => true,
            'hospital.capstone_booking_accounts' => ['patient.demo@mediguide.test'],
        ]);

        $outsider = $this->makePatient('public.user@example.com');
        $this->assertFalse(DemoBookingEnvironment::provisionalBookingPermitted($outsider));
        $this->assertTrue(DemoBookingEnvironment::provisionalSlotsVisible());
    }

    private function makePatient(string $email): User
    {
        $user = User::factory()->role(RoleName::Patient)->create([
            'email' => $email,
            'email_verified_at' => now(),
        ]);
        $user->patient()->create([
            'date_of_birth' => '1990-01-01',
            'sex' => Sex::Female,
            'contact_number' => '0917'.substr(md5($email), 0, 7),
        ]);

        return $user->fresh(['patient']);
    }
}
