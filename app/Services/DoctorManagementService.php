<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\ClinicStatus;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DoctorManagementService
{
    /**
     * @param  array{display_name: string, clinic_id: int, specialization: ?string, status: string}  $doctorAttributes
     * @param  array{first_name: string, middle_name: ?string, last_name: string, email: string, password: string}  $accountAttributes
     */
    public function create(array $doctorAttributes, array $accountAttributes): Doctor
    {
        return DB::transaction(function () use ($doctorAttributes, $accountAttributes): Doctor {
            $clinic = $this->clinicForAssignment((int) $doctorAttributes['clinic_id']);
            $this->assertActiveDoctorPlacement($clinic, DoctorStatus::from($doctorAttributes['status']));

            $role = Role::query()->where('slug', RoleName::Doctor->value)->firstOrFail();

            $user = User::query()->create([
                'role_id' => $role->id,
                'first_name' => $accountAttributes['first_name'],
                'middle_name' => $accountAttributes['middle_name'],
                'last_name' => $accountAttributes['last_name'],
                'name' => $this->fullName(
                    $accountAttributes['first_name'],
                    $accountAttributes['middle_name'],
                    $accountAttributes['last_name'],
                ),
                'email' => $accountAttributes['email'],
                'password' => $accountAttributes['password'],
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ]);

            return Doctor::query()->create([
                'clinic_id' => $clinic->id,
                'user_id' => $user->id,
                'display_name' => $doctorAttributes['display_name'],
                'specialization' => $doctorAttributes['specialization'],
                'status' => $doctorAttributes['status'],
            ]);
        });
    }

    /**
     * @param  array{display_name: string, clinic_id: int, specialization: ?string, status: string}  $doctorAttributes
     * @param  array{first_name: string, middle_name: ?string, last_name: string, email: string}|null  $accountAttributes
     */
    public function update(Doctor $doctor, array $doctorAttributes, ?array $accountAttributes): Doctor
    {
        return DB::transaction(function () use ($doctor, $doctorAttributes, $accountAttributes): Doctor {
            /** @var Doctor $locked */
            $locked = Doctor::query()->whereKey($doctor->getKey())->lockForUpdate()->firstOrFail();
            $clinic = $this->clinicForAssignment((int) $doctorAttributes['clinic_id']);
            $status = DoctorStatus::from($doctorAttributes['status']);

            $this->assertActiveDoctorPlacement($clinic, $status);
            $this->assertClinicChangeIsSafe($locked, $clinic);

            $locked->update([
                'clinic_id' => $clinic->id,
                'display_name' => $doctorAttributes['display_name'],
                'specialization' => $doctorAttributes['specialization'],
                'status' => $status,
            ]);

            if ($accountAttributes !== null && $locked->user instanceof User) {
                $locked->user->update([
                    'first_name' => $accountAttributes['first_name'],
                    'middle_name' => $accountAttributes['middle_name'],
                    'last_name' => $accountAttributes['last_name'],
                    'name' => $this->fullName(
                        $accountAttributes['first_name'],
                        $accountAttributes['middle_name'],
                        $accountAttributes['last_name'],
                    ),
                    'email' => $accountAttributes['email'],
                ]);
            }

            return $locked->fresh(['user', 'clinic.department']);
        });
    }

    public function updateStatus(Doctor $doctor, DoctorStatus $status): Doctor
    {
        return DB::transaction(function () use ($doctor, $status): Doctor {
            /** @var Doctor $locked */
            $locked = Doctor::query()->with('clinic.department')->whereKey($doctor->getKey())->lockForUpdate()->firstOrFail();

            if ($status === DoctorStatus::Active) {
                $clinic = $locked->clinic;
                $departmentActive = $clinic->department?->status === DepartmentStatus::Active;

                if ($clinic->status !== ClinicStatus::Active || ! $departmentActive) {
                    throw ValidationException::withMessages([
                        'status' => 'This doctor cannot be activated because the assigned clinic or department is inactive.',
                    ]);
                }
            }

            $locked->update(['status' => $status]);

            return $locked->fresh();
        });
    }

    private function clinicForAssignment(int $clinicId): Clinic
    {
        $clinic = Clinic::query()->with('department')->find($clinicId);

        if (! $clinic instanceof Clinic) {
            throw ValidationException::withMessages([
                'clinic_id' => 'The selected clinic is invalid.',
            ]);
        }

        return $clinic;
    }

    private function assertActiveDoctorPlacement(Clinic $clinic, DoctorStatus $status): void
    {
        if ($status !== DoctorStatus::Active) {
            return;
        }

        if ($clinic->status !== ClinicStatus::Active) {
            throw ValidationException::withMessages([
                'clinic_id' => 'An active doctor must belong to an active clinic.',
            ]);
        }

        if ($clinic->department?->status !== DepartmentStatus::Active) {
            throw ValidationException::withMessages([
                'clinic_id' => 'An active doctor must belong to a clinic in an active department.',
            ]);
        }
    }

    private function assertClinicChangeIsSafe(Doctor $doctor, Clinic $clinic): void
    {
        if ((int) $doctor->clinic_id === (int) $clinic->id) {
            return;
        }

        $hasFutureAppointments = Appointment::query()
            ->where('doctor_id', $doctor->id)
            ->whereIn('status', [AppointmentStatus::Pending, AppointmentStatus::Confirmed])
            ->whereDate('appointment_date', '>=', Carbon::today())
            ->exists();

        if ($hasFutureAppointments) {
            throw ValidationException::withMessages([
                'clinic_id' => 'This doctor cannot be moved to another clinic while future appointments are assigned.',
            ]);
        }
    }

    private function fullName(string $firstName, ?string $middleName, string $lastName): string
    {
        return collect([$firstName, $middleName, $lastName])
            ->filter(fn (?string $part): bool => filled($part))
            ->implode(' ');
    }
}
