<?php

namespace Tests\Feature\Admin;

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\ClinicStatus;
use App\Enums\DayOfWeek;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorScheduleStatus;
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
use App\Services\Ai\AiSuggestedDoctorService;
use App\Services\AiHospitalContextService;
use App\Services\HospitalImport\HospitalScheduleImportService;
use App\Services\HospitalImport\RecordProvenanceService;
use App\Support\AiGuidanceResult;
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
use Tests\TestCase;

class AdminHospitalClassificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05 08:00:00');
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

    public function test_it_admin_creates_are_classified_as_hospital_provided_without_verification(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.departments.store'), [
                'name' => 'Outpatient Services',
                'description' => 'Hospital department.',
                'status' => DepartmentStatus::Active->value,
            ])
            ->assertRedirect(route('admin.departments'));

        $department = Department::query()->where('name', 'Outpatient Services')->firstOrFail();
        $this->assertSame(HospitalDataSource::Imported, $department->data_source);
        $this->assertNull($department->verified_at);
        $this->assertNull($department->verified_by_user_id);

        $this->actingAs($admin)
            ->post(route('admin.clinics.store'), [
                'name' => 'Family Medicine',
                'department_id' => $department->id,
                'description' => 'Hospital clinic.',
                'status' => ClinicStatus::Active->value,
            ])
            ->assertRedirect(route('admin.clinics'));

        $clinic = Clinic::query()->where('name', 'Family Medicine')->firstOrFail();
        $this->assertSame(HospitalDataSource::Imported, $clinic->data_source);
        $this->assertNull($clinic->verified_at);

        $this->actingAs($admin)
            ->post(route('admin.doctors.store'), [
                'display_name' => 'Dr. Reyes',
                'clinic_id' => $clinic->id,
                'specialization' => 'Family Medicine',
                'status' => DoctorStatus::Active->value,
                'first_name' => 'Ana',
                'last_name' => 'Reyes',
                'email' => 'ana.reyes@mediguide.test',
                'password' => 'MediGuide@Doc1',
                'password_confirmation' => 'MediGuide@Doc1',
            ])
            ->assertRedirect(route('admin.doctors'));

        $doctor = Doctor::query()->where('display_name', 'Dr. Reyes')->firstOrFail();
        $this->assertSame(HospitalDataSource::Imported, $doctor->data_source);
        $this->assertNull($doctor->verified_at);
        $this->assertSame(0, $doctor->schedules()->count());
        $this->assertSame(1, AuditEvent::query()->where('action', AuditAction::DepartmentCreated->value)->count());
        $this->assertSame(1, AuditEvent::query()->where('action', AuditAction::ClinicCreated->value)->count());
        $this->assertSame(1, AuditEvent::query()->where('action', AuditAction::DoctorCreated->value)->count());
    }

    public function test_browser_submitted_provenance_cannot_override_server_classification(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.departments.store'), [
                'name' => 'Sandbox Department',
                'status' => DepartmentStatus::Active->value,
                'data_source' => HospitalDataSource::Development->value,
                'verified_at' => now()->toDateTimeString(),
                'verified_by_user_id' => $admin->id,
            ])
            ->assertSessionHasErrors(['data_source', 'verified_at', 'verified_by_user_id']);

        $this->assertNull(Department::query()->where('name', 'Sandbox Department')->first());

        $this->actingAs($admin)
            ->post(route('admin.departments.store'), [
                'name' => 'Forged Verified',
                'status' => DepartmentStatus::Active->value,
                'data_source' => HospitalDataSource::Verified->value,
            ])
            ->assertSessionHasErrors('data_source');
    }

    public function test_unauthorized_roles_cannot_create_hospital_records(): void
    {
        $payload = [
            'name' => 'Blocked Department',
            'status' => DepartmentStatus::Active->value,
        ];

        $this->post(route('admin.departments.store'), $payload)
            ->assertRedirect(route('login'));

        foreach ([RoleName::Patient, RoleName::HospitalStaff, RoleName::Doctor] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->post(route('admin.departments.store'), $payload)
                ->assertForbidden();
        }
    }

    public function test_verification_http_routes_are_not_exposed(): void
    {
        $admin = $this->admin();
        $department = $this->hospitalDepartment();

        $this->actingAs($admin)
            ->patch('/admin/departments/'.$department->id.'/verification', ['action' => 'verify'])
            ->assertNotFound();

        $this->assertSame(HospitalDataSource::Imported, $department->fresh()->data_source);
        $this->assertNull($department->fresh()->verified_at);
    }

    public function test_internal_verification_still_records_actor_and_revokes_on_clinical_edits(): void
    {
        $admin = $this->admin();
        $department = $this->hospitalDepartment();
        $this->actingAs($admin);

        app(RecordProvenanceService::class)->verify($department, $admin);
        $department->refresh();
        $this->assertSame(HospitalDataSource::Verified, $department->data_source);
        $this->assertNotNull($department->verified_at);
        $this->assertSame($admin->id, $department->verified_by_user_id);

        app(RecordProvenanceService::class)->revoke($department, $admin);
        $department->refresh();
        $this->assertSame(HospitalDataSource::Imported, $department->data_source);
        $this->assertNull($department->verified_at);

        app(RecordProvenanceService::class)->verify($department, $admin);
        $this->actingAs($admin)
            ->patch(route('admin.departments.update', $department), [
                'name' => 'Renamed Outpatient',
                'description' => $department->description,
                'status' => DepartmentStatus::Active->value,
            ])
            ->assertRedirect(route('admin.departments'));

        $department->refresh();
        $this->assertSame(HospitalDataSource::Imported, $department->data_source);
        $this->assertNull($department->verified_at);
        $this->assertSame(2, AuditEvent::query()->where('action', AuditAction::RecordVerified->value)->count());
        $this->assertGreaterThanOrEqual(1, AuditEvent::query()->where('action', AuditAction::RecordVerificationRevoked->value)->count());
    }

    public function test_parent_child_provenance_and_ai_eligibility_modes(): void
    {
        $admin = $this->admin();
        $development = Department::query()->where('name', 'Development Department A')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.clinics.store'), [
                'name' => 'Invalid Provided Clinic',
                'department_id' => $development->id,
                'status' => ClinicStatus::Active->value,
            ])
            ->assertSessionHasErrors('department_id');

        $department = $this->hospitalDepartment();
        $clinic = $this->hospitalClinic($department);
        $doctor = $this->hospitalDoctor($clinic);
        $developmentDoctor = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();

        config(['hospital.data_source' => HospitalDataSource::Imported->value]);
        $importedContext = app(AiHospitalContextService::class);
        $this->assertTrue($importedContext->eligibleDoctorsForSpecialization('Family Medicine')->contains('id', $doctor->id));
        $this->assertFalse($importedContext->eligibleDoctorsForSpecialization(DevelopmentDoctorSeeder::SPECIALIZATION_A)->contains('id', $developmentDoctor->id));

        $doctor->update(['status' => DoctorStatus::Inactive]);
        $this->assertFalse($importedContext->eligibleDoctorsForSpecialization('Family Medicine')->contains('id', $doctor->id));
        $doctor->update(['status' => DoctorStatus::Active]);

        $clinic->update(['status' => ClinicStatus::Inactive]);
        $this->assertNull($this->clinicInContext($clinic->id));
        $clinic->update(['status' => ClinicStatus::Active]);

        $department->update(['status' => DepartmentStatus::Inactive]);
        $this->assertNull($this->clinicInContext($clinic->id));
        $department->update(['status' => DepartmentStatus::Active]);

        $this->actingAs($admin);
        app(RecordProvenanceService::class)->verify($department, $admin);
        app(RecordProvenanceService::class)->verify($clinic, $admin);
        app(RecordProvenanceService::class)->verify($doctor, $admin);

        config(['hospital.data_source' => HospitalDataSource::Verified->value]);
        $verifiedContext = app(AiHospitalContextService::class);
        $this->assertTrue($verifiedContext->eligibleDoctorsForSpecialization('Family Medicine')->contains('id', $doctor->fresh()->id));
        $this->assertFalse($verifiedContext->eligibleDoctorsForSpecialization(DevelopmentDoctorSeeder::SPECIALIZATION_A)->contains('id', $developmentDoctor->id));

        config(['hospital.data_source' => HospitalDataSource::Development->value]);
        $this->assertTrue(
            app(AiHospitalContextService::class)
                ->eligibleDoctorsForSpecialization(DevelopmentDoctorSeeder::SPECIALIZATION_A)
                ->contains('id', $developmentDoctor->id),
        );
    }

    public function test_existing_imported_records_remain_eligible_and_do_not_invent_slots(): void
    {
        app(HospitalScheduleImportService::class)->execute();
        config(['hospital.data_source' => HospitalDataSource::Imported->value]);

        $context = app(AiHospitalContextService::class)->availableContext();
        $service = collect($context->services)->first(fn ($item): bool => $item->specialization === 'Pediatrician');
        $this->assertNotNull($service);

        $cards = app(AiSuggestedDoctorService::class)->forGuidance(AiGuidanceResult::fromPayload([
            'type' => 'recommendation',
            'service_id' => $service->serviceId,
            'specialization' => 'Pediatrician',
            'reason' => 'The stored Pediatrician service matches the concern.',
        ]));

        $this->assertContains('Dr. Endaya, Hazel Anne', array_column($cards['doctors'], 'display_name'));
        $this->assertNotContains(true, array_column($cards['doctors'], 'bookable'));
        $this->assertSame(QueenMaryClinicScheduleCatalog::PENDING_DEPARTMENT_NAME, Department::query()->where('data_source', HospitalDataSource::Imported->value)->value('name'));
        $this->assertSame(HospitalDataSource::Imported, Doctor::query()->where('display_name', 'Dr. Endaya, Hazel Anne')->first()?->data_source);
        $this->assertNotSame(HospitalDataSource::Verified, Doctor::query()->where('display_name', 'Dr. Endaya, Hazel Anne')->first()?->data_source);
    }

    public function test_historical_appointments_and_integrity_survive_classification(): void
    {
        $doctor = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();
        $appointment = $this->appointmentFor($doctor);
        $count = Appointment::query()->count();

        $this->actingAs($this->admin())
            ->post(route('admin.departments.store'), [
                'name' => 'Records Office',
                'status' => DepartmentStatus::Active->value,
            ])
            ->assertRedirect(route('admin.departments'));

        $appointment->refresh();
        $this->assertSame($count, Appointment::query()->count());
        $this->assertSame($doctor->id, $appointment->doctor_id);
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->status);
        $this->assertSame(IntegrityStatus::Valid, app(RecordIntegrityService::class)->statusOf($appointment));
    }

    public function test_forms_do_not_show_data_source_or_verification_controls(): void
    {
        $html = $this->actingAs($this->admin())
            ->get(route('admin.departments.create'))
            ->assertOk()
            ->assertDontSee('Data Source')
            ->assertDontSee('Hospital Provided')
            ->assertDontSee('Hospital Verified')
            ->assertDontSee('Mark as Hospital Verified')
            ->assertDontSee('Remove verification')
            ->getContent();

        $this->assertStringContainsString('mg-staff-schedule-grid', $html);
        $this->assertStringNotContainsString('<table', $html);

        $department = $this->hospitalDepartment();
        $this->actingAs($this->admin())
            ->get(route('admin.departments.edit', $department))
            ->assertOk()
            ->assertDontSee('Data Source')
            ->assertDontSee('Mark as Hospital Verified')
            ->assertSee('Save Changes');

        $this->actingAs($this->admin())
            ->get(route('admin.clinics.create'))
            ->assertOk()
            ->assertDontSee('Data Source')
            ->assertDontSee('Mark as Hospital Verified');

        $this->actingAs($this->admin())
            ->get(route('admin.doctors.create'))
            ->assertOk()
            ->assertDontSee('Data Source')
            ->assertDontSee('Mark as Hospital Verified');
    }

    private function clinicInContext(int $clinicId): mixed
    {
        foreach (app(AiHospitalContextService::class)->availableContext()->clinics as $clinic) {
            if ($clinic->clinicId === $clinicId) {
                return $clinic;
            }
        }

        return null;
    }

    private function hospitalDepartment(): Department
    {
        return Department::query()->create([
            'name' => 'Outpatient Services',
            'description' => 'Hospital department.',
            'status' => DepartmentStatus::Active,
            'data_source' => HospitalDataSource::Imported,
        ]);
    }

    private function hospitalClinic(Department $department): Clinic
    {
        return Clinic::query()->create([
            'department_id' => $department->id,
            'name' => 'Family Medicine',
            'description' => 'Hospital clinic.',
            'status' => ClinicStatus::Active,
            'data_source' => HospitalDataSource::Imported,
        ]);
    }

    private function hospitalDoctor(Clinic $clinic): Doctor
    {
        return Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'display_name' => 'Dr. Reyes',
            'specialization' => 'Family Medicine',
            'status' => DoctorStatus::Active,
            'data_source' => HospitalDataSource::Imported,
        ]);
    }

    private function appointmentFor(Doctor $doctor): Appointment
    {
        $schedule = DoctorSchedule::query()->where('doctor_id', $doctor->id)->first()
            ?? DoctorSchedule::query()->create([
                'doctor_id' => $doctor->id,
                'day_of_week' => DayOfWeek::Monday,
                'start_time' => '09:00:00',
                'end_time' => '12:00:00',
                'slot_duration' => 30,
                'status' => DoctorScheduleStatus::Active,
            ]);

        $user = User::factory()->role(RoleName::Patient)->create();
        $user->patient()->create([
            'date_of_birth' => '1992-04-01',
            'sex' => Sex::Female,
            'contact_number' => '09170000031',
        ]);

        return Appointment::query()->create([
            'patient_id' => $user->patient->id,
            'doctor_id' => $doctor->id,
            'doctor_schedule_id' => $schedule->id,
            'appointment_date' => '2026-10-12',
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'status' => AppointmentStatus::Confirmed,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->role(RoleName::ItAdministrator)->create();
    }
}
