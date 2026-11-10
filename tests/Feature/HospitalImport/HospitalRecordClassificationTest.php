<?php

namespace Tests\Feature\HospitalImport;

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\DoctorStatus;
use App\Enums\HospitalDataSource;
use App\Enums\RoleName;
use App\Enums\Sex;
use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\User;
use App\Services\AiHospitalContextService;
use App\Services\HospitalImport\HospitalRecordClassificationService;
use App\Services\HospitalImport\HospitalScheduleImportService;
use App\Services\HospitalImport\RecordProvenanceService;
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
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HospitalRecordClassificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_invalid_provenance_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        HospitalDataSource::parse('poster');
    }

    public function test_records_are_not_promoted_to_verified_automatically(): void
    {
        $this->seedDevelopment();
        $doctor = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();

        try {
            app(RecordProvenanceService::class)->assign($doctor, HospitalDataSource::Verified);
            $this->fail('Verified promotion should have been rejected.');
        } catch (ValidationException) {
        }

        $this->assertSame(HospitalDataSource::Development, $doctor->fresh()->data_source);
    }

    public function test_dry_run_does_not_write_provenance(): void
    {
        app(HospitalScheduleImportService::class)->execute();
        Department::query()->update(['data_source' => HospitalDataSource::Development->value]);
        Clinic::query()->update(['data_source' => HospitalDataSource::Development->value]);
        Doctor::query()->update(['data_source' => HospitalDataSource::Development->value]);

        $summary = app(HospitalRecordClassificationService::class)->preview();

        $this->assertTrue($summary['dry_run']);
        $this->assertSame(0, $summary['updated']);
        $this->assertSame(HospitalDataSource::Development, Doctor::query()->first()?->data_source);
        $this->artisan('mediguide:classify-hospital-records')
            ->expectsOutputToContain('DRY RUN');
    }

    public function test_catalog_mapping_classifies_imported_records_and_leaves_development_rows(): void
    {
        $this->seedDevelopment();
        app(HospitalScheduleImportService::class)->execute();
        Department::query()->update(['data_source' => HospitalDataSource::Development->value]);
        Clinic::query()->update(['data_source' => HospitalDataSource::Development->value]);
        Doctor::query()->update(['data_source' => HospitalDataSource::Development->value]);

        $ortho = Clinic::query()->where('name', 'Ortho')->first();
        $robles = Doctor::query()->where('display_name', 'Dr. Robles')->first();

        $summary = app(HospitalRecordClassificationService::class)->execute();

        $this->assertFalse($summary['dry_run']);
        $this->assertGreaterThan(0, $summary['updated']);
        $this->assertSame(
            HospitalDataSource::Imported,
            Department::query()->where('name', QueenMaryClinicScheduleCatalog::PENDING_DEPARTMENT_NAME)->first()?->data_source,
        );
        $this->assertSame(
            HospitalDataSource::Imported,
            Doctor::query()->where('display_name', 'Dr. Acosta, Emil Rommel')->first()?->data_source,
        );
        $this->assertSame(HospitalDataSource::Development, Doctor::query()->where('display_name', 'Test Doctor 1')->first()?->data_source);
        $this->assertTrue($robles === null || $robles->fresh()->data_source === HospitalDataSource::Development);
        $this->assertTrue($ortho === null || $ortho->fresh()->data_source === HospitalDataSource::Development);
        $this->assertSame(0, Doctor::query()->where('data_source', HospitalDataSource::Verified->value)->count());
        $this->assertGreaterThan(
            0,
            AuditEvent::query()->where('action', AuditAction::RecordProvenanceChanged->value)->count(),
        );

        $repeat = app(HospitalRecordClassificationService::class)->execute();
        $this->assertSame(0, $repeat['updated']);
    }

    public function test_classification_preserves_historical_appointments_and_integrity(): void
    {
        $this->seedDevelopment();
        $patient = $this->patientUser();
        $doctor = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();
        $appointment = $this->appointmentFor($patient, $doctor);
        $count = Appointment::query()->count();
        $doctorId = $appointment->doctor_id;
        $scheduleId = $appointment->doctor_schedule_id;
        $status = $appointment->status;

        app(HospitalScheduleImportService::class)->execute();
        app(HospitalRecordClassificationService::class)->execute();

        $appointment->refresh();
        $this->assertSame($count, Appointment::query()->count());
        $this->assertSame($doctorId, $appointment->doctor_id);
        $this->assertSame($scheduleId, $appointment->doctor_schedule_id);
        $this->assertSame($status, $appointment->status);
        $this->assertSame(IntegrityStatus::Valid, app(RecordIntegrityService::class)->statusOf($appointment));
        $this->assertTrue(DoctorSchedule::query()->whereKey($scheduleId)->exists());
        $this->assertTrue(app(AuditVerificationService::class)->verify()['passed']);
    }

    public function test_imported_mode_grounding_uses_classified_records_only(): void
    {
        $this->seedDevelopment();
        app(HospitalScheduleImportService::class)->execute();
        Department::query()->update(['data_source' => HospitalDataSource::Development->value]);
        Clinic::query()->update(['data_source' => HospitalDataSource::Development->value]);
        Doctor::query()->update(['data_source' => HospitalDataSource::Development->value]);
        app(HospitalRecordClassificationService::class)->execute();
        config(['hospital.data_source' => HospitalDataSource::Imported->value]);

        $context = app(AiHospitalContextService::class)->availableContext();
        $names = array_map(fn ($clinic) => $clinic->clinicName, $context->clinics);
        $specializations = array_map(fn ($service) => $service->specialization, $context->services);

        $this->assertNotContains('Ortho', $names);
        $this->assertNotContains('Development Clinic A', $names);
        $this->assertContains('Pediatrician', $names);
        $this->assertContains('Pediatrician', $specializations);
        $this->assertFalse(
            app(AiHospitalContextService::class)
                ->eligibleDoctorsForSpecialization(DevelopmentDoctorSeeder::SPECIALIZATION_A)
                ->contains(fn (Doctor $doctor): bool => $doctor->display_name === 'Test Doctor 1'),
        );
        $this->assertCount(12, $specializations);
        $this->assertSame(16, Doctor::query()->where('data_source', HospitalDataSource::Imported->value)->count());
        $this->assertFalse(
            app(AiHospitalContextService::class)
                ->eligibleDoctorsForSpecialization('Ortho')
                ->contains(fn (Doctor $doctor): bool => $doctor->display_name === 'Dr. Robles'),
        );
    }

    public function test_it_admin_can_still_edit_classified_doctors(): void
    {
        app(HospitalScheduleImportService::class)->execute();
        $admin = User::factory()->role(RoleName::ItAdministrator)->create();
        $doctor = Doctor::query()->where('display_name', 'Dr. Acosta, Emil Rommel')->firstOrFail();

        $this->actingAs($admin)
            ->patch(route('admin.doctors.update', $doctor), [
                'display_name' => 'Dr. Acosta, Emil Rommel',
                'clinic_id' => $doctor->clinic_id,
                'specialization' => 'Otolaryngologist (Ears, Nose, Throat)',
                'status' => DoctorStatus::Active->value,
            ])
            ->assertRedirect(route('admin.doctors'));

        $this->assertSame('Dr. Acosta, Emil Rommel', $doctor->fresh()->display_name);
        $this->assertSame(HospitalDataSource::Imported, $doctor->fresh()->data_source);
        $this->assertSame(0, DoctorSchedule::query()->where('doctor_id', $doctor->id)->count());
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
            'email' => 'classify-patient@example.com',
        ]);
        $user->patient()->create([
            'date_of_birth' => '1990-01-01',
            'sex' => Sex::Female,
            'contact_number' => '09170000992',
        ]);

        return $user->fresh(['patient']);
    }

    private function appointmentFor(User $patient, Doctor $doctor): Appointment
    {
        $schedule = DoctorSchedule::query()->where('doctor_id', $doctor->id)->orderBy('id')->firstOrFail();

        return Appointment::query()->create([
            'patient_id' => $patient->patient->id,
            'doctor_id' => $doctor->id,
            'doctor_schedule_id' => $schedule->id,
            'appointment_date' => '2026-10-12',
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'patient_concern' => 'Keep historical visit',
            'status' => AppointmentStatus::Pending,
        ]);
    }
}
