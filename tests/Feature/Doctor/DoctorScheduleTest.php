<?php

namespace Tests\Feature\Doctor;

use App\Enums\ClinicStatus;
use App\Enums\DayOfWeek;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorScheduleStatus;
use App\Enums\DoctorStatus;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use Database\Seeders\DevelopmentClinicSeeder;
use Database\Seeders\DevelopmentDepartmentSeeder;
use Database\Seeders\DevelopmentDoctorScheduleSeeder;
use Database\Seeders\DevelopmentDoctorSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DoctorScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_can_be_created_for_a_valid_doctor(): void
    {
        $doctor = $this->developmentDoctor('Test Doctor 1');

        $schedule = DoctorSchedule::query()->create([
            'doctor_id' => $doctor->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'slot_duration' => 30,
            'status' => DoctorScheduleStatus::Active,
        ]);

        $this->assertDatabaseHas('doctor_schedules', [
            'id' => $schedule->id,
            'doctor_id' => $doctor->id,
            'day_of_week' => DayOfWeek::Monday->value,
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'slot_duration' => 30,
            'status' => DoctorScheduleStatus::Active->value,
        ]);
    }

    public function test_schedule_belongs_to_doctor(): void
    {
        $doctor = $this->developmentDoctor('Test Doctor 1');

        $schedule = $this->createSchedule($doctor, DayOfWeek::Monday, '09:00:00', '12:00:00');

        $this->assertTrue($schedule->doctor->is($doctor));
        $this->assertSame('Test Doctor 1', $schedule->doctor->display_name);
    }

    public function test_doctor_has_many_schedules(): void
    {
        $doctor = $this->developmentDoctor('Test Doctor 1');

        $monday = $this->createSchedule($doctor, DayOfWeek::Monday, '09:00:00', '12:00:00');
        $wednesday = $this->createSchedule($doctor, DayOfWeek::Wednesday, '13:00:00', '16:00:00');

        $schedules = $doctor->schedules()->orderBy('day_of_week')->get();

        $this->assertCount(2, $schedules);
        $this->assertTrue($schedules->contains($monday));
        $this->assertTrue($schedules->contains($wednesday));
    }

    public function test_day_of_week_casts_correctly(): void
    {
        $doctor = $this->developmentDoctor('Test Doctor 1');

        $schedule = $this->createSchedule($doctor, DayOfWeek::Monday, '09:00:00', '12:00:00');

        $this->assertSame(DayOfWeek::Monday, $schedule->fresh()->day_of_week);
        $this->assertSame('monday', $schedule->fresh()->day_of_week->value);
        $this->assertSame('MONDAY', $schedule->fresh()->day_of_week->label());
    }

    public function test_doctor_schedule_status_casts_correctly(): void
    {
        $doctor = $this->developmentDoctor('Test Doctor 1');

        $active = $this->createSchedule($doctor, DayOfWeek::Monday, '09:00:00', '12:00:00');
        $inactive = $this->createSchedule(
            $doctor,
            DayOfWeek::Wednesday,
            '13:00:00',
            '16:00:00',
            DoctorScheduleStatus::Inactive,
        );

        $this->assertSame(DoctorScheduleStatus::Active, $active->fresh()->status);
        $this->assertSame('active', $active->fresh()->status->value);
        $this->assertSame('ACTIVE', $active->fresh()->status->label());

        $this->assertSame(DoctorScheduleStatus::Inactive, $inactive->fresh()->status);
        $this->assertSame('inactive', $inactive->fresh()->status->value);
        $this->assertSame('INACTIVE', $inactive->fresh()->status->label());
    }

    public function test_doctor_id_cannot_reference_a_nonexistent_doctor(): void
    {
        $this->expectException(QueryException::class);

        DoctorSchedule::query()->create([
            'doctor_id' => 999_999,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'slot_duration' => 30,
            'status' => DoctorScheduleStatus::Active,
        ]);
    }

    public function test_doctor_deletion_is_restricted_when_schedules_reference_it(): void
    {
        $doctor = $this->developmentDoctor('Test Doctor 1');
        $this->createSchedule($doctor, DayOfWeek::Monday, '09:00:00', '12:00:00');

        try {
            $doctor->delete();
            $this->fail('Doctor deletion should be restricted when schedules reference it.');
        } catch (QueryException) {
            // Expected: RESTRICT prevents deleting a doctor that still has schedules.
        }

        $this->assertDatabaseHas('doctors', ['id' => $doctor->id]);
        $this->assertDatabaseHas('doctor_schedules', [
            'doctor_id' => $doctor->id,
            'day_of_week' => DayOfWeek::Monday->value,
        ]);
    }

    public function test_slot_duration_must_be_positive(): void
    {
        $doctor = $this->developmentDoctor('Test Doctor 1');

        try {
            $this->createSchedule($doctor, DayOfWeek::Monday, '09:00:00', '12:00:00', slotDuration: 0);
            $this->fail('Zero slot duration should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('slot_duration', $exception->errors());
        }

        $this->assertSame(0, DoctorSchedule::query()->count());
    }

    public function test_start_time_must_be_before_end_time(): void
    {
        $doctor = $this->developmentDoctor('Test Doctor 1');

        try {
            $this->createSchedule($doctor, DayOfWeek::Monday, '12:00:00', '09:00:00');
            $this->fail('A start time after the end time should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('end_time', $exception->errors());
        }

        $this->assertSame(0, DoctorSchedule::query()->count());
    }

    public function test_exact_duplicate_schedule_is_rejected(): void
    {
        $doctor = $this->developmentDoctor('Test Doctor 1');

        $this->createSchedule($doctor, DayOfWeek::Monday, '09:00:00', '12:00:00', DoctorScheduleStatus::Inactive);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->createSchedule($doctor, DayOfWeek::Monday, '09:00:00', '12:00:00', DoctorScheduleStatus::Inactive);
    }

    public function test_overlapping_active_schedule_for_the_same_doctor_and_day_is_rejected(): void
    {
        $doctor = $this->developmentDoctor('Test Doctor 1');

        $this->createSchedule($doctor, DayOfWeek::Monday, '09:00:00', '12:00:00');

        try {
            $this->createSchedule($doctor, DayOfWeek::Monday, '10:00:00', '13:00:00');
            $this->fail('Overlapping active schedules should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('start_time', $exception->errors());
        }

        $this->assertSame(1, DoctorSchedule::query()->count());
    }

    public function test_non_overlapping_schedule_for_the_same_doctor_and_day_is_allowed(): void
    {
        $doctor = $this->developmentDoctor('Test Doctor 1');

        $this->createSchedule($doctor, DayOfWeek::Monday, '09:00:00', '12:00:00');
        $afternoon = $this->createSchedule($doctor, DayOfWeek::Monday, '13:00:00', '16:00:00');

        $this->assertDatabaseHas('doctor_schedules', [
            'id' => $afternoon->id,
            'doctor_id' => $doctor->id,
            'day_of_week' => DayOfWeek::Monday->value,
            'start_time' => '13:00:00',
            'end_time' => '16:00:00',
        ]);
        $this->assertSame(2, $doctor->schedules()->count());
    }

    public function test_development_doctor_schedule_seeder_creates_placeholder_records(): void
    {
        $this->seedDevelopmentSchedules();

        $this->assertSame(5, DoctorSchedule::query()->count());
        $this->assertDatabaseHas('doctor_schedules', [
            'day_of_week' => DayOfWeek::Monday->value,
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'slot_duration' => 30,
            'status' => DoctorScheduleStatus::Active->value,
        ]);
        $this->assertDatabaseHas('doctor_schedules', [
            'day_of_week' => DayOfWeek::Wednesday->value,
            'start_time' => '13:00:00',
            'end_time' => '16:00:00',
            'slot_duration' => 30,
        ]);
        $this->assertDatabaseHas('doctor_schedules', [
            'day_of_week' => DayOfWeek::Friday->value,
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
        ]);

        $monday = DoctorSchedule::query()
            ->where('day_of_week', DayOfWeek::Monday)
            ->where('start_time', '09:00:00')
            ->firstOrFail();

        $this->assertSame('Test Doctor 1', $monday->doctor->display_name);
    }

    public function test_development_doctor_schedule_seeder_is_safe_to_re_run(): void
    {
        $this->seedDevelopmentSchedules();
        $this->seed(DevelopmentDoctorScheduleSeeder::class);

        $this->assertSame(5, DoctorSchedule::query()->count());
        $this->assertSame(3, Doctor::query()->count());
    }

    private function seedDevelopmentSchedules(): void
    {
        $this->seed(DevelopmentDepartmentSeeder::class);
        $this->seed(DevelopmentClinicSeeder::class);
        $this->seed(DevelopmentDoctorSeeder::class);
        $this->seed(DevelopmentDoctorScheduleSeeder::class);
    }

    private function createSchedule(
        Doctor $doctor,
        DayOfWeek $dayOfWeek,
        string $startTime,
        string $endTime,
        DoctorScheduleStatus $status = DoctorScheduleStatus::Active,
        int $slotDuration = 30,
    ): DoctorSchedule {
        return DoctorSchedule::query()->create([
            'doctor_id' => $doctor->id,
            'day_of_week' => $dayOfWeek,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'slot_duration' => $slotDuration,
            'status' => $status,
        ]);
    }

    private function developmentDoctor(string $displayName): Doctor
    {
        $department = Department::query()->create([
            'name' => 'Development Department for '.$displayName,
            'status' => DepartmentStatus::Active,
        ]);

        $clinic = Clinic::query()->create([
            'department_id' => $department->id,
            'name' => 'Development Clinic for '.$displayName,
            'status' => ClinicStatus::Active,
        ]);

        return Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'display_name' => $displayName,
            'status' => DoctorStatus::Active,
        ]);
    }
}
