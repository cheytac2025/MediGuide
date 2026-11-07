<?php

namespace Tests\Feature\HospitalImport;

use App\Enums\AiGuidanceType;
use App\Enums\AppointmentStatus;
use App\Enums\ClinicStatus;
use App\Enums\DayOfWeek;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorScheduleStatus;
use App\Enums\DoctorStatus;
use App\Enums\AuditAction;
use App\Enums\HospitalDataSource;
use App\Enums\RoleName;
use App\Enums\Sex;
use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\User;
use App\Services\Ai\AiSuggestedDoctorService;
use App\Services\AppointmentBookingService;
use App\Services\HospitalImport\HospitalScheduleActivationPreview;
use App\Support\AiGuidanceResult;
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
use Tests\TestCase;

class HospitalScheduleActivationPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-05 08:00:00', 'Asia/Manila'));
        config(['hospital.data_source' => 'development']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_schedule_dry_run_makes_no_database_changes(): void
    {
        $this->importedDoctor('Dr. Acosta, Emil Rommel', 'Otolaryngologist (Ears, Nose, Throat)');
        $before = $this->counts();

        $preview = $this->preview->preview();

        $this->assertTrue($preview['dry_run']);
        $this->assertFalse($preview['writes']);
        $this->assertSame(0, $preview['online_bookable_count']);
        $this->assertSame($before, $this->counts());
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_doctor_specific_durations_are_previewed_and_not_written(): void
    {
        $preview = $this->preview->preview([
            'proposals' => $this->catalog(['acosta-emil-rommel', 'combe-reynaldo']),
            'approved_durations' => [
                'acosta-emil-rommel' => 20,
                'combe-reynaldo' => 45,
            ],
        ]);

        $acosta = $this->row($preview, 'acosta-emil-rommel');
        $combe = $this->row($preview, 'combe-reynaldo');

        $this->assertSame(20, $acosta['proposed_slot_duration']);
        $this->assertSame(45, $combe['proposed_slot_duration']);
        $this->assertNotSame($acosta['proposed_slot_duration'], $combe['proposed_slot_duration']);
        $this->assertSame(0, DoctorSchedule::query()->count());
    }

    public function test_catalog_preserves_distinct_days_and_multiple_windows(): void
    {
        $preview = $this->preview->preview([
            'proposals' => $this->catalog(['cadiente-gilbert', 'arada-hocate-maria-nickle']),
        ]);

        $cadiente = $this->rows($preview, 'cadiente-gilbert');
        $arada = $this->rows($preview, 'arada-hocate-maria-nickle');

        $this->assertSame(['monday', 'wednesday', 'friday'], array_column($cadiente, 'consultation_day'));
        $this->assertSame(['11:00', '11:00', '11:00'], array_column($cadiente, 'start'));
        $this->assertCount(2, $arada);
        $this->assertSame('tuesday', $arada[0]['consultation_day']);
        $this->assertSame('15:00', $arada[0]['start']);
        $this->assertSame('thursday', $arada[1]['consultation_day']);
        $this->assertSame('14:00', $arada[1]['start']);
    }

    public function test_invalid_consultation_time_is_not_bookable(): void
    {
        $preview = $this->preview->preview([
            'proposals' => [[
                'key' => 'invalid-window',
                'display_name' => 'Dr. Preview Invalid',
                'specialization' => 'Internal Medicine',
                'booking_mode' => 'fixed',
                'schedules' => [[
                    'day' => DayOfWeek::Monday,
                    'start' => '16:00',
                    'end' => '15:00',
                ]],
            ]],
            'approved_durations' => ['invalid-window' => 20],
            'authorized_keys' => ['invalid-window'],
            'operationally_valid_keys' => ['invalid-window'],
            'confirmed_identity_keys' => ['invalid-window'],
        ]);

        $row = $preview['rows'][0];

        $this->assertTrue($row['invalid_consultation_time']);
        $this->assertFalse($row['would_become_online_bookable']);
        $this->assertContains('valid_consultation_time', $row['missing_hospital_approvals']);
        $this->assertSame(0, DoctorSchedule::query()->count());
    }

    public function test_appointment_only_and_no_fixed_hours_stay_closed(): void
    {
        $this->importedDoctor('Dr. Enriquez, Tyron Vincent P.', 'Orthopedic Surgeon & Traumatology (Fracture, Bone & Joint Disease)');
        $this->importedDoctor('Dr. Saquitan-Epetia, Angeli', 'Developmental and Behavioral Pediatrician');

        $preview = $this->preview->preview([
            'proposals' => $this->catalog([
                'enriquez-tyron-vincent-p',
                'eclipse-jr-eulalio',
                'saquitan-epetia-angeli',
                'epetia-mar-christopher',
            ]),
            'approved_durations' => [
                'enriquez-tyron-vincent-p' => 30,
                'eclipse-jr-eulalio' => 30,
            ],
            'authorized_keys' => ['enriquez-tyron-vincent-p', 'saquitan-epetia-angeli'],
            'operationally_valid_keys' => ['enriquez-tyron-vincent-p', 'saquitan-epetia-angeli'],
            'confirmed_identity_keys' => ['enriquez-tyron-vincent-p', 'saquitan-epetia-angeli'],
        ]);

        foreach ($this->rows($preview, 'enriquez-tyron-vincent-p') as $row) {
            $this->assertSame('appointment_only_listed_hours', $row['poster_classification']);
            $this->assertFalse($row['would_become_online_bookable']);
            $this->assertContains('appointment_only_policy', $row['missing_hospital_approvals']);
        }

        $noHours = $this->row($preview, 'saquitan-epetia-angeli');
        $this->assertSame('no_fixed_hours', $noHours['poster_classification']);
        $this->assertNull($noHours['consultation_day']);
        $this->assertFalse($noHours['would_become_online_bookable']);
        $this->assertSame(0, DoctorSchedule::query()->count());
    }

    public function test_inactive_doctor_clinic_and_department_block_activation(): void
    {
        $inactiveDoctor = $this->importedDoctor('Dr. Acosta, Emil Rommel', 'Otolaryngologist (Ears, Nose, Throat)', DoctorStatus::Inactive);
        $inactiveClinic = $this->importedDoctor('Dr. Combe, Reynaldo', 'Urologist');
        $inactiveClinic->clinic->update(['status' => ClinicStatus::Inactive]);
        $inactiveDepartment = $this->importedDoctor('Dr. Dulay, Dennis', 'IM-Oncologist');
        $inactiveDepartment->clinic->department->update(['status' => DepartmentStatus::Inactive]);

        $preview = $this->readyPreview(['acosta-emil-rommel', 'combe-reynaldo', 'dulay-dennis'], 20);

        $this->assertContains('active_doctor', $this->row($preview, 'acosta-emil-rommel')['missing_hospital_approvals']);
        $this->assertContains('active_clinic', $this->row($preview, 'combe-reynaldo')['missing_hospital_approvals']);
        $this->assertContains('active_department', $this->row($preview, 'dulay-dennis')['missing_hospital_approvals']);
        $this->assertSame(0, $preview['online_bookable_count']);
        $this->assertSame(DoctorStatus::Inactive, $inactiveDoctor->fresh()->status);
    }

    public function test_existing_appointment_and_schedule_conflicts_are_reported_without_changes(): void
    {
        $doctor = $this->importedDoctor('Dr. Acosta, Emil Rommel', 'Otolaryngologist (Ears, Nose, Throat)');
        $schedule = DoctorSchedule::query()->create([
            'doctor_id' => $doctor->id,
            'day_of_week' => DayOfWeek::Thursday,
            'start_time' => '15:00',
            'end_time' => '16:00',
            'slot_duration' => 60,
            'status' => DoctorScheduleStatus::Active,
        ]);
        $patient = $this->patient();
        $appointment = Appointment::query()->create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'doctor_schedule_id' => $schedule->id,
            'appointment_date' => '2026-10-08',
            'start_time' => '15:00:00',
            'end_time' => '16:00:00',
            'status' => AppointmentStatus::Pending,
        ]);

        $duplicate = $this->readyPreview(['acosta-emil-rommel'], 60);
        $row = $this->row($duplicate, 'acosta-emil-rommel');

        $this->assertContains('duplicate_active_schedule', $row['existing_schedule_conflicts']);
        $this->assertContains('pending_or_confirmed_appointment', $row['existing_appointment_conflicts']);
        $this->assertFalse($row['would_become_online_bookable']);
        $this->assertSame(1, DoctorSchedule::query()->count());
        $this->assertSame(AppointmentStatus::Pending, $appointment->fresh()->status);

        $overlapDoctor = $this->importedDoctor('Dr. Cabreira, Alvin', 'Urologist');
        DoctorSchedule::query()->create([
            'doctor_id' => $overlapDoctor->id,
            'day_of_week' => DayOfWeek::Thursday,
            'start_time' => '15:00',
            'end_time' => '15:30',
            'slot_duration' => 30,
            'status' => DoctorScheduleStatus::Active,
        ]);

        $overlap = $this->readyPreview(['cabreira-alvin'], 30);
        $this->assertContains(
            'overlapping_active_schedule',
            $this->row($overlap, 'cabreira-alvin')['existing_schedule_conflicts'],
        );
        $this->assertFalse($this->row($overlap, 'cabreira-alvin')['would_become_online_bookable']);
    }

    public function test_fully_described_fixed_window_can_be_marked_ready_without_writing(): void
    {
        $this->importedDoctor('Dr. Endaya, Hazel Anne', 'Pediatrician');
        $before = $this->counts();

        $preview = $this->readyPreview(['endaya-hazel-anne'], 45);
        $row = $this->row($preview, 'endaya-hazel-anne');

        $this->assertSame('saturday', $row['consultation_day']);
        $this->assertSame('14:00', $row['start']);
        $this->assertSame('17:00', $row['end']);
        $this->assertSame(45, $row['proposed_slot_duration']);
        $this->assertTrue($row['would_become_online_bookable']);
        $this->assertSame([], $row['missing_hospital_approvals']);
        $this->assertSame($before, $this->counts());
    }

    public function test_booking_window_timezone_and_absence_of_invented_slots_stay_in_place(): void
    {
        $doctor = $this->importedDoctor('Dr. Balajadia, Ma. Imelda', 'IM-Cardiologist');
        $booking = app(AppointmentBookingService::class);

        $this->assertSame('Asia/Manila', config('app.timezone'));
        $this->assertSame(7, $booking->bookingWindowDays());
        $this->assertSame('2026-10-12', $booking->lastBookableDate()->toDateString());
        $this->assertNull($booking->nextAvailableSlot($doctor));

        $schedule = DoctorSchedule::query()->create([
            'doctor_id' => $doctor->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '12:00',
            'end_time' => '15:00',
            'slot_duration' => 60,
            'status' => DoctorScheduleStatus::Active,
        ]);

        $this->assertNotEmpty($booking->getAvailableSlots($doctor, $schedule, '2026-10-12'));
        $this->assertSame([], $booking->getAvailableSlots($doctor, $schedule, '2026-10-19'));

        $this->preview->preview([
            'approved_durations' => ['balajadia-ma-imelda' => 15],
        ]);

        $this->assertSame(1, DoctorSchedule::query()->count());
        $this->assertSame(60, $schedule->fresh()->slot_duration);
    }

    public function test_suggested_doctors_stay_unavailable_until_a_real_schedule_exists(): void
    {
        $doctor = $this->importedDoctor('Dr. Endaya, Hazel Anne', 'Pediatrician');
        $result = AiGuidanceResult::fromPayload([
            'type' => 'recommendation',
            'service_id' => 1,
            'specialization' => 'Pediatrician',
            'reason' => 'Navigation suggestion based on the listed specialization.',
            'confidence' => null,
        ]);

        $suggested = app(AiSuggestedDoctorService::class)->forGuidance($result);

        $this->assertSame(AiGuidanceType::Recommendation, $result->type);
        $this->assertSame($doctor->id, $suggested['doctors'][0]['id']);
        $this->assertFalse($suggested['doctors'][0]['bookable']);
        $this->assertNull($suggested['doctors'][0]['next_available']);
        $this->assertSame(AiSuggestedDoctorService::NO_ONLINE_SLOTS_MESSAGE, $suggested['message']);

        DoctorSchedule::query()->create([
            'doctor_id' => $doctor->id,
            'day_of_week' => DayOfWeek::Saturday,
            'start_time' => '14:00',
            'end_time' => '17:00',
            'slot_duration' => 60,
            'status' => DoctorScheduleStatus::Active,
        ]);

        $available = app(AiSuggestedDoctorService::class)->forGuidance($result);

        $this->assertTrue($available['doctors'][0]['bookable']);
        $this->assertNotNull($available['doctors'][0]['next_available']);
        $this->assertStringNotContainsString('99', (string) $available['doctors'][0]['next_available']);
    }

    public function test_existing_booking_integrity_audit_rbac_and_csrf_remain_effective(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(DevelopmentDepartmentSeeder::class);
        $this->seed(DevelopmentClinicSeeder::class);
        $this->seed(DevelopmentDoctorSeeder::class);
        $this->seed(DevelopmentDoctorScheduleSeeder::class);

        $doctor = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();
        $schedule = DoctorSchedule::query()
            ->where('doctor_id', $doctor->id)
            ->where('day_of_week', DayOfWeek::Monday)
            ->firstOrFail();
        $patient = $this->patient();

        $appointment = app(AppointmentBookingService::class)->book(
            $patient,
            $doctor,
            $schedule,
            '2026-10-05',
            '09:00',
            'Synthetic booking check.',
        );

        $this->assertSame(
            IntegrityStatus::Valid,
            app(RecordIntegrityService::class)->statusOf($appointment),
        );
        $this->assertTrue(
            AuditEvent::query()->where('action', AuditAction::AppointmentCreated->value)->exists(),
        );

        $staff = User::factory()->role(RoleName::HospitalStaff)->create();
        $staff->hospitalStaff()->create()->departments()->sync(
            Department::query()->where('name', 'Development Department A')->pluck('id'),
        );

        $this->actingAs($staff)->get(route('staff.doctor-schedules'))->assertOk();
        $this->actingAs(User::factory()->role(RoleName::Patient)->create())
            ->get(route('staff.doctor-schedules'))
            ->assertForbidden();
        $this->actingAs(User::factory()->role(RoleName::ItAdministrator)->create())
            ->get(route('staff.doctor-schedules'))
            ->assertForbidden();

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

        $this->actingAs($staff)
            ->post(route('staff.doctor-schedules.store', $doctor), [
                'day_of_week' => DayOfWeek::Friday->value,
                'start_time' => '09:00',
                'end_time' => '10:00',
                'slot_duration' => 30,
                'status' => DoctorScheduleStatus::Active->value,
            ])
            ->assertStatus(419);

        $this->assertSame(AppointmentStatus::Pending, $appointment->fresh()->status);
    }

    public function test_activation_command_is_a_dry_run(): void
    {
        $before = $this->counts();

        $this->artisan('mediguide:preview-schedule-activation')
            ->expectsOutputToContain('DRY RUN — no database writes.')
            ->assertSuccessful();

        $this->assertSame($before, $this->counts());
    }

    public function test_poster_summary_matches_the_existing_catalog(): void
    {
        $catalog = collect(QueenMaryClinicScheduleCatalog::doctors())->keyBy('key');

        $this->assertSame('thursday', $catalog['acosta-emil-rommel']['schedules'][0]['day']->value);
        $this->assertSame('15:00', $catalog['acosta-emil-rommel']['schedules'][0]['start']);
        $this->assertSame('16:00', $catalog['acosta-emil-rommel']['schedules'][0]['end']);
        $this->assertSame('by_appointment', $catalog['eclipse-jr-eulalio']['booking_mode']);
        $this->assertSame([], $catalog['eclipse-jr-eulalio']['schedules']);
        $this->assertSame('10:00', $catalog['eclipse-jr-eulalio']['listed_hours'][0]['start']);
        $this->assertSame('strictly_by_appointment', $catalog['epetia-mar-christopher']['booking_mode']);
        $this->assertSame([], $catalog['epetia-mar-christopher']['schedules']);
        $this->assertCount(16, $catalog);
    }

    private HospitalScheduleActivationPreview $preview {
        get => app(HospitalScheduleActivationPreview::class);
    }

    /**
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private function catalog(array $keys): array
    {
        return array_values(array_filter(
            QueenMaryClinicScheduleCatalog::doctors(),
            fn (array $doctor): bool => in_array($doctor['key'], $keys, true),
        ));
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function readyPreview(array $keys, int $duration): array
    {
        $durations = [];

        foreach ($keys as $key) {
            $durations[$key] = $duration;
        }

        return $this->preview->preview([
            'proposals' => $this->catalog($keys),
            'approved_durations' => $durations,
            'authorized_keys' => $keys,
            'operationally_valid_keys' => $keys,
            'confirmed_identity_keys' => $keys,
        ]);
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    private function row(array $preview, string $key): array
    {
        return $this->rows($preview, $key)[0];
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return list<array<string, mixed>>
     */
    private function rows(array $preview, string $key): array
    {
        return array_values(array_filter(
            $preview['rows'],
            fn (array $row): bool => $row['doctor_key'] === $key,
        ));
    }

    /**
     * @return array{doctors: int, schedules: int, appointments: int}
     */
    private function counts(): array
    {
        return [
            'doctors' => Doctor::query()->count(),
            'schedules' => DoctorSchedule::query()->count(),
            'appointments' => Appointment::query()->count(),
        ];
    }

    private function importedDoctor(
        string $name,
        string $specialization,
        DoctorStatus $status = DoctorStatus::Active,
    ): Doctor {
        $department = Department::query()->create([
            'name' => 'Pending hospital mapping '.$name,
            'description' => 'Temporary grouping for a synthetic activation preview.',
            'status' => DepartmentStatus::Active,
            'data_source' => HospitalDataSource::Development,
        ]);
        $clinic = Clinic::query()->create([
            'department_id' => $department->id,
            'name' => $specialization,
            'description' => 'Synthetic clinic for schedule preview.',
            'status' => ClinicStatus::Active,
            'data_source' => HospitalDataSource::Development,
        ]);

        return Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'display_name' => $name,
            'specialization' => $specialization,
            'status' => $status,
            'data_source' => HospitalDataSource::Development,
        ]);
    }

    private function patient(): Patient
    {
        $user = User::factory()->role(RoleName::Patient)->create();

        return $user->patient()->create([
            'date_of_birth' => '1990-01-15',
            'sex' => Sex::Female,
            'contact_number' => '09170000000',
        ]);
    }
}
