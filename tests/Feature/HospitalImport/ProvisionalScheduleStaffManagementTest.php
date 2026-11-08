<?php

namespace Tests\Feature\HospitalImport;

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\DayOfWeek;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorScheduleStatus;
use App\Enums\DoctorStatus;
use App\Enums\HospitalDataSource;
use App\Enums\RoleName;
use App\Enums\Sex;
use App\Models\Appointment;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\User;
use App\Services\Ai\AiSuggestedDoctorService;
use App\Services\AppointmentBookingService;
use App\Services\HospitalImport\HospitalScheduleImportService;
use App\Support\AiGuidanceResult;
use App\Support\Audit\AuditVerificationService;
use App\Support\HospitalImport\QueenMaryClinicScheduleCatalog;
use App\Support\Integrity\IntegrityStatus;
use App\Support\Integrity\RecordIntegrityService;
use Database\Seeders\DevelopmentClinicSeeder;
use Database\Seeders\DevelopmentDepartmentSeeder;
use Database\Seeders\DevelopmentDoctorScheduleSeeder;
use Database\Seeders\DevelopmentDoctorSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProvisionalScheduleStaffManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-05 08:00:00', 'Asia/Manila'));
        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        config(['hospital.data_source' => HospitalDataSource::Imported->value]);
        app(HospitalScheduleImportService::class)->execute(30);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_assigned_staff_can_view_imported_doctors_and_provisional_windows(): void
    {
        $cadiente = $this->doctor('Dr. Cadiente, Gilbert');

        $this->actingAs($this->assignedStaff())
            ->get(route('staff.doctor-schedules'))
            ->assertOk()
            ->assertSee('Dr. Cadiente, Gilbert')
            ->assertSee('Internal Medicine')
            ->assertSee('Pending hospital mapping')
            ->assertDontSee('Internal Medicine · Internal Medicine')
            ->assertSee('Dr. Eclipse Jr., Eulalio')
            ->assertSee('Dr. Enriquez, Tyron Vincent P.')
            ->assertSee('Dr. Saquitan-Epetia, Angeli')
            ->assertSee('Dr. Epetia, Mar Christopher')
            ->assertDontSee('Test Doctor 1');

        $this->actingAs($this->assignedStaff())
            ->get(route('staff.doctor-schedules.show', $cadiente))
            ->assertOk()
            ->assertSee('Internal Medicine · Pending hospital mapping')
            ->assertDontSee('Internal Medicine · Internal Medicine')
            ->assertSee('MONDAY')
            ->assertSee('11:00 AM')
            ->assertSee('01:00 PM')
            ->assertSee('30 minute slots')
            ->assertSee('Edit');

        $this->actingAs($this->assignedStaff())
            ->get(route('staff.doctor-schedules.show', $this->doctor('Dr. Enriquez, Tyron Vincent P.')))
            ->assertOk()
            ->assertSee('No schedules on file for this doctor.');

        $this->assertSame(0, $this->doctor('Dr. Eclipse Jr., Eulalio')->schedules()->count());
        $this->assertSame(21, DoctorSchedule::query()->where('provisional_demonstration', true)->count());
    }

    public function test_staff_edits_recalculate_availability_for_booking_and_suggested_doctors(): void
    {
        $cadiente = $this->doctor('Dr. Cadiente, Gilbert');
        $monday = $this->window($cadiente, DayOfWeek::Monday);
        $wednesday = $this->window($cadiente, DayOfWeek::Wednesday);
        $friday = $this->window($cadiente, DayOfWeek::Friday);
        $booking = app(AppointmentBookingService::class);

        $this->assertSame(['date' => '2026-10-05', 'time' => '11:00'], $booking->nextAvailableSlot($cadiente));

        $this->actingAs($this->assignedStaff())
            ->patch(route('staff.doctor-schedules.update', $monday), $this->payload(
                DayOfWeek::Monday,
                '10:30',
                '13:00',
            ))
            ->assertRedirect(route('staff.doctor-schedules.show', $cadiente))
            ->assertSessionHas('schedule_status', 'Doctor schedule updated successfully.');

        $monday->refresh();
        $this->assertSame('10:30:00', substr((string) $monday->start_time, 0, 8));
        $this->assertTrue($monday->provisional_demonstration);
        $this->assertSame(['date' => '2026-10-05', 'time' => '10:30'], $booking->nextAvailableSlot($cadiente->fresh()));
        $this->assertSame('10:30', $booking->getAvailableSlots($cadiente, $monday, '2026-10-05')[0]);
        $this->assertNotContains('10:00', $booking->getAvailableSlots($cadiente, $monday, '2026-10-05'));

        $this->actingAs($this->assignedStaff())
            ->patch(route('staff.doctor-schedules.update', $wednesday), $this->payload(
                DayOfWeek::Wednesday,
                '11:00',
                '13:00',
                15,
            ))
            ->assertSessionHas('schedule_status');

        $this->assertSame(
            ['11:00', '11:15', '11:30', '11:45', '12:00', '12:15', '12:30', '12:45'],
            $booking->getAvailableSlots($cadiente, $wednesday->fresh(), '2026-10-07'),
        );

        $this->actingAs($this->assignedStaff())
            ->patch(route('staff.doctor-schedules.update', $friday), $this->payload(
                DayOfWeek::Thursday,
                '11:00',
                '13:00',
            ))
            ->assertSessionHas('schedule_status');

        $this->assertSame([], $booking->getAvailableSlots($cadiente, $friday->fresh(), '2026-10-09'));
        $this->assertContains('11:00', $booking->getAvailableSlots($cadiente, $friday->fresh(), '2026-10-08'));

        $card = $this->internalMedicineCard($cadiente);
        $this->assertTrue($card['bookable']);
        $this->assertSame('Mon, Oct 5, 2026, 10:30 AM', $card['next_available']);
        $this->assertSame(3, DB::table('audit_events')->where('action', AuditAction::ScheduleUpdated->value)->count());

        $chain = app(AuditVerificationService::class)->verify();
        $this->assertTrue($chain['passed']);
        $this->assertSame(0, $chain['invalid']);
        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_expired_and_inactive_provisional_windows_produce_no_slots(): void
    {
        $cadiente = $this->doctor('Dr. Cadiente, Gilbert');
        $monday = $this->window($cadiente, DayOfWeek::Monday);

        $this->actingAs($this->assignedStaff())
            ->patch(route('staff.doctor-schedules.update', $monday), $this->payload(
                DayOfWeek::Monday,
                '07:00',
                '08:00',
            ))
            ->assertSessionHas('schedule_status');

        $slot = app(AppointmentBookingService::class)->nextAvailableSlot($cadiente->fresh());
        $this->assertSame(['date' => '2026-10-07', 'time' => '11:00'], $slot);

        $this->actingAs($this->assignedStaff())
            ->patch(route('staff.doctor-schedules.status', $monday), [
                'status' => DoctorScheduleStatus::Inactive->value,
            ])
            ->assertSessionHas('schedule_status', 'Doctor schedule deactivated successfully.');

        $this->assertSame(
            ['date' => '2026-10-07', 'time' => '11:00'],
            app(AppointmentBookingService::class)->nextAvailableSlot($cadiente->fresh()),
        );
        $this->assertSame([], app(AppointmentBookingService::class)->getAvailableSlots($cadiente, $monday->fresh(), '2026-10-05'));

        $eclipse = $this->doctor('Dr. Eclipse Jr., Eulalio');
        $this->assertNull(app(AppointmentBookingService::class)->nextAvailableSlot($eclipse));
        $eclipseCard = $this->cardFor('General Surgery', $eclipse);
        $this->assertFalse($eclipseCard['bookable']);
        $this->assertNull($eclipseCard['next_available']);
    }

    public function test_pending_and_confirmed_appointments_block_incompatible_provisional_edits(): void
    {
        $cadiente = $this->doctor('Dr. Cadiente, Gilbert');
        $monday = $this->window($cadiente, DayOfWeek::Monday);
        $pending = $this->appointment($monday, AppointmentStatus::Pending, '11:00:00', '11:30:00');
        $confirmed = $this->appointment($monday, AppointmentStatus::Confirmed, '12:00:00', '12:30:00');

        $this->actingAs($this->assignedStaff())
            ->from(route('staff.doctor-schedules.show', $cadiente))
            ->patch(route('staff.doctor-schedules.update', $monday), $this->payload(
                DayOfWeek::Monday,
                '12:30',
                '13:00',
            ))
            ->assertSessionHasErrors('start_time');

        $monday->refresh();
        $this->assertSame('11:00:00', substr((string) $monday->start_time, 0, 8));
        $this->assertSame('13:00:00', substr((string) $monday->end_time, 0, 8));
        $this->assertSame(AppointmentStatus::Pending, $pending->fresh()->status);
        $this->assertSame(AppointmentStatus::Confirmed, $confirmed->fresh()->status);
        $this->assertSame(IntegrityStatus::Valid, app(RecordIntegrityService::class)->statusOf($pending->fresh()));
        $this->assertSame(IntegrityStatus::Valid, app(RecordIntegrityService::class)->statusOf($confirmed->fresh()));
        $this->assertSame(0, DB::table('audit_events')->where('action', AuditAction::ScheduleUpdated->value)->count());
        $this->assertContains('11:00', app(AppointmentBookingService::class)->getScheduleSlotStarts($monday));
        $this->assertNotContains('11:00', app(AppointmentBookingService::class)->getAvailableSlots($cadiente, $monday, '2026-10-12'));
        $this->assertNotContains('12:00', app(AppointmentBookingService::class)->getAvailableSlots($cadiente, $monday, '2026-10-12'));
    }

    public function test_unauthorized_users_cannot_manage_provisional_schedules(): void
    {
        $cadiente = $this->doctor('Dr. Cadiente, Gilbert');
        $monday = $this->window($cadiente, DayOfWeek::Monday);
        $payload = $this->payload(DayOfWeek::Monday, '10:00', '13:00');

        $this->get(route('staff.doctor-schedules.show', $cadiente))
            ->assertRedirect(route('login'));

        $outsider = $this->outsiderStaff();

        $this->actingAs($outsider)
            ->get(route('staff.doctor-schedules'))
            ->assertOk()
            ->assertDontSee('Dr. Cadiente, Gilbert');

        $this->actingAs($outsider)
            ->patch(route('staff.doctor-schedules.update', $monday), $payload)
            ->assertNotFound();

        foreach ([RoleName::ItAdministrator, RoleName::Doctor, RoleName::Patient] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->patch(route('staff.doctor-schedules.update', $monday), $payload)
                ->assertForbidden();

            $this->actingAs(User::factory()->role($role)->create())
                ->patch(route('staff.doctor-schedules.status', $monday), [
                    'status' => DoctorScheduleStatus::Inactive->value,
                ])
                ->assertForbidden();
        }

        $this->assertSame('11:00:00', substr((string) $monday->fresh()->start_time, 0, 8));
        $this->assertSame(DoctorScheduleStatus::Active, $monday->fresh()->status);
    }

    public function test_provisional_schedule_updates_reject_a_missing_csrf_token(): void
    {
        $this->app->instance(
            PreventRequestForgery::class,
            new class($this->app, $this->app->make(Encrypter::class)) extends PreventRequestForgery
            {
                protected function runningUnitTests()
                {
                    return false;
                }
            },
        );

        $cadiente = $this->doctor('Dr. Cadiente, Gilbert');
        $monday = $this->window($cadiente, DayOfWeek::Monday);

        $this->actingAs($this->assignedStaff())
            ->patch(route('staff.doctor-schedules.update', $monday), $this->payload(
                DayOfWeek::Monday,
                '10:00',
                '13:00',
            ))
            ->assertStatus(419);

        $this->assertSame('11:00:00', substr((string) $monday->fresh()->start_time, 0, 8));
    }

    public function test_imported_mode_suggested_doctors_ignore_development_records(): void
    {
        $this->seed(DevelopmentDepartmentSeeder::class);
        $this->seed(DevelopmentClinicSeeder::class);
        $this->seed(DevelopmentDoctorSeeder::class);
        $this->seed(DevelopmentDoctorScheduleSeeder::class);

        $development = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();
        $development->update([
            'specialization' => 'Internal Medicine',
            'status' => DoctorStatus::Active,
        ]);
        $ordinaryBefore = DoctorSchedule::query()->where('provisional_demonstration', false)->count();

        $names = array_column($this->internalMedicineCards(), 'display_name');
        $this->assertContains('Dr. Cadiente, Gilbert', $names);
        $this->assertContains('Dr. Chulvo-Chan, Ma. Niña Lourdes', $names);
        $this->assertNotContains('Test Doctor 1', $names);
        $this->assertSame($ordinaryBefore, DoctorSchedule::query()->where('provisional_demonstration', false)->count());
        $this->assertSame(21, DoctorSchedule::query()->where('provisional_demonstration', true)->count());
    }

    private function assignedStaff(): User
    {
        return $this->staffFor(QueenMaryClinicScheduleCatalog::PENDING_DEPARTMENT_NAME);
    }

    private function outsiderStaff(): User
    {
        $department = Department::query()->create([
            'name' => 'Other Department',
            'status' => DepartmentStatus::Active,
            'data_source' => HospitalDataSource::Development,
        ]);

        return $this->staffFor($department->name);
    }

    private function staffFor(string $departmentName): User
    {
        $user = User::factory()->role(RoleName::HospitalStaff)->create();
        $user->hospitalStaff()->create()->departments()->sync(
            Department::query()->where('name', $departmentName)->pluck('id'),
        );

        return $user;
    }

    private function doctor(string $name): Doctor
    {
        return Doctor::query()->where('display_name', $name)->firstOrFail();
    }

    private function window(Doctor $doctor, DayOfWeek $day): DoctorSchedule
    {
        return DoctorSchedule::query()
            ->where('doctor_id', $doctor->id)
            ->where('day_of_week', $day)
            ->firstOrFail();
    }

    /**
     * @return array{day_of_week: string, start_time: string, end_time: string, slot_duration: int, status: string}
     */
    private function payload(
        DayOfWeek $day,
        string $start,
        string $end,
        int $slotDuration = 30,
    ): array {
        return [
            'day_of_week' => $day->value,
            'start_time' => $start,
            'end_time' => $end,
            'slot_duration' => $slotDuration,
            'status' => DoctorScheduleStatus::Active->value,
        ];
    }

    private function appointment(
        DoctorSchedule $schedule,
        AppointmentStatus $status,
        string $start,
        string $end,
    ): Appointment {
        $user = User::factory()->role(RoleName::Patient)->create();
        $patient = $user->patient()->create([
            'date_of_birth' => '1990-01-01',
            'sex' => Sex::Female,
            'contact_number' => '09170000'.str_pad((string) $user->id, 3, '0', STR_PAD_LEFT),
        ]);

        return Appointment::query()->create([
            'patient_id' => $patient->id,
            'doctor_id' => $schedule->doctor_id,
            'doctor_schedule_id' => $schedule->id,
            'appointment_date' => '2026-10-12',
            'start_time' => $start,
            'end_time' => $end,
            'patient_concern' => 'Isolated schedule conflict check',
            'status' => $status,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function internalMedicineCard(Doctor $doctor): array
    {
        foreach ($this->internalMedicineCards() as $card) {
            if ($card['id'] === $doctor->id) {
                return $card;
            }
        }

        $this->fail('Imported doctor was not returned for Internal Medicine.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function internalMedicineCards(): array
    {
        return $this->cardsFor('Internal Medicine');
    }

    /**
     * @return array<string, mixed>
     */
    private function cardFor(string $specialization, Doctor $doctor): array
    {
        foreach ($this->cardsFor($specialization) as $card) {
            if ($card['id'] === $doctor->id) {
                return $card;
            }
        }

        $this->fail('Doctor was not returned for '.$specialization.'.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cardsFor(string $specialization): array
    {
        $guidance = AiGuidanceResult::fromPayload([
            'type' => 'recommendation',
            'service_id' => 1,
            'specialization' => $specialization,
            'reason' => 'Stored hospital specialization match.',
        ]);

        return app(AiSuggestedDoctorService::class)->forGuidance($guidance)['doctors'];
    }
}
