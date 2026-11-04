<?php

namespace Tests\Feature\Patient;

use App\Enums\AiGuidanceType;
use App\Enums\ClinicStatus;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorStatus;
use App\Enums\HospitalDataSource;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Services\AiHospitalContextService;
use App\Support\AiClinicContext;
use App\Support\AiGuidanceResult;
use App\Support\AiHospitalContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class AiHospitalContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_clinic_under_active_department_is_included(): void
    {
        $department = $this->department('Development Department A', DepartmentStatus::Active);
        $clinic = $this->clinic($department, 'Development Clinic A', ClinicStatus::Active);

        $context = $this->service()->availableContext();

        $this->assertNotNull($this->clinicInContext($context, $clinic->id));
        $this->assertSame([$clinic->id], $context->allowedClinicIds());
    }

    public function test_inactive_clinic_is_excluded(): void
    {
        $department = $this->department('Development Department A', DepartmentStatus::Active);
        $active = $this->clinic($department, 'Development Clinic A', ClinicStatus::Active);
        $inactive = $this->clinic($department, 'Development Clinic Inactive', ClinicStatus::Inactive);

        $context = $this->service()->availableContext();

        $this->assertNotNull($this->clinicInContext($context, $active->id));
        $this->assertNull($this->clinicInContext($context, $inactive->id));
    }

    public function test_clinic_under_inactive_department_is_excluded(): void
    {
        $activeDepartment = $this->department('Development Department A', DepartmentStatus::Active);
        $inactiveDepartment = $this->department('Development Department Inactive', DepartmentStatus::Inactive);
        $visible = $this->clinic($activeDepartment, 'Development Clinic A', ClinicStatus::Active);
        $hidden = $this->clinic($inactiveDepartment, 'Development Clinic Hidden', ClinicStatus::Active);

        $context = $this->service()->availableContext();

        $this->assertNotNull($this->clinicInContext($context, $visible->id));
        $this->assertNull($this->clinicInContext($context, $hidden->id));
    }

    public function test_context_uses_real_database_clinic_ids(): void
    {
        $department = $this->department('Development Department A', DepartmentStatus::Active);
        $clinic = $this->clinic($department, 'Development Clinic A', ClinicStatus::Active);

        $match = $this->clinicInContext($this->service()->availableContext(), $clinic->id);

        $this->assertInstanceOf(AiClinicContext::class, $match);
        $this->assertSame($clinic->id, $match->clinicId);
        $this->assertSame($clinic->id, $match->toArray()['clinic_id']);
        $this->assertDatabaseHas('clinics', ['id' => $match->clinicId]);
    }

    public function test_context_uses_real_database_clinic_names_and_descriptions(): void
    {
        $department = $this->department('Development Department A', DepartmentStatus::Active, 'Placeholder department for local development. Not hospital data.');
        $description = 'Placeholder clinic for local development. Not hospital data.';
        $clinic = $this->clinic($department, 'Development Clinic A', ClinicStatus::Active, $description);

        $match = $this->clinicInContext($this->service()->availableContext(), $clinic->id);

        $this->assertInstanceOf(AiClinicContext::class, $match);
        $this->assertSame($clinic->name, $match->clinicName);
        $this->assertSame($clinic->description, $match->clinicDescription);
        $this->assertSame($department->id, $match->departmentId);
        $this->assertSame($department->name, $match->departmentName);
        $this->assertSame([
            'clinic_id' => $clinic->id,
            'clinic_name' => 'Development Clinic A',
            'clinic_description' => $description,
            'description_kind' => 'administrative_mapping',
            'department_id' => $department->id,
            'department_name' => 'Development Department A',
        ], $match->toArray());
    }

    public function test_missing_description_is_not_fabricated(): void
    {
        $department = $this->department('Development Department A', DepartmentStatus::Active);
        $missing = $this->clinic($department, 'Development Clinic Missing Description', ClinicStatus::Active, null);
        $blank = $this->clinic($department, 'Development Clinic Blank Description', ClinicStatus::Active, '');
        $whitespace = $this->clinic($department, 'Development Clinic Whitespace Description', ClinicStatus::Active, '   ');

        $context = $this->service()->availableContext();

        foreach ([$missing, $blank, $whitespace] as $clinic) {
            $match = $this->clinicInContext($context, $clinic->id);

            $this->assertInstanceOf(AiClinicContext::class, $match);
            $this->assertArrayHasKey('clinic_description', $match->toArray());
            $this->assertSame('none', $match->toArray()['description_kind']);
            $this->assertNull($match->clinicDescription);
        }
    }

    public function test_active_doctors_are_resolved_only_from_eligible_clinics(): void
    {
        $activeDepartment = $this->department('Development Department A', DepartmentStatus::Active);
        $otherDepartment = $this->department('Development Department B', DepartmentStatus::Active);
        $inactiveDepartment = $this->department('Development Department Inactive', DepartmentStatus::Inactive);
        $eligible = $this->clinic($activeDepartment, 'Development Clinic A', ClinicStatus::Active);
        $otherEligible = $this->clinic($otherDepartment, 'Development Clinic B', ClinicStatus::Active);
        $inactiveClinic = $this->clinic($activeDepartment, 'Development Clinic Inactive', ClinicStatus::Inactive);
        $hiddenClinic = $this->clinic($inactiveDepartment, 'Development Clinic Hidden', ClinicStatus::Active);

        $eligibleDoctor = $this->doctor($eligible, 'Active Development Doctor', DoctorStatus::Active);
        $otherDoctor = $this->doctor($otherEligible, 'Other Development Doctor', DoctorStatus::Active);
        $this->doctor($inactiveClinic, 'Doctor At Inactive Clinic', DoctorStatus::Active);
        $this->doctor($hiddenClinic, 'Doctor Under Inactive Department', DoctorStatus::Active);

        $service = $this->service();
        $context = $service->availableContext();
        $doctors = $service->availableDoctors($eligible->id);

        $this->assertSame(['data_source', 'clinics', 'services'], array_keys($context->toArray()));
        $this->assertSame([$eligibleDoctor->id], $doctors->pluck('id')->all());
        $this->assertNotContains($otherDoctor->id, $doctors->pluck('id')->all());
        $this->assertCount(0, $service->availableDoctors($inactiveClinic->id));
        $this->assertCount(0, $service->availableDoctors($hiddenClinic->id));
    }

    public function test_inactive_doctors_are_excluded_from_availability(): void
    {
        $department = $this->department('Development Department A', DepartmentStatus::Active);
        $clinic = $this->clinic($department, 'Development Clinic A', ClinicStatus::Active);
        $active = $this->doctor($clinic, 'Active Development Doctor', DoctorStatus::Active);
        $inactive = $this->doctor($clinic, 'Inactive Development Doctor', DoctorStatus::Inactive);

        $doctors = $this->service()->availableDoctors($clinic->id);

        $this->assertSame([$active->id], $doctors->pluck('id')->all());
        $this->assertNotContains($inactive->id, $doctors->pluck('id')->all());
        $this->assertTrue($doctors->every(fn (Doctor $doctor): bool => $doctor->status === DoctorStatus::Active));
    }

    public function test_proposed_valid_clinic_recommendation_passes_validation(): void
    {
        $department = $this->department('Development Department A', DepartmentStatus::Active);
        $clinic = $this->clinic($department, 'Development Clinic A', ClinicStatus::Active);
        $service = $this->service();
        $context = $service->availableContext();
        $guidance = AiGuidanceResult::fromPayload([
            'type' => AiGuidanceType::Recommendation->value,
            'clinic_id' => $clinic->id,
            'reason' => 'The concern maps to an available clinic record.',
        ]);

        $this->assertSame(AiGuidanceType::Recommendation, $guidance->type);
        $this->assertTrue($service->validateProposedClinic($guidance->clinicId, $context));
        $this->assertTrue($service->validateProposedClinic((string) $clinic->id, $context));
    }

    public function test_nonexistent_clinic_id_is_rejected(): void
    {
        $department = $this->department('Development Department A', DepartmentStatus::Active);
        $clinic = $this->clinic($department, 'Development Clinic A', ClinicStatus::Active);
        $service = $this->service();
        $context = $service->availableContext();
        $deletedId = $clinic->id;
        $clinic->delete();

        $this->assertFalse($service->validateProposedClinic(999999, $context));
        $this->assertFalse($service->validateProposedClinic($deletedId, $context));
        $this->assertFalse($service->validateProposedClinic('not-a-clinic', $context));
    }

    public function test_inactive_clinic_recommendation_is_rejected(): void
    {
        $department = $this->department('Development Department A', DepartmentStatus::Active);
        $clinic = $this->clinic($department, 'Development Clinic A', ClinicStatus::Active);
        $service = $this->service();
        $context = $service->availableContext();

        $clinic->update(['status' => ClinicStatus::Inactive]);

        $this->assertTrue($context->includesClinic($clinic->id));
        $this->assertFalse($service->validateProposedClinic($clinic->id, $context));
    }

    public function test_clinic_under_inactive_department_recommendation_is_rejected(): void
    {
        $department = $this->department('Development Department A', DepartmentStatus::Active);
        $clinic = $this->clinic($department, 'Development Clinic A', ClinicStatus::Active);
        $service = $this->service();
        $context = $service->availableContext();

        $department->update(['status' => DepartmentStatus::Inactive]);

        $this->assertTrue($context->includesClinic($clinic->id));
        $this->assertFalse($service->validateProposedClinic($clinic->id, $context));
    }

    public function test_clinic_not_present_in_the_request_allowed_context_is_rejected(): void
    {
        $department = $this->department('Development Department A', DepartmentStatus::Active);
        $allowed = $this->clinic($department, 'Development Clinic A', ClinicStatus::Active);
        $service = $this->service();
        $context = $service->availableContext();
        $later = $this->clinic($department, 'Development Clinic Added Later', ClinicStatus::Active);

        $this->assertSame(ClinicStatus::Active, $later->status);
        $this->assertSame(DepartmentStatus::Active, $later->department->status);
        $this->assertFalse($context->includesClinic($later->id));
        $this->assertFalse($service->validateProposedClinic($later->id, $context));
        $this->assertTrue($service->validateProposedClinic($allowed->id, $context));
    }

    public function test_eligible_specializations_are_catalogued_without_doctor_identities(): void
    {
        $department = $this->department('Development Department A', DepartmentStatus::Active);
        $clinic = $this->clinic($department, 'Development Clinic A', ClinicStatus::Active);
        $this->doctor($clinic, 'Active Development Doctor', DoctorStatus::Active, 'Development Specialization A');
        $this->doctor($clinic, 'Inactive Development Doctor', DoctorStatus::Inactive, 'Hidden Specialization');

        $context = $this->service()->availableContext();
        $encoded = json_encode($context->toArray());

        $this->assertCount(1, $context->services);
        $this->assertSame(1, $context->services[0]->serviceId);
        $this->assertSame('Development Specialization A', $context->services[0]->specialization);
        $this->assertTrue($context->includesService(1));
        $this->assertFalse($context->includesService(99));
        $this->assertTrue($this->service()->validateProposedService(1, $context));
        $this->assertFalse($this->service()->validateProposedService(99, $context));
        $this->assertIsString($encoded);
        $this->assertStringContainsString('Development Specialization A', $encoded);
        $this->assertStringNotContainsString('Active Development Doctor', $encoded);
        $this->assertStringNotContainsString('Hidden Specialization', $encoded);
        $this->assertStringNotContainsString('doctor_id', $encoded);
    }

    public function test_specialization_from_inactive_clinic_or_department_is_excluded(): void
    {
        $activeDepartment = $this->department('Development Department A', DepartmentStatus::Active);
        $inactiveDepartment = $this->department('Development Department Inactive', DepartmentStatus::Inactive);
        $activeClinic = $this->clinic($activeDepartment, 'Development Clinic A', ClinicStatus::Active);
        $inactiveClinic = $this->clinic($activeDepartment, 'Development Clinic Inactive', ClinicStatus::Inactive);
        $hiddenClinic = $this->clinic($inactiveDepartment, 'Development Clinic Hidden', ClinicStatus::Active);
        $this->doctor($activeClinic, 'Visible Doctor', DoctorStatus::Active, 'Development Specialization A');
        $this->doctor($inactiveClinic, 'Clinic Hidden Doctor', DoctorStatus::Active, 'Inactive Clinic Specialization');
        $this->doctor($hiddenClinic, 'Department Hidden Doctor', DoctorStatus::Active, 'Inactive Department Specialization');

        $context = $this->service()->availableContext();
        $labels = array_map(fn ($service): string => $service->specialization, $context->services);

        $this->assertSame(['Development Specialization A'], $labels);
        $this->assertFalse($this->service()->validateProposedService(2, $context));
    }

    public function test_structured_recommendation_contract_supports_known_guidance_types(): void
    {
        $department = $this->department('Development Department A', DepartmentStatus::Active);
        $clinic = $this->clinic($department, 'Development Clinic A', ClinicStatus::Active);

        $recommendation = AiGuidanceResult::fromPayload([
            'type' => 'recommendation',
            'clinic_id' => (string) $clinic->id,
            'reason' => 'Available clinic record.',
            'confidence' => 0.5,
            'doctor_id' => 99,
        ]);
        $withoutConfidence = AiGuidanceResult::fromPayload([
            'type' => 'recommendation',
            'clinic_id' => $clinic->id,
            'reason' => 'Available clinic record.',
        ]);
        $clarification = AiGuidanceResult::fromPayload([
            'type' => 'clarification',
            'question' => 'Which area is affected?',
            'clinic_id' => $clinic->id,
        ]);
        $uncertain = AiGuidanceResult::fromPayload([
            'type' => 'uncertain',
            'message' => 'There is not enough information to choose a clinic.',
        ]);
        $safety = AiGuidanceResult::fromPayload([
            'type' => 'safety',
            'message' => 'Seek urgent in-person care if this is an emergency.',
        ]);

        $this->assertSame(AiGuidanceType::Recommendation, $recommendation->type);
        $this->assertSame($clinic->id, $recommendation->clinicId);
        $this->assertSame('Available clinic record.', $recommendation->reason);
        $this->assertSame(0.5, $recommendation->confidence);
        $this->assertArrayNotHasKey('doctor_id', $recommendation->toArray());
        $this->assertSame([
            'type' => 'recommendation',
            'clinic_id' => $clinic->id,
            'reason' => 'Available clinic record.',
        ], $withoutConfidence->toArray());

        $byService = AiGuidanceResult::fromPayload([
            'type' => 'recommendation',
            'service_id' => 1,
            'reason' => 'Available specialization record.',
            'doctor_id' => 99,
        ]);
        $this->assertSame(1, $byService->serviceId);
        $this->assertNull($byService->clinicId);
        $this->assertArrayNotHasKey('doctor_id', $byService->toArray());

        $this->assertSame(AiGuidanceType::Clarification, $clarification->type);
        $this->assertSame('Which area is affected?', $clarification->question);
        $this->assertNull($clarification->clinicId);

        $this->assertSame(AiGuidanceType::Uncertain, $uncertain->type);
        $this->assertSame('There is not enough information to choose a clinic.', $uncertain->message);
        $this->assertNull($uncertain->clinicId);

        $this->assertSame(AiGuidanceType::Safety, $safety->type);
        $this->assertSame('Seek urgent in-person care if this is an emergency.', $safety->message);
        $this->assertNull($safety->clinicId);

        $rejected = 0;

        foreach ([
            ['type' => 'diagnosis'],
            ['type' => 'recommendation', 'reason' => 'Missing clinic.'],
            ['type' => 'clarification'],
            ['type' => 'uncertain'],
            ['type' => 'safety', 'message' => '   '],
            ['type' => 'recommendation', 'clinic_id' => $clinic->id, 'reason' => 'Too sure.', 'confidence' => 1.5],
        ] as $payload) {
            try {
                AiGuidanceResult::fromPayload($payload);
            } catch (InvalidArgumentException) {
                $rejected++;
            }
        }

        $this->assertSame(6, $rejected);
    }

    public function test_grounding_service_does_not_create_an_appointment(): void
    {
        $department = $this->department('Development Department A', DepartmentStatus::Active);
        $clinic = $this->clinic($department, 'Development Clinic A', ClinicStatus::Active);
        $this->doctor($clinic, 'Active Development Doctor', DoctorStatus::Active);

        $appointments = Appointment::query()->count();
        $schedules = DoctorSchedule::query()->count();
        $doctors = Doctor::query()->count();
        $clinics = Clinic::query()->count();

        $service = $this->service();
        $context = $service->availableContext();
        $service->availableDoctors($clinic->id);
        $guidance = AiGuidanceResult::fromPayload([
            'type' => 'recommendation',
            'clinic_id' => $clinic->id,
            'reason' => 'Available clinic record.',
        ]);
        $service->validateProposedClinic($guidance->clinicId, $context);
        AiGuidanceResult::fromPayload([
            'type' => 'clarification',
            'question' => 'Which area is affected?',
        ]);
        AiGuidanceResult::fromPayload([
            'type' => 'uncertain',
            'message' => 'There is not enough information to choose a clinic.',
        ]);
        AiGuidanceResult::fromPayload([
            'type' => 'safety',
            'message' => 'Seek urgent in-person care if this is an emergency.',
        ]);

        $this->assertSame($appointments, Appointment::query()->count());
        $this->assertSame(0, Appointment::query()->count());
        $this->assertSame($schedules, DoctorSchedule::query()->count());
        $this->assertSame($doctors, Doctor::query()->count());
        $this->assertSame($clinics, Clinic::query()->count());
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_context_identifies_development_data_until_verified_records_replace_it(): void
    {
        $department = $this->department('Development Department A', DepartmentStatus::Active);
        $clinic = $this->clinic(
            $department,
            'Development Clinic A',
            ClinicStatus::Active,
            'Placeholder clinic for local development. Not hospital data.',
        );

        $development = $this->service()->availableContext();

        $this->assertSame(HospitalDataSource::Development, $development->dataSource);
        $this->assertSame('development', $development->toArray()['data_source']);
        $this->assertSame('Development Clinic A', $development->clinics[0]->clinicName);
        $this->assertSame($clinic->description, $development->clinics[0]->clinicDescription);

        config(['hospital.data_source' => 'not-a-real-source']);
        $this->assertSame(HospitalDataSource::Development, $this->service()->availableContext()->dataSource);

        config(['hospital.data_source' => HospitalDataSource::Imported->value]);
        $imported = $this->service()->availableContext();
        $this->assertSame(HospitalDataSource::Imported, $imported->dataSource);
        $this->assertSame('imported', $imported->toArray()['data_source']);
        $this->assertNull($this->clinicInContext($imported, $clinic->id));

        config(['hospital.data_source' => HospitalDataSource::Verified->value]);
        $verified = $this->service()->availableContext();

        $this->assertSame(HospitalDataSource::Verified, $verified->dataSource);
        $this->assertSame('verified', $verified->toArray()['data_source']);
        $this->assertSame([], $verified->clinics);
    }

    public function test_imported_mode_includes_imported_and_verified_records_only(): void
    {
        config(['hospital.data_source' => HospitalDataSource::Imported->value]);

        $developmentDepartment = $this->department('Development Department A', DepartmentStatus::Active);
        $importedDepartment = $this->department('Pending hospital mapping', DepartmentStatus::Active, null, HospitalDataSource::Imported);
        $verifiedDepartment = $this->department('Verified Mapping', DepartmentStatus::Active, null, HospitalDataSource::Verified);

        $developmentClinic = $this->clinic($developmentDepartment, 'Ortho', ClinicStatus::Active, null, HospitalDataSource::Development);
        $importedClinic = $this->clinic($importedDepartment, 'Pediatrician', ClinicStatus::Active, null, HospitalDataSource::Imported);
        $verifiedClinic = $this->clinic($verifiedDepartment, 'General Surgery', ClinicStatus::Active, null, HospitalDataSource::Verified);

        $developmentDoctor = $this->doctor($developmentClinic, 'Dr. Robles', DoctorStatus::Active, 'Ortho', HospitalDataSource::Development);
        $importedDoctor = $this->doctor($importedClinic, 'Dr. Endaya, Hazel Anne', DoctorStatus::Active, 'Pediatrician', HospitalDataSource::Imported);
        $verifiedDoctor = $this->doctor($verifiedClinic, 'Dr. Verified', DoctorStatus::Active, 'General Surgery', HospitalDataSource::Verified);

        $context = $this->service()->availableContext();
        $specializations = array_map(fn ($service) => $service->specialization, $context->services);

        $this->assertNull($this->clinicInContext($context, $developmentClinic->id));
        $this->assertNotNull($this->clinicInContext($context, $importedClinic->id));
        $this->assertNotNull($this->clinicInContext($context, $verifiedClinic->id));
        $this->assertNotContains('Ortho', $specializations);
        $this->assertContains('Pediatrician', $specializations);
        $this->assertContains('General Surgery', $specializations);
        $this->assertSame([$importedDoctor->id], $this->service()->availableDoctors($importedClinic->id)->pluck('id')->all());
        $this->assertSame([$verifiedDoctor->id], $this->service()->availableDoctors($verifiedClinic->id)->pluck('id')->all());
        $this->assertCount(0, $this->service()->availableDoctors($developmentClinic->id));
        $this->assertFalse($this->service()->eligibleDoctorsForSpecialization('Ortho')->contains('id', $developmentDoctor->id));
        $this->assertTrue($this->service()->eligibleDoctorsForSpecialization('Pediatrician')->contains('id', $importedDoctor->id));
    }

    public function test_inactive_imported_records_are_excluded_from_grounding(): void
    {
        config(['hospital.data_source' => HospitalDataSource::Imported->value]);

        $department = $this->department('Pending hospital mapping', DepartmentStatus::Active, null, HospitalDataSource::Imported);
        $clinic = $this->clinic($department, 'Pediatrician', ClinicStatus::Active, null, HospitalDataSource::Imported);
        $active = $this->doctor($clinic, 'Dr. Endaya, Hazel Anne', DoctorStatus::Active, 'Pediatrician', HospitalDataSource::Imported);
        $inactive = $this->doctor($clinic, 'Dr. Inactive Imported', DoctorStatus::Inactive, 'Pediatrician', HospitalDataSource::Imported);

        $this->assertTrue($this->service()->eligibleDoctorsForSpecialization('Pediatrician')->contains('id', $active->id));
        $this->assertFalse($this->service()->eligibleDoctorsForSpecialization('Pediatrician')->contains('id', $inactive->id));

        $clinic->update(['status' => ClinicStatus::Inactive]);
        $this->assertNull($this->clinicInContext($this->service()->availableContext(), $clinic->id));
        $this->assertTrue($this->service()->eligibleDoctorsForSpecialization('Pediatrician')->isEmpty());

        $clinic->update(['status' => ClinicStatus::Active]);
        $department->update(['status' => DepartmentStatus::Inactive]);
        $this->assertSame([], $this->service()->availableContext()->clinics);
    }

    public function test_duplicate_specialization_labels_are_normalized_from_eligible_doctors_only(): void
    {
        config(['hospital.data_source' => HospitalDataSource::Imported->value]);

        $importedDepartment = $this->department('Pending hospital mapping', DepartmentStatus::Active, null, HospitalDataSource::Imported);
        $developmentDepartment = $this->department('Development Department A', DepartmentStatus::Active);
        $importedClinic = $this->clinic($importedDepartment, 'Pediatrician', ClinicStatus::Active, null, HospitalDataSource::Imported);
        $developmentClinic = $this->clinic($developmentDepartment, 'Development Clinic A', ClinicStatus::Active);

        $this->doctor($importedClinic, 'Dr. Arada-Hocate, Maria Nickle', DoctorStatus::Active, 'Pediatrician', HospitalDataSource::Imported);
        $this->doctor($importedClinic, 'Dr. Endaya, Hazel Anne', DoctorStatus::Active, 'pediatrician', HospitalDataSource::Imported);
        $this->doctor($developmentClinic, 'Test Doctor 1', DoctorStatus::Active, 'Pediatrician');

        $services = $this->service()->availableContext()->services;

        $this->assertCount(1, $services);
        $this->assertSame('Pediatrician', $services[0]->specialization);
        $this->assertSame([$importedClinic->id], $services[0]->clinicIds);
        $payload = json_encode($this->service()->availableContext()->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Test Doctor 1', $payload);
        $this->assertStringNotContainsString('Dr. Endaya', $payload);
    }

    public function test_verified_mode_includes_verified_doctors_only(): void
    {
        config(['hospital.data_source' => HospitalDataSource::Verified->value]);

        $importedDepartment = $this->department('Pending hospital mapping', DepartmentStatus::Active, null, HospitalDataSource::Imported);
        $verifiedDepartment = $this->department('Verified Mapping', DepartmentStatus::Active, null, HospitalDataSource::Verified);
        $importedClinic = $this->clinic($importedDepartment, 'Pediatrician', ClinicStatus::Active, null, HospitalDataSource::Imported);
        $verifiedClinic = $this->clinic($verifiedDepartment, 'General Surgery', ClinicStatus::Active, null, HospitalDataSource::Verified);
        $this->doctor($importedClinic, 'Dr. Endaya, Hazel Anne', DoctorStatus::Active, 'Pediatrician', HospitalDataSource::Imported);
        $verifiedDoctor = $this->doctor($verifiedClinic, 'Dr. Verified', DoctorStatus::Active, 'General Surgery', HospitalDataSource::Verified);

        $context = $this->service()->availableContext();

        $this->assertNull($this->clinicInContext($context, $importedClinic->id));
        $this->assertNotNull($this->clinicInContext($context, $verifiedClinic->id));
        $this->assertSame([$verifiedDoctor->id], $this->service()->availableDoctors($verifiedClinic->id)->pluck('id')->all());
        $this->assertTrue($this->service()->validateProposedService(1, $context));
        $this->assertFalse($this->service()->validateProposedClinic($importedClinic->id, $context));
    }

    public function test_imported_mapping_notes_are_marked_administrative_and_not_rewritten(): void
    {
        $mapping = 'Imported from the supplied clinic-schedule poster for this specialization. Mapping to an official hospital clinic requires confirmation. Not hospital-verified.';
        $approved = 'Outpatient consultation for adult internal medicine concerns.';
        $department = $this->department('Pending hospital mapping', DepartmentStatus::Active);
        $mapped = $this->clinic($department, 'Internal Medicine', ClinicStatus::Active, $mapping);
        $described = $this->clinic($department, 'Adult Medicine', ClinicStatus::Active, $approved);

        $context = $this->service()->availableContext();
        $mappedContext = $this->clinicInContext($context, $mapped->id);
        $describedContext = $this->clinicInContext($context, $described->id);

        $this->assertSame($mapping, $mappedContext?->clinicDescription);
        $this->assertSame('administrative_mapping', $mappedContext?->descriptionKind->value);
        $this->assertSame($approved, $describedContext?->clinicDescription);
        $this->assertSame('approved_service_description', $describedContext?->descriptionKind->value);
        $this->assertSame($mapping, $mapped->fresh()->description);
        $this->assertSame($approved, $described->fresh()->description);
    }

    public function test_development_mode_still_includes_development_records(): void
    {
        $department = $this->department('Development Department A', DepartmentStatus::Active);
        $clinic = $this->clinic($department, 'Development Clinic A', ClinicStatus::Active);
        $doctor = $this->doctor($clinic, 'Test Doctor 1', DoctorStatus::Active, 'Development Specialization A');

        $context = $this->service()->availableContext();

        $this->assertSame(HospitalDataSource::Development, $context->dataSource);
        $this->assertNotNull($this->clinicInContext($context, $clinic->id));
        $this->assertTrue($this->service()->eligibleDoctorsForSpecialization('Development Specialization A')->contains('id', $doctor->id));
    }

    private function service(): AiHospitalContextService
    {
        return app(AiHospitalContextService::class);
    }

    private function clinicInContext(AiHospitalContext $context, int $clinicId): ?AiClinicContext
    {
        foreach ($context->clinics as $clinic) {
            if ($clinic->clinicId === $clinicId) {
                return $clinic;
            }
        }

        return null;
    }

    private function department(
        string $name,
        DepartmentStatus $status,
        ?string $description = null,
        HospitalDataSource $dataSource = HospitalDataSource::Development,
    ): Department {
        return Department::query()->create([
            'name' => $name,
            'description' => $description,
            'status' => $status,
            'data_source' => $dataSource,
        ]);
    }

    private function clinic(
        Department $department,
        string $name,
        ClinicStatus $status,
        ?string $description = null,
        HospitalDataSource $dataSource = HospitalDataSource::Development,
    ): Clinic {
        return Clinic::query()->create([
            'department_id' => $department->id,
            'name' => $name,
            'description' => $description,
            'status' => $status,
            'data_source' => $dataSource,
        ]);
    }

    private function doctor(
        Clinic $clinic,
        string $displayName,
        DoctorStatus $status,
        ?string $specialization = null,
        HospitalDataSource $dataSource = HospitalDataSource::Development,
    ): Doctor {
        return Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'display_name' => $displayName,
            'specialization' => $specialization,
            'status' => $status,
            'data_source' => $dataSource,
        ]);
    }
}
