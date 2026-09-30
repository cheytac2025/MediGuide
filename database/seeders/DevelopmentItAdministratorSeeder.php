<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * DEVELOPMENT ONLY.
 *
 * Seeds an obvious placeholder IT Administrator account for local development
 * and automated tests. This is not a real Queen Mary Hospital employee.
 *
 * Safe to rerun. Skipped in production.
 */
class DevelopmentItAdministratorSeeder extends Seeder
{
    public const EMAIL = 'admin.dev@mediguide.test';

    public const PASSWORD = 'MediGuide@Admin2026';

    /**
     * Seed the development IT Administrator account.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $role = Role::query()->firstOrCreate(
            ['slug' => RoleName::ItAdministrator->value],
            ['name' => RoleName::ItAdministrator->label()],
        );

        User::query()->updateOrCreate(
            ['email' => self::EMAIL],
            [
                'role_id' => $role->id,
                'first_name' => 'Dev',
                'middle_name' => null,
                'last_name' => 'Admin',
                'name' => 'Dev Admin',
                'password' => Hash::make(self::PASSWORD),
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ],
        );
    }
}
