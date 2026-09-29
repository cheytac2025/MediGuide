<?php

namespace Database\Seeders;

use App\Enums\DoctorStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Doctor;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * DEVELOPMENT ONLY.
 *
 * Seeds a placeholder Doctor login linked to Test Doctor 1 for local development
 * and automated tests. This is not a real Queen Mary Hospital physician.
 *
 * Safe to rerun. Skipped in production. Run after DevelopmentDoctorSeeder.
 */
class DevelopmentDoctorAccountSeeder extends Seeder
{
    public const EMAIL = 'doctor.dev@mediguide.test';

    public const PASSWORD = 'MediGuide@Doctor2026';

    public const LINKED_DOCTOR_DISPLAY_NAME = 'Test Doctor 1';

    /**
     * Seed the development Doctor account and link it to a test doctor record.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $role = Role::query()->firstOrCreate(
            ['slug' => RoleName::Doctor->value],
            ['name' => RoleName::Doctor->label()],
        );

        $user = User::query()->updateOrCreate(
            ['email' => self::EMAIL],
            [
                'role_id' => $role->id,
                'first_name' => 'Dev',
                'middle_name' => null,
                'last_name' => 'Doctor',
                'name' => 'Dev Doctor',
                'password' => Hash::make(self::PASSWORD),
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ],
        );

        $doctor = Doctor::query()
            ->where('display_name', self::LINKED_DOCTOR_DISPLAY_NAME)
            ->where('status', DoctorStatus::Active)
            ->firstOrFail();

        $doctor->update([
            'user_id' => $user->id,
        ]);
    }
}
