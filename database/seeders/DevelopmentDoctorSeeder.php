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
 * Specialization labels are copied from QueenMaryClinicScheduleCatalog for
 * realistic navigation fixtures. Display names and clinic links remain
 * synthetic. These records are not Queen Mary Hospital physicians and are
 * not promoted hospital data.
 *
 * Replace or remove this seeder when verified hospital doctor
 * information becomes available.
 */
class DevelopmentDoctorSeeder extends Seeder
{
    /**
     * Catalog specialization used by Development Clinic A fixtures.
     */
    public const string SPECIALIZATION_A = 'Internal Medicine';

    /**
     * Catalog specialization used by Development Clinic B fixtures.
     */
    public const string SPECIALIZATION_B = 'General Surgery';

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
                'specialization' => self::SPECIALIZATION_A,
                'status' => DoctorStatus::Active,
            ],
            [
                'clinic' => 'Development Clinic B',
                'display_name' => 'Test Doctor 2',
                'specialization' => self::SPECIALIZATION_B,
                'status' => DoctorStatus::Active,
            ],
            [
                'clinic' => 'Development Clinic C',
                'display_name' => 'Test Doctor 3',
                'specialization' => self::SPECIALIZATION_A,
                'status' => DoctorStatus::Inactive,
            ],
        ];
    }
}
