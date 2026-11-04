<?php

namespace App\Services;

use App\Enums\ClinicStatus;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorStatus;
use App\Enums\HospitalDataSource;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Support\AiClinicContext;
use App\Support\AiHospitalContext;
use App\Support\AiServiceContext;
use Illuminate\Database\Eloquent\Collection;

/**
 * Supplies database-grounded hospital service context for a future guidance model.
 *
 * This service only reads eligible clinics and active doctors. It does not call
 * an external model, store conversations, write clinical text, change schedules,
 * or create appointments. Booking remains in AppointmentBookingService.
 */
class AiHospitalContextService
{
    /**
     * Clinics MediGuide currently considers available.
     *
     * A clinic is included only when both the clinic and its department are active.
     * Descriptions are copied from stored fields. Doctor identities are omitted
     * so a model selects a clinic or specialization, not a doctor.
     */
    public function availableContext(): AiHospitalContext
    {
        $clinics = Clinic::query()
            ->with('department')
            ->forAiGrounding()
            ->where('status', ClinicStatus::Active)
            ->whereHas('department', fn ($query) => $query
                ->where('status', DepartmentStatus::Active)
                ->forAiGrounding())
            ->orderBy('id')
            ->get();

        /** @var list<AiClinicContext> $contexts */
        $contexts = [];

        foreach ($clinics as $clinic) {
            if ($clinic->department->status !== DepartmentStatus::Active) {
                continue;
            }

            $contexts[] = AiClinicContext::fromClinic($clinic);
        }

        return new AiHospitalContext(
            HospitalDataSource::current(),
            $contexts,
            $this->eligibleServices($contexts),
        );
    }

    /**
     * Active doctors for availability after a clinic has been chosen.
     *
     * Returns no doctors unless the clinic is active and its department is active.
     * Inactive doctors are omitted. This does not select a doctor for the patient.
     *
     * @return Collection<int, Doctor>
     */
    public function availableDoctors(int $clinicId): Collection
    {
        $clinic = $this->findEligibleClinic($clinicId);

        if (! $clinic instanceof Clinic) {
            return Doctor::query()->whereIn('id', [])->get();
        }

        return Doctor::query()
            ->where('clinic_id', $clinic->id)
            ->forAiGrounding()
            ->where('status', DoctorStatus::Active)
            ->orderBy('id')
            ->get();
    }

    /**
     * A proposed clinic is allowed only when it exists, is active, belongs to an
     * active department, and was included in the context supplied for this request.
     *
     * A model-returned clinic_id is never trusted on its own.
     */
    public function validateProposedClinic(mixed $clinicId, AiHospitalContext $allowedContext): bool
    {
        $id = $this->normalizeClinicId($clinicId);

        if ($id === null || ! $allowedContext->includesClinic($id)) {
            return false;
        }

        return $this->findEligibleClinic($id) instanceof Clinic;
    }

    /**
     * A proposed service is allowed only when it was included in this request's
     * catalog and at least one eligible doctor still holds that specialization.
     */
    public function validateProposedService(mixed $serviceId, AiHospitalContext $allowedContext): bool
    {
        $id = $this->normalizeClinicId($serviceId);

        if ($id === null) {
            return false;
        }

        $service = $allowedContext->serviceById($id);

        if (! $service instanceof AiServiceContext) {
            return false;
        }

        return $this->eligibleDoctorsForSpecialization(
            $service->specialization,
            $allowedContext->allowedClinicIds(),
        )->isNotEmpty();
    }

    public function serviceFromContext(mixed $serviceId, AiHospitalContext $allowedContext): ?AiServiceContext
    {
        $id = $this->normalizeClinicId($serviceId);

        if ($id === null) {
            return null;
        }

        return $allowedContext->serviceById($id);
    }

    /**
     * @param  list<int>|null  $clinicIds
     * @return Collection<int, Doctor>
     */
    public function eligibleDoctorsForSpecialization(string $specialization, ?array $clinicIds = null): Collection
    {
        $normalized = AiServiceContext::normalize($specialization);

        if ($normalized === '') {
            return Doctor::query()->whereIn('id', [])->get();
        }

        $allowedClinicIds = $clinicIds ?? $this->availableContext()->allowedClinicIds();

        if ($allowedClinicIds === []) {
            return Doctor::query()->whereIn('id', [])->get();
        }

        return Doctor::query()
            ->with('clinic.department')
            ->forAiGrounding()
            ->where('status', DoctorStatus::Active)
            ->whereIn('clinic_id', $allowedClinicIds)
            ->whereNotNull('specialization')
            ->orderBy('display_name')
            ->orderBy('id')
            ->get()
            ->filter(fn (Doctor $doctor): bool => AiServiceContext::normalize((string) $doctor->specialization) === $normalized)
            ->values();
    }

    /**
     * @param  list<AiClinicContext>  $clinics
     * @return list<AiServiceContext>
     */
    private function eligibleServices(array $clinics): array
    {
        $clinicIds = array_map(fn (AiClinicContext $clinic): int => $clinic->clinicId, $clinics);

        if ($clinicIds === []) {
            return [];
        }

        $doctors = Doctor::query()
            ->forAiGrounding()
            ->where('status', DoctorStatus::Active)
            ->whereIn('clinic_id', $clinicIds)
            ->whereNotNull('specialization')
            ->orderBy('id')
            ->get();

        /** @var array<string, array{label: string, clinic_ids: list<int>}> $groups */
        $groups = [];

        foreach ($doctors as $doctor) {
            $label = trim((string) $doctor->specialization);

            if ($label === '') {
                continue;
            }

            $key = AiServiceContext::normalize($label);

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'label' => $label,
                    'clinic_ids' => [],
                ];
            }

            if (! in_array($doctor->clinic_id, $groups[$key]['clinic_ids'], true)) {
                $groups[$key]['clinic_ids'][] = $doctor->clinic_id;
            }
        }

        ksort($groups);

        $services = [];
        $serviceId = 1;

        foreach ($groups as $group) {
            sort($group['clinic_ids']);
            $services[] = new AiServiceContext(
                $serviceId,
                $group['label'],
                $group['clinic_ids'],
            );
            $serviceId++;
        }

        return $services;
    }

    private function findEligibleClinic(int $clinicId): ?Clinic
    {
        if ($clinicId < 1) {
            return null;
        }

        return Clinic::query()
            ->with('department')
            ->forAiGrounding()
            ->whereKey($clinicId)
            ->where('status', ClinicStatus::Active)
            ->whereHas('department', fn ($query) => $query
                ->where('status', DepartmentStatus::Active)
                ->forAiGrounding())
            ->first();
    }

    private function normalizeClinicId(mixed $clinicId): ?int
    {
        if (is_int($clinicId) && $clinicId > 0) {
            return $clinicId;
        }

        if (is_string($clinicId) && preg_match('/^[1-9]\d*$/', $clinicId) === 1) {
            return (int) $clinicId;
        }

        return null;
    }
}
