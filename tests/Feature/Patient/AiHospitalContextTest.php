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

        $this->assertSame(['data_source', 'clinics'], array_keys($context->toArray()));
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

        config(['hospital.data_source' => HospitalDataSource::Verified->value]);
        $verified = $this->service()->availableContext();

        $this->assertSame(HospitalDataSource::Verified, $verified->dataSource);
        $this->assertSame('verified', $verified->toArray()['data_source']);
        $this->assertSame($development->clinics[0]->toArray(), $verified->clinics[0]->toArray());
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

    private function department(string $name, DepartmentStatus $status, ?string $description = null): Department
    {
        return Department::query()->create([
            'name' => $name,
            'description' => $description,
            'status' => $status,
        ]);
    }

    private function clinic(Department $department, string $name, ClinicStatus $status, ?string $description = null): Clinic
    {
        return Clinic::query()->create([
            'department_id' => $department->id,
            'name' => $name,
            'description' => $description,
            'status' => $status,
        ]);
    }

    private function doctor(Clinic $clinic, string $displayName, DoctorStatus $status): Doctor
    {
        return Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'display_name' => $displayName,
            'specialization' => null,
            'status' => $status,
        ]);
    }
}
