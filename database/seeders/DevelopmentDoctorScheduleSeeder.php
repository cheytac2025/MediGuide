<?php

namespace Database\Seeders;

use App\Enums\DayOfWeek;
use App\Enums\DoctorScheduleStatus;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use Illuminate\Database\Seeder;

/**
 * DEVELOPMENT ONLY.
 *
 * Seeds obvious placeholder doctor schedules for local development and tests.
 * These records are not Queen Mary Hospital data.
 *
 * Replace or remove this seeder when verified hospital schedule
 * information becomes available.
 */
class DevelopmentDoctorScheduleSeeder extends Seeder
{
    /**
     * Seed placeholder doctor schedules for development.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        foreach ($this->placeholders() as $schedule) {
            $doctor = Doctor::query()->where('display_name', $schedule['doctor'])->firstOrFail();

            DoctorSchedule::query()->updateOrCreate(
                [
                    'doctor_id' => $doctor->id,
                    'day_of_week' => $schedule['day_of_week'],
                    'start_time' => $schedule['start_time'],
                    'end_time' => $schedule['end_time'],
                ],
                [
                    'slot_duration' => $schedule['slot_duration'],
                    'status' => $schedule['status'],
                ],
            );
        }
    }

    /**
     * @return list<array{doctor: string, day_of_week: DayOfWeek, start_time: string, end_time: string, slot_duration: int, status: DoctorScheduleStatus}>
     */
    private function placeholders(): array
    {
        return [
            [
                'doctor' => 'Test Doctor 1',
                'day_of_week' => DayOfWeek::Monday,
                'start_time' => '09:00:00',
                'end_time' => '12:00:00',
                'slot_duration' => 30,
                'status' => DoctorScheduleStatus::Active,
            ],
            [
                'doctor' => 'Test Doctor 1',
                'day_of_week' => DayOfWeek::Wednesday,
                'start_time' => '13:00:00',
                'end_time' => '16:00:00',
                'slot_duration' => 30,
                'status' => DoctorScheduleStatus::Active,
            ],
            [
                'doctor' => 'Test Doctor 2',
                'day_of_week' => DayOfWeek::Tuesday,
                'start_time' => '09:00:00',
                'end_time' => '12:00:00',
                'slot_duration' => 30,
                'status' => DoctorScheduleStatus::Active,
            ],
            [
                'doctor' => 'Test Doctor 2',
                'day_of_week' => DayOfWeek::Thursday,
                'start_time' => '13:00:00',
                'end_time' => '16:00:00',
                'slot_duration' => 30,
                'status' => DoctorScheduleStatus::Active,
            ],
            [
                'doctor' => 'Test Doctor 3',
                'day_of_week' => DayOfWeek::Friday,
                'start_time' => '09:00:00',
                'end_time' => '12:00:00',
                'slot_duration' => 30,
                'status' => DoctorScheduleStatus::Active,
            ],
        ];
    }
}
