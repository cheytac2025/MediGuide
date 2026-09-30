<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * DEVELOPMENT ONLY.
 *
 * Seeds an obvious placeholder Hospital Staff account for local development
 * and automated tests. This is not a real Queen Mary Hospital employee.
 *
 * Safe to rerun. Skipped in production.
 */
class DevelopmentHospitalStaffSeeder extends Seeder
{
    public const EMAIL = 'staff.dev@mediguide.test';

    public const PASSWORD = 'MediGuide@Staff2026';

    /**
     * Seed the development Hospital Staff account.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $role = Role::query()->firstOrCreate(
            ['slug' => RoleName::HospitalStaff->value],
            ['name' => RoleName::HospitalStaff->label()],
        );

        $user = User::query()->updateOrCreate(
            ['email' => self::EMAIL],
            [
                'role_id' => $role->id,
                'first_name' => 'Dev',
                'middle_name' => null,
                'last_name' => 'Staff',
                'name' => 'Dev Staff',
                'password' => Hash::make(self::PASSWORD),
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ],
        );

        $profile = $user->hospitalStaff()->firstOrCreate([]);

        $departmentIds = Department::query()
            ->whereIn('name', [
                'Development Department A',
                'Development Department B',
            ])
            ->pluck('id');

        $profile->departments()->sync($departmentIds);
    }
}
