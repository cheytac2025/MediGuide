<?php

namespace Tests\Feature\Demo;

use App\Enums\AppointmentStatus;
use App\Enums\DayOfWeek;
use App\Enums\DoctorScheduleStatus;
use App\Enums\DoctorStatus;
use App\Enums\RoleName;
use App\Enums\Sex;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\User;
use App\Services\Ai\AiSuggestedDoctorService;
use App\Services\AppointmentBookingService;
use App\Services\Demo\DemoBookingCoverageService;
use App\Services\HospitalImport\HospitalScheduleImportService;
use App\Support\AiGuidanceResult;
use App\Support\BookingIntent;
use App\Support\Demo\DemoBookingEnvironment;
use App\Support\HospitalImport\QueenMaryClinicScheduleCatalog;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DemoBookingCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-10 08:00:00', 'Asia/Manila'));
        $this->seed(RoleSeeder::class);
        config(['hospital.provisional_local_booking' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_command_refuses_without_demonstration_confirmation(): void
    {
        $this->artisan('mediguide:ensure-demo-booking-coverage')
            ->expectsOutputToContain('--confirm-local-demonstration')
            ->assertFailed();
    }

    public function test_command_refuses_blocked_production_database_name(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => 'mediguide_demo',
            'hospital.provisional_local_booking' => true,
        ]);

        $this->artisan('mediguide:ensure-demo-booking-coverage', [
            '--confirm-local-demonstration' => true,
            '--confirm-database' => 'mediguide_demo',
        ])
            ->expectsOutputToContain('blocked production/shared database')
            ->assertFailed();
    }

    public function test_command_refuses_when_provisional_flag_is_off(): void
    {
        config(['hospital.provisional_local_booking' => false]);

        $this->artisan('mediguide:ensure-demo-booking-coverage', [
            '--confirm-local-demonstration' => true,
            '--confirm-database' => ':memory:',
        ])
            ->expectsOutputToContain('HOSPITAL_PROVISIONAL_LOCAL_BOOKING must be true')
            ->assertFailed();
    }

    public function test_imported_catalog_reaches_full_eligible_booking_coverage(): void
    {
        app(HospitalScheduleImportService::class)->execute(30);

        $before = app(DemoBookingCoverageService::class)->ensureCoverage(dryRun: true);
        $this->assertSame(16, $before['eligible_active']);
        $this->assertGreaterThan(0, $before['made_bookable']);
        $this->assertSame(
            4,
            Doctor::query()
                ->whereIn('display_name', [
                    'Dr. Eclipse Jr., Eulalio',
                    'Dr. Enriquez, Tyron Vincent P.',
                    'Dr. Saquitan-Epetia, Angeli',
                    'Dr. Epetia, Mar Christopher',
                ])
                ->whereDoesntHave('schedules')
                ->count(),
        );

        $this->artisan('mediguide:ensure-demo-booking-coverage', [
            '--confirm-local-demonstration' => true,
            '--confirm-database' => ':memory:',
        ])->assertSuccessful();

        $after = app(DemoBookingCoverageService::class)->ensureCoverage(dryRun: true);
        $this->assertSame($after['eligible_active'], $after['bookable_after']);
        $this->assertSame(0, count($after['still_unbookable']));
        $this->assertSame(0, $after['made_bookable']);

        $booking = app(AppointmentBookingService::class);
        $active = Doctor::query()
            ->where('status', DoctorStatus::Active)
            ->with(['clinic.department', 'schedules'])
            ->orderBy('id')
            ->get();

        $this->assertCount(16, $active);

        foreach ($active as $doctor) {
            $slot = $booking->nextAvailableSlot($doctor);
            $this->assertNotNull($slot, 'Expected bookable slot for '.$doctor->display_name);
            $this->assertTrue(($slot['provisional'] ?? false) === true || $doctor->schedules->contains(
                fn (DoctorSchedule $s): bool => ! $s->provisional_demonstration,
            ));
        }

        foreach ([
            'Dr. Eclipse Jr., Eulalio',
            'Dr. Enriquez, Tyron Vincent P.',
            'Dr. Saquitan-Epetia, Angeli',
            'Dr. Epetia, Mar Christopher',
        ] as $name) {
            $doctor = Doctor::query()->where('display_name', $name)->firstOrFail();
            $this->assertTrue(
                $doctor->schedules()->where('provisional_demonstration', true)->exists(),
                $name.' should receive provisional demonstration windows',
            );
            $this->assertFalse(
                $doctor->schedules()->where('provisional_demonstration', false)->exists(),
                $name.' must not receive approved official schedules from demo coverage',
            );
        }
    }

    public function test_ai_cards_label_demonstration_slots_and_remain_specialty_grounded(): void
    {
        app(HospitalScheduleImportService::class)->execute(30);
        app(DemoBookingCoverageService::class)->ensureCoverage();

        $pediatrician = Doctor::query()->where('specialization', 'Pediatrician')->firstOrFail();
        $guidance = AiGuidanceResult::fromPayload([
            'type' => 'recommendation',
            'clinic_id' => $pediatrician->clinic_id,
            'service_id' => 1,
            'specialization' => 'Pediatrician',
            'reason' => 'Synthetic pediatric concern for demo coverage.',
        ]);

        $cards = app(AiSuggestedDoctorService::class)->forGuidance($guidance);
        $this->assertNotEmpty($cards['doctors']);

        foreach ($cards['doctors'] as $card) {
            $this->assertTrue($card['bookable']);
            $this->assertSame('Pediatrician', $card['specialization']);
            $this->assertStringContainsString('Demonstration availability:', $card['availability']);
            $this->assertTrue($card['demonstration_slot']);
        }

        $unrelated = Doctor::query()
            ->where('specialization', '!=', 'Pediatrician')
            ->pluck('id')
            ->all();
        $shownIds = array_column($cards['doctors'], 'id');
        $this->assertSame([], array_values(array_intersect($shownIds, $unrelated)));
    }

    public function test_each_active_catalog_doctor_can_complete_ai_handoff_and_booking(): void
    {
        Notification::fake();
        app(HospitalScheduleImportService::class)->execute(30);
        $coverage = app(DemoBookingCoverageService::class)->ensureCoverage();
        $this->assertSame($coverage['eligible_active'], $coverage['bookable_after']);

        $booking = app(AppointmentBookingService::class);

        $doctors = Doctor::query()
            ->where('status', DoctorStatus::Active)
            ->with(['clinic.department', 'schedules'])
            ->orderBy('id')
            ->get();

        foreach ($doctors as $index => $doctor) {
            $patient = $this->makePatient();
            $slot = $booking->nextAvailableSlot($doctor);
            $this->assertNotNull($slot, $doctor->display_name);

            $intent = BookingIntent::validated([
                'clinic_id' => $doctor->clinic_id,
                'doctor_id' => $doctor->id,
            ]);
            $this->assertNotNull($intent, 'Intent failed for '.$doctor->display_name);

            $guidance = AiGuidanceResult::fromPayload([
                'type' => 'recommendation',
                'clinic_id' => $doctor->clinic_id,
                'service_id' => $index + 1,
                'specialization' => $doctor->specialization,
                'reason' => 'Synthetic concern for '.$doctor->specialization,
            ]);
            $cards = app(AiSuggestedDoctorService::class)->forGuidance($guidance);
            $ids = array_column($cards['doctors'], 'id');
            $this->assertContains($doctor->id, $ids, 'Doctor missing from grounded specialty cards: '.$doctor->display_name);

            $schedule = $doctor->schedules
                ->first(fn (DoctorSchedule $s): bool => (int) ($slot['schedule_id'] ?? 0) === $s->id)
                ?? $doctor->schedules->first(
                    fn (DoctorSchedule $s): bool => $s->status === DoctorScheduleStatus::Active,
                );
            $this->assertInstanceOf(DoctorSchedule::class, $schedule);

            $appointment = $booking->book(
                $patient->patient,
                $doctor,
                $schedule,
                $slot['date'],
                $slot['time'],
            );

            $this->assertSame(AppointmentStatus::Pending, $appointment->status);
            $this->assertSame($doctor->id, $appointment->doctor_id);
            $this->assertDatabaseHas('appointments', [
                'id' => $appointment->id,
                'patient_id' => $patient->patient->id,
                'doctor_id' => $doctor->id,
            ]);
        }

        $this->assertSame($doctors->count(), Appointment::query()->count());
    }

    public function test_double_booking_still_blocked_on_demonstration_slots(): void
    {
        Notification::fake();
        app(HospitalScheduleImportService::class)->execute(30);
        app(DemoBookingCoverageService::class)->ensureCoverage();

        $doctor = Doctor::query()->where('display_name', 'Dr. Acosta, Emil Rommel')->firstOrFail();
        $booking = app(AppointmentBookingService::class);
        $slot = $booking->nextAvailableSlot($doctor);
        $this->assertNotNull($slot);

        $schedule = DoctorSchedule::query()->findOrFail($slot['schedule_id']);
        $first = $this->makePatient();
        $second = $this->makePatient();

        $booking->book($first->patient, $doctor, $schedule, $slot['date'], $slot['time']);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $booking->book($second->patient, $doctor, $schedule, $slot['date'], $slot['time']);
    }

    public function test_coverage_never_modifies_approved_non_provisional_schedules(): void
    {
        app(HospitalScheduleImportService::class)->execute(30);

        $doctor = Doctor::query()->where('display_name', 'Dr. Acosta, Emil Rommel')->firstOrFail();
        $approved = DoctorSchedule::query()->create([
            'doctor_id' => $doctor->id,
            'day_of_week' => DayOfWeek::Sunday,
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
            'slot_duration' => 30,
            'status' => DoctorScheduleStatus::Active,
            'provisional_demonstration' => false,
        ]);

        app(DemoBookingCoverageService::class)->ensureCoverage();

        $this->assertDatabaseHas('doctor_schedules', [
            'id' => $approved->id,
            'provisional_demonstration' => false,
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
        ]);
    }

    public function test_blocked_database_helper_recognizes_production_names(): void
    {
        $this->assertTrue(DemoBookingEnvironment::isBlockedDatabase('mediguide_demo'));
        $this->assertFalse(DemoBookingEnvironment::isBlockedDatabase('mediguide_showcase_capstone'));
        $this->assertFalse(DemoBookingEnvironment::isBlockedDatabase(':memory:'));
    }

    public function test_catalog_specialization_count_matches_unique_poster_rows(): void
    {
        $specializations = collect(QueenMaryClinicScheduleCatalog::doctors())
            ->pluck('specialization')
            ->unique()
            ->sort()
            ->values();

        $this->assertGreaterThanOrEqual(10, $specializations->count());
        $this->assertContains('Pediatrician', $specializations->all());
        $this->assertContains('Otolaryngologist (Ears, Nose, Throat)', $specializations->all());
    }

    private function makePatient(): User
    {
        static $sequence = 0;
        $sequence++;

        $user = User::factory()->role(RoleName::Patient)->create([
            'first_name' => 'Demo',
            'last_name' => 'Patient'.$sequence,
            'name' => 'Demo Patient'.$sequence,
            'email' => "demo.patient{$sequence}@example.com",
            'email_verified_at' => now(),
        ]);

        $user->patient()->create([
            'date_of_birth' => '1990-01-01',
            'sex' => Sex::Female,
            'contact_number' => '0917000'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
        ]);

        return $user->fresh(['patient']);
    }
}
