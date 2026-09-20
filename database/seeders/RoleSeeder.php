<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Seed the application's default MediGuide roles.
     */
    public function run(): void
    {
        foreach (RoleName::cases() as $role) {
            Role::query()->updateOrCreate(
                ['slug' => $role->value],
                ['name' => $role->label()],
            );
        }
    }
}
