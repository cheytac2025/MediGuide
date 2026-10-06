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
     * so a future model selects a clinic, not a doctor.
     */
    public function availableContext(): AiHospitalContext
    {
        $clinics = Clinic::query()
            ->with('department')
            ->where('status', ClinicStatus::Active)
            ->whereHas('department', fn ($query) => $query->where('status', DepartmentStatus::Active))
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

        return new AiHospitalContext(HospitalDataSource::current(), $contexts);
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

    private function findEligibleClinic(int $clinicId): ?Clinic
    {
        if ($clinicId < 1) {
            return null;
        }

        return Clinic::query()
            ->with('department')
            ->whereKey($clinicId)
            ->where('status', ClinicStatus::Active)
            ->whereHas('department', fn ($query) => $query->where('status', DepartmentStatus::Active))
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
