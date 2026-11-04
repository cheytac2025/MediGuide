<?php

namespace Tests\Feature\HospitalImport;

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
use App\Services\Ai\AiSuggestedDoctorService;
use App\Services\AiHospitalContextService;
use App\Services\AppointmentBookingService;
use App\Services\HospitalImport\HospitalScheduleImportService;
use App\Support\AiDisclaimer;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HospitalScheduleImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-05 08:00:00', 'Asia/Manila'));
        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dry_run_previews_source_records_without_writing(): void
    {
        $summary = app(HospitalScheduleImportService::class)->preview(30, true);

        $this->assertTrue($summary['dry_run']);
        $this->assertSame('pending_hospital_verification', $summary['verification']);
        $this->assertCount(16, $summary['doctors']);
        $this->assertCount(12, $summary['clinics']);
        $this->assertCount(21, $summary['schedules']);
        $this->assertCount(4, $summary['appointment_only']);
        $this->assertCount(3, $summary['ambiguous']);
        $this->assertSame(0, Doctor::query()->count());
        $this->assertSame(0, Clinic::query()->count());
        $this->assertSame(0, Department::query()->count());
        $this->assertSame(0, DoctorSchedule::query()->count());
    }

    public function test_artisan_command_defaults_to_dry_run(): void
    {
        $this->artisan('mediguide:import-hospital-schedules')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertSame(0, Doctor::query()->count());
    }

    public function test_import_creates_doctors_specializations_and_fixed_schedules(): void
    {
        $summary = app(HospitalScheduleImportService::class)->execute(30);

        $this->assertFalse($summary['dry_run']);
        $this->assertSame(16, $summary['created']['doctors']);
        $this->assertSame(12, $summary['created']['clinics']);
        $this->assertSame(1, $summary['created']['departments']);
        $this->assertSame(21, $summary['created']['schedules']);

        $acosta = Doctor::query()->where('display_name', 'Dr. Acosta, Emil Rommel')->firstOrFail();
        $this->assertSame('Otolaryngologist (Ears, Nose, Throat)', $acosta->specialization);
        $this->assertNull($acosta->user_id);
        $this->assertSame(DoctorStatus::Active, $acosta->status);
        $this->assertSame(HospitalDataSource::Imported, $acosta->data_source);
        $this->assertSame(HospitalDataSource::Imported, $acosta->clinic->data_source);
        $this->assertSame(HospitalDataSource::Imported, $acosta->clinic->department->data_source);
        $this->assertNotSame(HospitalDataSource::Verified, $acosta->data_source);
        $this->assertSame('Otolaryngologist (Ears, Nose, Throat)', $acosta->clinic->name);
        $this->assertSame(QueenMaryClinicScheduleCatalog::PENDING_DEPARTMENT_NAME, $acosta->clinic->department->name);

        $this->assertDatabaseHas('doctor_schedules', [
            'doctor_id' => $acosta->id,
            'day_of_week' => DayOfWeek::Thursday->value,
            'start_time' => '15:00:00',
            'end_time' => '16:00:00',
            'slot_duration' => 30,
            'status' => DoctorScheduleStatus::Active->value,
        ]);

        $cadiente = Doctor::query()->where('display_name', 'Dr. Cadiente, Gilbert')->firstOrFail();
        $this->assertSame(3, $cadiente->schedules()->count());
        $this->assertSame('Internal Medicine', $cadiente->specialization);
        $this->assertNotSame(
            Doctor::query()->where('display_name', 'Dr. Balajadia, Ma. Imelda')->value('clinic_id'),
            $cadiente->clinic_id,
        );
    }

    public function test_missing_doctor_information_is_skipped(): void
    {
        $service = new HospitalScheduleImportService([
            [
                'display_name' => '',
                'specialization' => 'Pediatrician',
                'booking_mode' => 'fixed',
                'schedules' => [],
            ],
            [
                'display_name' => 'Dr. Incomplete',
                'specialization' => '',
                'booking_mode' => 'fixed',
                'schedules' => [],
            ],
        ]);

        $summary = $service->execute(30);

        $this->assertCount(2, $summary['skipped']);
        $this->assertSame(0, Doctor::query()->count());
    }

    public function test_ambiguous_and_appointment_only_rows_are_flagged_and_not_made_bookable(): void
    {
        $summary = app(HospitalScheduleImportService::class)->execute(30);

        $names = array_column($summary['appointment_only'], 'display_name');
        $this->assertContains('Dr. Eclipse Jr., Eulalio', $names);
        $this->assertContains('Dr. Enriquez, Tyron Vincent P.', $names);
        $this->assertContains('Dr. Saquitan-Epetia, Angeli', $names);
        $this->assertContains('Dr. Epetia, Mar Christopher', $names);

        $eclipse = Doctor::query()->where('display_name', 'Dr. Eclipse Jr., Eulalio')->firstOrFail();
        $this->assertSame(0, $eclipse->schedules()->count());
        $this->assertNull(app(AppointmentBookingService::class)->nextAvailableSlot($eclipse));

        $ambiguous = array_column($summary['ambiguous'], 'display_name');
        $this->assertContains('Dr. De Jesus, Mediatrix', $ambiguous);
        $this->assertContains('Dr. Eclipse Jr., Eulalio', $ambiguous);
        $this->assertContains('Dr. Enriquez, Tyron Vincent P.', $ambiguous);
    }

    public function test_duplicate_prevention_and_repeated_import_are_idempotent(): void
    {
        $service = app(HospitalScheduleImportService::class);
        $first = $service->execute(30);
        $doctors = Doctor::query()->count();
        $schedules = DoctorSchedule::query()->count();
        $clinics = Clinic::query()->count();

        $second = $service->execute(30);

        $this->assertSame(16, $first['created']['doctors']);
        $this->assertSame(0, $second['created']['doctors']);
        $this->assertSame(0, $second['created']['schedules']);
        $this->assertCount(16, $second['duplicates']);
        $this->assertSame($doctors, Doctor::query()->count());
        $this->assertSame($schedules, DoctorSchedule::query()->count());
        $this->assertSame($clinics, Clinic::query()->count());
        $this->assertSame(0, Doctor::query()->whereNotNull('user_id')->count());
    }

    public function test_department_and_clinic_mappings_are_inferred_and_not_official(): void
    {
        $summary = app(HospitalScheduleImportService::class)->preview();

        $this->assertSame('inferred', $summary['departments'][0]['mapping']);
        $this->assertSame('inferred_from_specialization', $summary['clinics'][0]['mapping']);
        $this->assertSame(QueenMaryClinicScheduleCatalog::PENDING_DEPARTMENT_NAME, $summary['departments'][0]['name']);
    }

    public function test_development_cleanup_preserves_historical_appointments_and_integrity(): void
    {
        $this->seedDevelopment();
        $patient = $this->patientUser();
        $doctor = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();
        $appointment = $this->appointmentFor($patient, $doctor, 'Keep this visit');
        $hmac = app(RecordIntegrityService::class)->statusOf($appointment);
        $this->assertSame(IntegrityStatus::Valid, $hmac);

        $summary = app(HospitalScheduleImportService::class)->execute(30, true);

        $appointment->refresh();
        $this->assertSame($doctor->id, $appointment->doctor_id);
        $this->assertSame('Keep this visit', $appointment->patient_concern);
        $this->assertSame(IntegrityStatus::Valid, app(RecordIntegrityService::class)->statusOf($appointment));
        $this->assertSame(DoctorStatus::Inactive, $doctor->fresh()->status);
        $this->assertGreaterThan(0, $summary['deactivated']['doctors']);
        $this->assertContains('Test Doctor 1', Doctor::query()->pluck('display_name'));
    }

    public function test_audit_chain_remains_valid_after_import(): void
    {
        app(HospitalScheduleImportService::class)->execute(30);

        $result = app(AuditVerificationService::class)->verify();

        $this->assertTrue($result['passed']);
        $this->assertGreaterThan(0, $result['valid']);
        $this->assertSame(0, $result['invalid']);
    }

    public function test_inactive_imported_doctors_clinics_and_departments_are_excluded(): void
    {
        app(HospitalScheduleImportService::class)->execute(30);
        config(['hospital.data_source' => HospitalDataSource::Imported->value]);

        $pediatrician = Doctor::query()->where('display_name', 'Dr. Endaya, Hazel Anne')->firstOrFail();
        $context = app(AiHospitalContextService::class);
        $this->assertNotEmpty($context->eligibleDoctorsForSpecialization('Pediatrician'));

        $pediatrician->update(['status' => DoctorStatus::Inactive]);
        $remaining = $context->eligibleDoctorsForSpecialization('Pediatrician');
        $this->assertFalse($remaining->contains(fn (Doctor $doctor): bool => $doctor->id === $pediatrician->id));

        $clinic = Clinic::query()->where('name', 'Pediatrician')->firstOrFail();
        $clinic->update(['status' => ClinicStatus::Inactive]);
        $this->assertTrue($context->eligibleDoctorsForSpecialization('Pediatrician')->isEmpty());
        $this->assertFalse($context->availableContext()->includesClinic($clinic->id));

        Clinic::query()->where('name', 'Pediatrician')->update(['status' => ClinicStatus::Active->value]);
        Department::query()
            ->where('name', QueenMaryClinicScheduleCatalog::PENDING_DEPARTMENT_NAME)
            ->update(['status' => DepartmentStatus::Inactive->value]);

        $this->assertSame([], $context->availableContext()->toArray()['clinics']);
        $this->assertTrue($context->eligibleDoctorsForSpecialization('Internal Medicine')->isEmpty());
    }

    public function test_imported_fixed_hours_are_available_and_appointment_only_are_not(): void
    {
        app(HospitalScheduleImportService::class)->execute(30);

        $cadiente = Doctor::query()->where('display_name', 'Dr. Cadiente, Gilbert')->firstOrFail();
        $slot = app(AppointmentBookingService::class)->nextAvailableSlot($cadiente);
        $this->assertIsArray($slot);
        $this->assertSame('2026-10-05', $slot['date']);
        $this->assertSame('11:00', $slot['time']);

        $strict = Doctor::query()->where('display_name', 'Dr. Saquitan-Epetia, Angeli')->firstOrFail();
        $this->assertNull(app(AppointmentBookingService::class)->nextAvailableSlot($strict));
    }

    public function test_ai_grounding_suggested_doctors_and_booking_handoff_use_imported_records(): void
    {
        app(HospitalScheduleImportService::class)->execute(30);
        config(['hospital.data_source' => HospitalDataSource::Imported->value]);

        $context = app(AiHospitalContextService::class)->availableContext();
        $service = collect($context->services)->first(
            fn ($item): bool => $item->specialization === 'Pediatrician',
        );
        $this->assertNotNull($service);

        $guidance = AiGuidanceResult::fromPayload([
            'type' => 'recommendation',
            'service_id' => $service->serviceId,
            'specialization' => 'Pediatrician',
            'reason' => 'The stored Pediatrician service matches the concern.',
        ]);

        $cards = app(AiSuggestedDoctorService::class)->forGuidance($guidance);
        $names = array_column($cards['doctors'], 'display_name');
        $this->assertContains('Dr. Arada-Hocate, Maria Nickle', $names);
        $this->assertContains('Dr. Endaya, Hazel Anne', $names);
        $this->assertNotContains('Dr. Juan', $names);
        $this->assertNotContains('Test Doctor 1', $names);

        $arada = Doctor::query()->where('display_name', 'Dr. Arada-Hocate, Maria Nickle')->firstOrFail();
        $this->assertTrue($cards['doctors'][0]['bookable'] || $cards['doctors'][1]['bookable']);

        $patient = $this->patientUser();
        $this->actingAs($patient)
            ->withSession([AiDisclaimer::SESSION_KEY => true])
            ->post(route('ai-front-desk.booking-intent'), [
                'clinic_id' => $arada->clinic_id,
                'doctor_id' => $arada->id,
            ])
            ->assertRedirect(route('patient.book-appointment', [
                'clinic_id' => $arada->clinic_id,
                'doctor_id' => $arada->id,
            ]));
    }

    public function test_it_admin_can_manage_imported_doctors_and_staff_remain_department_scoped(): void
    {
        app(HospitalScheduleImportService::class)->execute(30);

        $admin = User::factory()->role(RoleName::ItAdministrator)->create();
        $imported = Doctor::query()->where('display_name', 'Dr. Acosta, Emil Rommel')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('admin.doctors'))
            ->assertOk()
            ->assertSee('Dr. Acosta, Emil Rommel');

        $this->actingAs($admin)
            ->patch(route('admin.doctors.update', $imported), [
                'display_name' => $imported->display_name,
                'clinic_id' => $imported->clinic_id,
                'specialization' => 'Otolaryngologist (Ears, Nose, Throat)',
                'status' => DoctorStatus::Inactive->value,
            ])
            ->assertRedirect(route('admin.doctors'));

        $this->assertSame(DoctorStatus::Inactive, $imported->fresh()->status);

        $this->actingAs($admin)
            ->patch(route('admin.doctors.status', $imported), [
                'status' => DoctorStatus::Active->value,
            ]);

        $pending = Department::query()
            ->where('name', QueenMaryClinicScheduleCatalog::PENDING_DEPARTMENT_NAME)
            ->firstOrFail();
        $other = Department::query()->create([
            'name' => 'Development Department A',
            'status' => DepartmentStatus::Active,
        ]);

        $assigned = User::factory()->role(RoleName::HospitalStaff)->create([
            'email' => 'import-staff@example.com',
        ]);
        $assigned->hospitalStaff()->create()->departments()->sync([$pending->id]);

        $outsider = User::factory()->role(RoleName::HospitalStaff)->create([
            'email' => 'other-staff@example.com',
        ]);
        $outsider->hospitalStaff()->create()->departments()->sync([$other->id]);

        $this->actingAs($assigned)
            ->get(route('staff.doctor-schedules'))
            ->assertOk()
            ->assertSee('Dr. Acosta, Emil Rommel');

        $this->actingAs($outsider)
            ->get(route('staff.doctor-schedules'))
            ->assertOk()
            ->assertDontSee('Dr. Acosta, Emil Rommel');

        $this->actingAs($outsider)
            ->get(route('staff.doctor-schedules.show', $imported))
            ->assertNotFound();
    }

    public function test_import_does_not_invent_doctors_or_store_secretary_numbers(): void
    {
        app(HospitalScheduleImportService::class)->execute(30);

        $names = Doctor::query()->pluck('display_name')->all();
        $expected = array_column(QueenMaryClinicScheduleCatalog::doctors(), 'display_name');
        sort($names);
        sort($expected);
        $this->assertSame($expected, $names);

        $payload = json_encode([
            Doctor::query()->get()->toArray(),
            Clinic::query()->get()->toArray(),
        ], JSON_THROW_ON_ERROR);

        $this->assertDoesNotMatchRegularExpression('/09\d{2}/', $payload);
        $this->assertStringNotContainsString('Dr. Juan', $payload);
        $this->assertNull(Doctor::query()->whereNotNull('user_id')->first());
    }

    public function test_fixed_hours_without_approved_slot_duration_are_held(): void
    {
        $summary = app(HospitalScheduleImportService::class)->execute();

        $this->assertSame(16, Doctor::query()->count());
        $this->assertSame(0, DoctorSchedule::query()->count());
        $this->assertSame('hold_pending_slot_duration', $summary['schedules'][0]['action']);
    }

    private function seedDevelopment(): void
    {
        $this->seed(DevelopmentDepartmentSeeder::class);
        $this->seed(DevelopmentClinicSeeder::class);
        $this->seed(DevelopmentDoctorSeeder::class);
        $this->seed(DevelopmentDoctorScheduleSeeder::class);
    }

    private function patientUser(): User
    {
        $user = User::factory()->role(RoleName::Patient)->create([
            'email' => 'import-patient@example.com',
        ]);
        $user->patient()->create([
            'date_of_birth' => '1990-01-01',
            'sex' => Sex::Female,
            'contact_number' => '09170000991',
        ]);

        return $user->fresh(['patient']);
    }

    private function appointmentFor(User $patient, Doctor $doctor, string $concern): Appointment
    {
        $schedule = DoctorSchedule::query()->where('doctor_id', $doctor->id)->orderBy('id')->firstOrFail();

        return Appointment::query()->create([
            'patient_id' => $patient->patient->id,
            'doctor_id' => $doctor->id,
            'doctor_schedule_id' => $schedule->id,
            'appointment_date' => '2026-10-12',
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'patient_concern' => $concern,
            'status' => AppointmentStatus::Pending,
        ]);
    }
}
