<?php

namespace Database\Seeders;

use App\Enums\ClinicStatus;
use App\Models\Clinic;
use App\Models\Department;
use Illuminate\Database\Seeder;

/**
 * DEVELOPMENT ONLY.
 *
 * Seeds obvious placeholder clinics for local development and tests.
 * These records are not Queen Mary Hospital data.
 *
 * Replace or remove this seeder when verified hospital clinic
 * information becomes available.
 */
class DevelopmentClinicSeeder extends Seeder
{
    /**
     * Seed placeholder clinics for development.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        foreach ($this->placeholders() as $clinic) {
            $department = Department::query()->where('name', $clinic['department'])->firstOrFail();

            Clinic::query()->updateOrCreate(
                [
                    'department_id' => $department->id,
                    'name' => $clinic['name'],
                ],
                [
                    'description' => $clinic['description'],
                    'status' => $clinic['status'],
                ],
            );
        }
    }

    /**
     * @return list<array{department: string, name: string, description: string, status: ClinicStatus}>
     */
    private function placeholders(): array
    {
        return [
            [
                'department' => 'Development Department A',
                'name' => 'Development Clinic A',
                'description' => 'Placeholder clinic for local development. Not hospital data.',
                'status' => ClinicStatus::Active,
            ],
            [
                'department' => 'Development Department B',
                'name' => 'Development Clinic B',
                'description' => 'Placeholder clinic for local development. Not hospital data.',
                'status' => ClinicStatus::Active,
            ],
            [
                'department' => 'Development Department C',
                'name' => 'Development Clinic C',
                'description' => 'Placeholder clinic for local development. Not hospital data.',
                'status' => ClinicStatus::Inactive,
            ],
        ];
    }
}
