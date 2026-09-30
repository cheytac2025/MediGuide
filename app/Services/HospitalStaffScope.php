<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\HospitalStaff;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Department scope for an authenticated Hospital Staff account.
 *
 * Role middleware answers whether the user is Hospital Staff.
 * This service answers whether a resource belongs to that staff member's assigned departments.
 * An empty assignment never falls back to hospital-wide access.
 */
class HospitalStaffScope
{
    /** @var array<int, list<int>> */
    private array $departmentIdsByUser = [];

    /**
     * @return list<int>
     */
    public function departmentIds(User $user): array
    {
        if (! array_key_exists($user->id, $this->departmentIdsByUser)) {
            $this->departmentIdsByUser[$user->id] = $this->loadDepartmentIds($user);
        }

        return $this->departmentIdsByUser[$user->id];
    }

    public function canAccessDepartment(User $user, int $departmentId): bool
    {
        return in_array($departmentId, $this->departmentIds($user), true);
    }

    public function canAccessDoctor(User $user, Doctor $doctor): bool
    {
        $doctor->loadMissing('clinic');

        $departmentId = $doctor->clinic?->department_id;

        return $departmentId !== null && $this->canAccessDepartment($user, (int) $departmentId);
    }

    public function canAccessAppointment(User $user, Appointment $appointment): bool
    {
        $appointment->loadMissing('doctor.clinic');

        $doctor = $appointment->doctor;

        return $doctor instanceof Doctor && $this->canAccessDoctor($user, $doctor);
    }

    public function canAccessSchedule(User $user, DoctorSchedule $schedule): bool
    {
        $schedule->loadMissing('doctor.clinic');

        $doctor = $schedule->doctor;

        return $doctor instanceof Doctor && $this->canAccessDoctor($user, $doctor);
    }

    /**
     * @return Builder<Appointment>
     */
    public function appointments(User $user): Builder
    {
        $departmentIds = $this->departmentIds($user);

        return Appointment::query()->whereHas('doctor.clinic', function (Builder $query) use ($departmentIds): void {
            $this->limitToDepartments($query, $departmentIds);
        });
    }

    /**
     * @return Builder<Doctor>
     */
    public function doctors(User $user): Builder
    {
        $departmentIds = $this->departmentIds($user);

        return Doctor::query()->whereHas('clinic', function (Builder $query) use ($departmentIds): void {
            $this->limitToDepartments($query, $departmentIds);
        });
    }

    public function appointment(User $user, int $appointmentId): Appointment
    {
        return $this->appointments($user)->whereKey($appointmentId)->firstOrFail();
    }

    public function ensureDoctor(User $user, Doctor $doctor): void
    {
        if (! $this->canAccessDoctor($user, $doctor)) {
            abort(404);
        }
    }

    public function ensureSchedule(User $user, DoctorSchedule $schedule): void
    {
        if (! $this->canAccessSchedule($user, $schedule)) {
            abort(404);
        }
    }

    /**
     * @return list<int>
     */
    private function loadDepartmentIds(User $user): array
    {
        $profile = $user->hospitalStaff;

        if (! $profile instanceof HospitalStaff) {
            return [];
        }

        return $profile->departments()
            ->pluck('departments.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  Builder<Model>  $query
     * @param  list<int>  $departmentIds
     */
    private function limitToDepartments(Builder $query, array $departmentIds): void
    {
        if ($departmentIds === []) {
            $query->whereRaw('0 = 1');

            return;
        }

        $query->whereIn('department_id', $departmentIds);
    }
}
