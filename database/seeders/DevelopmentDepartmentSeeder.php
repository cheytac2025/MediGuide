<?php

namespace Database\Seeders;

use App\Enums\DepartmentStatus;
use App\Models\Department;
use Illuminate\Database\Seeder;

/**
 * DEVELOPMENT ONLY.
 *
 * Seeds obvious placeholder departments for local development and tests.
 * These records are not Queen Mary Hospital data.
 *
 * Replace or remove this seeder when verified hospital department
 * information becomes available.
 */
class DevelopmentDepartmentSeeder extends Seeder
{
    /**
     * Seed placeholder departments for development.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        foreach ($this->placeholders() as $department) {
            Department::query()->updateOrCreate(
                ['name' => $department['name']],
                [
                    'description' => $department['description'],
                    'status' => $department['status'],
                ],
            );
        }
    }

    /**
     * @return list<array{name: string, description: string, status: DepartmentStatus}>
     */
    private function placeholders(): array
    {
        return [
            [
                'name' => 'Development Department A',
                'description' => 'Placeholder department for local development. Not hospital data.',
                'status' => DepartmentStatus::Active,
            ],
            [
                'name' => 'Development Department B',
                'description' => 'Placeholder department for local development. Not hospital data.',
                'status' => DepartmentStatus::Active,
            ],
            [
                'name' => 'Development Department C',
                'description' => 'Placeholder department for local development. Not hospital data.',
                'status' => DepartmentStatus::Inactive,
            ],
        ];
    }
}
