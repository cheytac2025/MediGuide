<?php

namespace Database\Seeders;

use App\Enums\DoctorStatus;
use App\Models\Clinic;
use App\Models\Doctor;
use Illuminate\Database\Seeder;

/**
 * DEVELOPMENT ONLY.
 *
 * Seeds obvious placeholder doctors for local development and tests.
 * These records are not Queen Mary Hospital data and are not real physicians.
 *
 * Replace or remove this seeder when verified hospital doctor
 * information becomes available.
 */
class DevelopmentDoctorSeeder extends Seeder
{
    /**
     * Seed placeholder doctors for development.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        foreach ($this->placeholders() as $doctor) {
            $clinic = Clinic::query()->where('name', $doctor['clinic'])->firstOrFail();

            Doctor::query()->updateOrCreate(
                [
                    'clinic_id' => $clinic->id,
                    'display_name' => $doctor['display_name'],
                ],
                [
                    'user_id' => null,
                    'specialization' => $doctor['specialization'],
                    'status' => $doctor['status'],
                ],
            );
        }
    }

    /**
     * @return list<array{clinic: string, display_name: string, specialization: string, status: DoctorStatus}>
     */
    private function placeholders(): array
    {
        return [
            [
                'clinic' => 'Development Clinic A',
                'display_name' => 'Test Doctor 1',
                'specialization' => 'Development Specialization A',
                'status' => DoctorStatus::Active,
            ],
            [
                'clinic' => 'Development Clinic B',
                'display_name' => 'Test Doctor 2',
                'specialization' => 'Development Specialization B',
                'status' => DoctorStatus::Active,
            ],
            [
                'clinic' => 'Development Clinic C',
                'display_name' => 'Test Doctor 3',
                'specialization' => 'Development Specialization A',
                'status' => DoctorStatus::Inactive,
            ],
        ];
    }
}
