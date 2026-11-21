<?php

namespace Database\Seeders;

use App\Enums\DoctorStatus;
use App\Enums\RoleName;
use App\Enums\Sex;
use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\Role;
use App\Models\User;
use App\Support\Demo\DemoBookingEnvironment;
use App\Support\HospitalImport\QueenMaryClinicScheduleCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Synthetic accounts for an isolated capstone demonstration database.
 *
 * Never copies real patient data. Skipped on ordinary production unless
 * MEDIGUIDE_DEMO_INSTANCE is explicitly enabled.
 */
class CapstoneDemoAccountSeeder extends Seeder
{
    public const PATIENT_EMAIL = 'patient.demo@mediguide.test';

    public const STAFF_EMAIL = 'staff.demo@mediguide.test';

    public const DOCTOR_EMAIL = 'doctor.demo@mediguide.test';

    public const ADMIN_EMAIL = 'admin.demo@mediguide.test';

    public const PASSWORD = 'MediGuide@Demo2026';

    public function run(): void
    {
        if (app()->isProduction() && config('hospital.demo_instance') !== true) {
            return;
        }

        if (DemoBookingEnvironment::isBlockedDatabase()) {
            $this->command?->error('Refusing to seed demo accounts on a blocked database.');

            return;
        }

        $this->seedPatient();
        $this->seedStaff();
        $this->seedDoctor();
        $this->seedAdmin();
    }

    private function seedPatient(): void
    {
        $role = Role::query()->firstOrCreate(
            ['slug' => RoleName::Patient->value],
            ['name' => RoleName::Patient->label()],
        );

        $user = User::query()->updateOrCreate(
            ['email' => self::PATIENT_EMAIL],
            [
                'role_id' => $role->id,
                'first_name' => 'Demo',
                'middle_name' => null,
                'last_name' => 'Patient',
                'name' => 'Demo Patient',
                'password' => Hash::make(self::PASSWORD),
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ],
        );

        $user->patient()->firstOrCreate(
            [],
            [
                'date_of_birth' => '1992-05-15',
                'sex' => Sex::Female,
                'contact_number' => '09170000001',
            ],
        );
    }

    private function seedStaff(): void
    {
        $role = Role::query()->firstOrCreate(
            ['slug' => RoleName::HospitalStaff->value],
            ['name' => RoleName::HospitalStaff->label()],
        );

        $user = User::query()->updateOrCreate(
            ['email' => self::STAFF_EMAIL],
            [
                'role_id' => $role->id,
                'first_name' => 'Demo',
                'middle_name' => null,
                'last_name' => 'Staff',
                'name' => 'Demo Staff',
                'password' => Hash::make(self::PASSWORD),
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ],
        );

        $profile = $user->hospitalStaff()->firstOrCreate([]);

        $departmentIds = Department::query()
            ->where('name', QueenMaryClinicScheduleCatalog::PENDING_DEPARTMENT_NAME)
            ->pluck('id');

        if ($departmentIds->isEmpty()) {
            $departmentIds = Department::query()->pluck('id');
        }

        $profile->departments()->sync($departmentIds);
    }

    private function seedDoctor(): void
    {
        $doctor = Doctor::query()
            ->where('status', DoctorStatus::Active)
            ->orderBy('id')
            ->first();

        if (! $doctor instanceof Doctor) {
            return;
        }

        $role = Role::query()->firstOrCreate(
            ['slug' => RoleName::Doctor->value],
            ['name' => RoleName::Doctor->label()],
        );

        $user = User::query()->updateOrCreate(
            ['email' => self::DOCTOR_EMAIL],
            [
                'role_id' => $role->id,
                'first_name' => 'Demo',
                'middle_name' => null,
                'last_name' => 'Doctor',
                'name' => 'Demo Doctor',
                'password' => Hash::make(self::PASSWORD),
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ],
        );

        if ($doctor->user_id !== $user->id) {
            $doctor->forceFill(['user_id' => $user->id])->save();
        }
    }

    private function seedAdmin(): void
    {
        $role = Role::query()->firstOrCreate(
            ['slug' => RoleName::ItAdministrator->value],
            ['name' => RoleName::ItAdministrator->label()],
        );

        User::query()->updateOrCreate(
            ['email' => self::ADMIN_EMAIL],
            [
                'role_id' => $role->id,
                'first_name' => 'Demo',
                'middle_name' => null,
                'last_name' => 'Admin',
                'name' => 'Demo Admin',
                'password' => Hash::make(self::PASSWORD),
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ],
        );
    }
}
