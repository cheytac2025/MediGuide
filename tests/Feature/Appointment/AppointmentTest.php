<?php

namespace Tests\Feature\Appointment;

use App\Enums\AppointmentStatus;
use App\Enums\ClinicStatus;
use App\Enums\DayOfWeek;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorScheduleStatus;
use App\Enums\DoctorStatus;
use App\Enums\Sex;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\User;
use App\Services\AppointmentBookingService;
use Database\Seeders\DevelopmentClinicSeeder;
use Database\Seeders\DevelopmentDepartmentSeeder;
use Database\Seeders\DevelopmentDoctorScheduleSeeder;
use Database\Seeders\DevelopmentDoctorSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AppointmentTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-10-05';

    private const TUESDAY = '2026-10-06';

    private const THURSDAY = '2026-10-08';

    private const FRIDAY = '2026-10-09';

    private int $patientSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05 08:00:00');

        $this->seed(DevelopmentDepartmentSeeder::class);
        $this->seed(DevelopmentClinicSeeder::class);
        $this->seed(DevelopmentDoctorSeeder::class);
        $this->seed(DevelopmentDoctorScheduleSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_valid_appointment_can_be_created(): void
    {
        $patient = $this->patient();
        $doctor = $this->developmentDoctor('Test Doctor 1');
        $schedule = $this->mondaySchedule();

        $appointment = $this->book($patient, $doctor, $schedule, self::MONDAY, '09:00', 'Sore throat');

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'doctor_schedule_id' => $schedule->id,
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'patient_concern' => 'Sore throat',
            'status' => AppointmentStatus::Pending->value,
        ]);
        $this->assertTrue(
            Appointment::query()->whereKey($appointment->id)->whereDate('appointment_date', self::MONDAY)->exists(),
        );
        $this->assertSame(self::MONDAY, $appointment->appointment_date->toDateString());
    }

    public function test_new_appointment_defaults_to_pending(): void
    {
        $appointment = $this->book(
            $this->patient(),
            $this->developmentDoctor('Test Doctor 1'),
            $this->mondaySchedule(),
            self::MONDAY,
            '09:30',
        );

        $this->assertSame(AppointmentStatus::Pending, $appointment->fresh()->status);
        $this->assertSame('pending', $appointment->fresh()->status->value);
        $this->assertSame('PENDING', $appointment->fresh()->status->label());
        $this->assertNull($appointment->patient_concern);
    }

    public function test_appointment_belongs_to_patient(): void
    {
        $patient = $this->patient();
        $appointment = $this->book($patient, $this->developmentDoctor('Test Doctor 1'), $this->mondaySchedule());

        $this->assertTrue($appointment->patient->is($patient));
    }

    public function test_appointment_belongs_to_doctor(): void
    {
        $doctor = $this->developmentDoctor('Test Doctor 1');
        $appointment = $this->book($this->patient(), $doctor, $this->mondaySchedule());

        $this->assertTrue($appointment->doctor->is($doctor));
        $this->assertSame('Test Doctor 1', $appointment->doctor->display_name);
    }

    public function test_appointment_belongs_to_doctor_schedule(): void
    {
        $schedule = $this->mondaySchedule();
        $appointment = $this->book($this->patient(), $this->developmentDoctor('Test Doctor 1'), $schedule);

        $this->assertTrue($appointment->doctorSchedule->is($schedule));
    }

    public function test_patient_has_many_appointments(): void
    {
        $patient = $this->patient();
        $doctor = $this->developmentDoctor('Test Doctor 1');
        $schedule = $this->mondaySchedule();

        $first = $this->book($patient, $doctor, $schedule, self::MONDAY, '09:00');
        $second = $this->book($patient, $doctor, $schedule, self::MONDAY, '09:30');

        $appointments = $patient->appointments()->orderBy('start_time')->get();

        $this->assertCount(2, $appointments);
        $this->assertTrue($appointments->contains($first));
        $this->assertTrue($appointments->contains($second));
    }

    public function test_doctor_has_many_appointments(): void
    {
        $doctor = $this->developmentDoctor('Test Doctor 1');
        $schedule = $this->mondaySchedule();

        $first = $this->book($this->patient(), $doctor, $schedule, self::MONDAY, '09:00');
        $second = $this->book($this->patient(), $doctor, $schedule, self::MONDAY, '10:00');

        $appointments = $doctor->appointments()->orderBy('start_time')->get();

        $this->assertCount(2, $appointments);
        $this->assertTrue($appointments->contains($first));
        $this->assertTrue($appointments->contains($second));
    }

    public function test_doctor_schedule_has_many_appointments(): void
    {
        $doctor = $this->developmentDoctor('Test Doctor 1');
        $schedule = $this->mondaySchedule();

        $first = $this->book($this->patient(), $doctor, $schedule, self::MONDAY, '09:00');
        $second = $this->book($this->patient(), $doctor, $schedule, self::MONDAY, '11:00');

        $appointments = $schedule->appointments()->orderBy('start_time')->get();

        $this->assertCount(2, $appointments);
        $this->assertTrue($appointments->contains($first));
        $this->assertTrue($appointments->contains($second));
    }

    public function test_invalid_appointment_date_is_rejected(): void
    {
        $this->assertBookingRejected(
            $this->patient(),
            $this->developmentDoctor('Test Doctor 1'),
            $this->mondaySchedule(),
            '2026-02-31',
            '09:00',
            'appointment_date',
        );

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_past_date_is_rejected(): void
    {
        $this->assertBookingRejected(
            $this->patient(),
            $this->developmentDoctor('Test Doctor 1'),
            $this->mondaySchedule(),
            '2026-09-28',
            '09:00',
            'appointment_date',
        );

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_same_day_past_time_is_rejected_and_a_future_slot_can_still_be_booked(): void
    {
        Carbon::setTestNow('2026-10-05 10:15:00');

        $patient = $this->patient();
        $doctor = $this->developmentDoctor('Test Doctor 1');
        $schedule = $this->mondaySchedule();

        $this->assertBookingRejected($patient, $doctor, $schedule, self::MONDAY, '09:00', 'start_time');

        $appointment = $this->book($patient, $doctor, $schedule, self::MONDAY, '10:30');

        $this->assertSame('10:30:00', $appointment->start_time);
        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_wrong_day_of_week_is_rejected(): void
    {
        $this->assertBookingRejected(
            $this->patient(),
            $this->developmentDoctor('Test Doctor 1'),
            $this->mondaySchedule(),
            self::TUESDAY,
            '09:00',
            'appointment_date',
        );

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_doctor_schedule_mismatch_is_rejected(): void
    {
        $doctor = $this->developmentDoctor('Test Doctor 1');
        $otherSchedule = DoctorSchedule::query()
            ->where('doctor_id', $this->developmentDoctor('Test Doctor 2')->id)
            ->where('day_of_week', DayOfWeek::Tuesday)
            ->firstOrFail();

        $this->assertBookingRejected(
            $this->patient(),
            $doctor,
            $otherSchedule,
            self::TUESDAY,
            '09:00',
            'doctor_schedule_id',
        );

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_inactive_department_is_rejected(): void
    {
        $doctor = $this->developmentDoctor('Test Doctor 1');
        $doctor->clinic->department->update(['status' => DepartmentStatus::Inactive]);

        $this->assertBookingRejected(
            $this->patient(),
            $doctor,
            $this->mondaySchedule(),
            self::MONDAY,
            '09:00',
            'department',
        );

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_inactive_clinic_is_rejected(): void
    {
        $doctor = $this->developmentDoctor('Test Doctor 1');
        $doctor->clinic->update(['status' => ClinicStatus::Inactive]);

        $this->assertBookingRejected(
            $this->patient(),
            $doctor,
            $this->mondaySchedule(),
            self::MONDAY,
            '09:00',
            'clinic',
        );

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_inactive_doctor_is_rejected(): void
    {
        $doctor = $this->developmentDoctor('Test Doctor 1');
        $doctor->update(['status' => DoctorStatus::Inactive]);

        $this->assertBookingRejected(
            $this->patient(),
            $doctor,
            $this->mondaySchedule(),
            self::MONDAY,
            '09:00',
            'doctor_id',
        );

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_inactive_doctor_schedule_is_rejected(): void
    {
        $schedule = $this->mondaySchedule();
        $schedule->update(['status' => DoctorScheduleStatus::Inactive]);

        $this->assertBookingRejected(
            $this->patient(),
            $this->developmentDoctor('Test Doctor 1'),
            $schedule,
            self::MONDAY,
            '09:00',
            'doctor_schedule_id',
        );

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_start_time_before_schedule_is_rejected(): void
    {
        $this->assertBookingRejected(
            $this->patient(),
            $this->developmentDoctor('Test Doctor 1'),
            $this->mondaySchedule(),
            self::MONDAY,
            '08:30',
            'start_time',
        );

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_start_time_outside_schedule_is_rejected(): void
    {
        $this->assertBookingRejected(
            $this->patient(),
            $this->developmentDoctor('Test Doctor 1'),
            $this->mondaySchedule(),
            self::MONDAY,
            '12:30',
            'start_time',
        );

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_misaligned_start_time_is_rejected(): void
    {
        $this->assertBookingRejected(
            $this->patient(),
            $this->developmentDoctor('Test Doctor 1'),
            $this->mondaySchedule(),
            self::MONDAY,
            '09:15',
            'start_time',
        );

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_end_time_is_calculated_from_slot_duration(): void
    {
        $doctor = $this->developmentDoctor('Test Doctor 1');
        $twentyMinuteSchedule = $this->createSchedule($doctor, DayOfWeek::Friday, '09:00:00', '12:00:00', 20);

        $twentyMinuteAppointment = $this->book(
            $this->patient(),
            $doctor,
            $twentyMinuteSchedule,
            self::FRIDAY,
            '09:00',
        );
        $thirtyMinuteAppointment = $this->book(
            $this->patient(),
            $doctor,
            $this->mondaySchedule(),
            self::MONDAY,
            '10:00',
        );

        $this->assertSame(20, $twentyMinuteSchedule->slot_duration);
        $this->assertSame('09:20:00', $twentyMinuteAppointment->end_time);
        $this->assertSame('10:30:00', $thirtyMinuteAppointment->end_time);
    }

    public function test_slot_extending_beyond_schedule_end_is_rejected(): void
    {
        $doctor = $this->developmentDoctor('Test Doctor 1');
        $schedule = $this->createSchedule($doctor, DayOfWeek::Thursday, '09:00:00', '10:00:00', 45);

        $fitting = $this->book($this->patient(), $doctor, $schedule, self::THURSDAY, '09:00');

        $this->assertSame('09:45:00', $fitting->end_time);

        try {
            $this->book($this->patient(), $doctor, $schedule, self::THURSDAY, '09:45');
            $this->fail('A slot that extends beyond the schedule end should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('start_time', $exception->errors());
            $this->assertStringContainsString('beyond', $exception->errors()['start_time'][0]);
        }

        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_pending_doctor_appointment_blocks_overlapping_slot(): void
    {
        $doctor = $this->developmentDoctor('Test Doctor 1');
        $schedule = $this->mondaySchedule();
        $existingPatient = $this->patient();

        $this->place($existingPatient, $doctor, $schedule, AppointmentStatus::Pending, '09:00:00', '09:30:00');
        $this->place($existingPatient, $doctor, $schedule, AppointmentStatus::Pending, '10:10:00', '10:40:00');

        $this->assertBookingRejected($this->patient(), $doctor, $schedule, self::MONDAY, '09:00', 'start_time');
        $this->assertBookingRejected($this->patient(), $doctor, $schedule, self::MONDAY, '10:00', 'start_time');
        $this->assertBookingRejected($this->patient(), $doctor, $schedule, self::MONDAY, '10:30', 'start_time');

        $this->assertSame(2, Appointment::query()->count());
    }

    public function test_confirmed_doctor_appointment_blocks_overlapping_slot(): void
    {
        $doctor = $this->developmentDoctor('Test Doctor 1');
        $schedule = $this->mondaySchedule();

        $this->place($this->patient(), $doctor, $schedule, AppointmentStatus::Confirmed, '11:00:00', '11:30:00');

        $this->assertBookingRejected($this->patient(), $doctor, $schedule, self::MONDAY, '11:00', 'start_time');
        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_cancelled_doctor_appointment_releases_slot(): void
    {
        $patient = $this->patient();
        $doctor = $this->developmentDoctor('Test Doctor 1');
        $schedule = $this->mondaySchedule();

        $this->place($patient, $doctor, $schedule, AppointmentStatus::Cancelled, '09:00:00', '09:30:00');

        $appointment = $this->book($this->patient(), $doctor, $schedule, self::MONDAY, '09:00');

        $this->assertSame(AppointmentStatus::Pending, $appointment->status);
        $this->assertSame(2, Appointment::query()->where('start_time', '09:00:00')->count());
    }

    public function test_rejected_doctor_appointment_releases_slot(): void
    {
        $doctor = $this->developmentDoctor('Test Doctor 1');
        $schedule = $this->mondaySchedule();

        $this->place($this->patient(), $doctor, $schedule, AppointmentStatus::Rejected, '09:30:00', '10:00:00');

        $appointment = $this->book($this->patient(), $doctor, $schedule, self::MONDAY, '09:30');

        $this->assertSame(AppointmentStatus::Pending, $appointment->status);
        $this->assertSame(2, Appointment::query()->where('start_time', '09:30:00')->count());
    }

    public function test_completed_doctor_appointment_does_not_block_the_slot(): void
    {
        $doctor = $this->developmentDoctor('Test Doctor 1');
        $schedule = $this->mondaySchedule();

        $this->place($this->patient(), $doctor, $schedule, AppointmentStatus::Completed, '11:30:00', '12:00:00');

        $appointment = $this->book($this->patient(), $doctor, $schedule, self::MONDAY, '11:30');

        $this->assertSame(AppointmentStatus::Pending, $appointment->status);
        $this->assertDatabaseHas('appointments', [
            'status' => AppointmentStatus::Completed->value,
            'start_time' => '11:30:00',
        ]);
    }

    public function test_patient_overlapping_pending_appointment_is_rejected(): void
    {
        $patient = $this->patient();
        $doctor = $this->developmentDoctor('Test Doctor 1');
        $otherDoctor = $this->developmentDoctor('Test Doctor 2');
        $otherSchedule = $this->createSchedule($otherDoctor, DayOfWeek::Monday, '09:00:00', '12:00:00', 20);

        $this->book($patient, $doctor, $this->mondaySchedule(), self::MONDAY, '10:00');

        $this->assertBookingRejected($patient, $otherDoctor, $otherSchedule, self::MONDAY, '10:00', 'patient_id');
        $this->assertBookingRejected($patient, $otherDoctor, $otherSchedule, self::MONDAY, '10:20', 'patient_id');
        $this->assertSame(1, $patient->appointments()->count());
    }

    public function test_patient_overlapping_confirmed_appointment_is_rejected(): void
    {
        $patient = $this->patient();
        $doctor = $this->developmentDoctor('Test Doctor 1');
        $otherDoctor = $this->developmentDoctor('Test Doctor 2');
        $otherSchedule = $this->createSchedule($otherDoctor, DayOfWeek::Monday, '09:00:00', '12:00:00');

        $this->place($patient, $doctor, $this->mondaySchedule(), AppointmentStatus::Confirmed, '10:00:00', '10:30:00');

        $this->assertBookingRejected($patient, $otherDoctor, $otherSchedule, self::MONDAY, '10:00', 'patient_id');
        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_different_patient_can_book_different_doctor_at_the_same_time(): void
    {
        $doctor = $this->developmentDoctor('Test Doctor 1');
        $otherDoctor = $this->developmentDoctor('Test Doctor 2');
        $otherSchedule = $this->createSchedule($otherDoctor, DayOfWeek::Monday, '09:00:00', '12:00:00');

        $first = $this->book($this->patient(), $doctor, $this->mondaySchedule(), self::MONDAY, '10:00');
        $second = $this->book($this->patient(), $otherDoctor, $otherSchedule, self::MONDAY, '10:00');

        $this->assertSame('10:00:00', $first->start_time);
        $this->assertSame('10:00:00', $second->start_time);
        $this->assertNotSame($first->doctor_id, $second->doctor_id);
        $this->assertSame(2, Appointment::query()->count());
    }

    public function test_different_valid_slot_for_the_same_doctor_succeeds(): void
    {
        $patient = $this->patient();
        $doctor = $this->developmentDoctor('Test Doctor 1');
        $schedule = $this->mondaySchedule();

        $first = $this->book($patient, $doctor, $schedule, self::MONDAY, '09:00');
        $second = $this->book($patient, $doctor, $schedule, self::MONDAY, '09:30');

        $this->assertSame('09:00:00', $first->start_time);
        $this->assertSame('09:30:00', $first->end_time);
        $this->assertSame('09:30:00', $second->start_time);
        $this->assertSame('10:00:00', $second->end_time);
    }

    public function test_available_slot_generator_returns_schedule_slots(): void
    {
        $slots = $this->service()->getAvailableSlots(
            $this->developmentDoctor('Test Doctor 1'),
            $this->mondaySchedule(),
            self::MONDAY,
        );

        $this->assertSame(
            ['09:00', '09:30', '10:00', '10:30', '11:00', '11:30'],
            $slots,
        );
    }

    public function test_available_slot_generator_removes_pending_slots(): void
    {
        $doctor = $this->developmentDoctor('Test Doctor 1');
        $schedule = $this->mondaySchedule();
        $patient = $this->patient();

        $this->place($patient, $doctor, $schedule, AppointmentStatus::Pending, '09:00:00', '09:30:00');
        $this->place($patient, $doctor, $schedule, AppointmentStatus::Pending, '11:10:00', '11:40:00');

        $slots = $this->service()->getAvailableSlots($doctor, $schedule, self::MONDAY);

        $this->assertSame(['09:30', '10:00', '10:30'], $slots);
    }

    public function test_available_slot_generator_removes_confirmed_slots(): void
    {
        $doctor = $this->developmentDoctor('Test Doctor 1');
        $schedule = $this->mondaySchedule();

        $this->place($this->patient(), $doctor, $schedule, AppointmentStatus::Confirmed, '10:30:00', '11:00:00');

        $slots = $this->service()->getAvailableSlots($doctor, $schedule, self::MONDAY);

        $this->assertNotContains('10:30', $slots);
        $this->assertContains('10:00', $slots);
        $this->assertContains('11:00', $slots);
    }

    public function test_available_slot_generator_includes_cancelled_rejected_and_completed_slots(): void
    {
        $doctor = $this->developmentDoctor('Test Doctor 1');
        $schedule = $this->mondaySchedule();
        $patient = $this->patient();

        $this->place($patient, $doctor, $schedule, AppointmentStatus::Cancelled, '09:00:00', '09:30:00');
        $this->place($patient, $doctor, $schedule, AppointmentStatus::Rejected, '09:30:00', '10:00:00');
        $this->place($patient, $doctor, $schedule, AppointmentStatus::Completed, '10:00:00', '10:30:00');

        $slots = $this->service()->getAvailableSlots($doctor, $schedule, self::MONDAY);

        $this->assertSame(
            ['09:00', '09:30', '10:00', '10:30', '11:00', '11:30'],
            $slots,
        );
    }

    public function test_today_slot_generator_removes_past_times(): void
    {
        Carbon::setTestNow('2026-10-05 10:15:00');

        $slots = $this->service()->getAvailableSlots(
            $this->developmentDoctor('Test Doctor 1'),
            $this->mondaySchedule(),
            self::MONDAY,
        );

        $this->assertSame(['10:30', '11:00', '11:30'], $slots);
    }

    public function test_concurrent_bookings_for_the_same_slot_allow_only_one(): void
    {
        $doctor = $this->developmentDoctor('Test Doctor 1');
        $schedule = $this->mondaySchedule();
        $outerLevel = DB::transactionLevel();
        $levelDuringBooking = $outerLevel;

        DB::listen(function () use (&$levelDuringBooking): void {
            $levelDuringBooking = max($levelDuringBooking, DB::transactionLevel());
        });

        $winner = $this->book($this->patient(), $doctor, $schedule, self::MONDAY, '11:00');

        $this->assertBookingRejected($this->patient(), $doctor, $schedule, self::MONDAY, '11:00', 'start_time');
        $this->assertGreaterThan($outerLevel, $levelDuringBooking);
        $this->assertSame(1, Appointment::query()->where('start_time', '11:00:00')->where('status', AppointmentStatus::Pending)->count());
        $this->assertTrue($winner->is(Appointment::query()->first()));
    }

    public function test_referenced_patient_doctor_and_schedule_cannot_be_deleted(): void
    {
        $patient = $this->patient();
        $doctor = $this->developmentDoctor('Test Doctor 1');
        $schedule = $this->mondaySchedule();
        $appointment = $this->book($patient, $doctor, $schedule);

        foreach ([$schedule, $doctor, $patient] as $record) {
            try {
                $record->delete();
                $this->fail($record::class.' deletion should be restricted while appointments reference it.');
            } catch (QueryException) {
                // Expected: RESTRICT keeps historical appointments.
            }
        }

        $this->assertDatabaseHas('appointments', ['id' => $appointment->id]);
        $this->assertDatabaseHas('patients', ['id' => $patient->id]);
        $this->assertDatabaseHas('doctors', ['id' => $doctor->id]);
        $this->assertDatabaseHas('doctor_schedules', ['id' => $schedule->id]);
    }

    private function service(): AppointmentBookingService
    {
        return app(AppointmentBookingService::class);
    }

    private function book(
        Patient $patient,
        Doctor $doctor,
        DoctorSchedule $schedule,
        string $date = self::MONDAY,
        string $start = '09:00',
        ?string $concern = null,
    ): Appointment {
        return $this->service()->book($patient, $doctor, $schedule, $date, $start, $concern);
    }

    private function assertBookingRejected(
        Patient $patient,
        Doctor $doctor,
        DoctorSchedule $schedule,
        string $date,
        string $start,
        string $errorKey,
    ): void {
        try {
            $this->book($patient, $doctor, $schedule, $date, $start);
            $this->fail('The booking should have been rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors());
        }
    }

    private function place(
        Patient $patient,
        Doctor $doctor,
        DoctorSchedule $schedule,
        AppointmentStatus $status,
        string $start,
        string $end,
        string $date = self::MONDAY,
    ): Appointment {
        return Appointment::query()->create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'doctor_schedule_id' => $schedule->id,
            'appointment_date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'status' => $status,
        ]);
    }

    private function createSchedule(
        Doctor $doctor,
        DayOfWeek $dayOfWeek,
        string $startTime,
        string $endTime,
        int $slotDuration = 30,
    ): DoctorSchedule {
        return DoctorSchedule::query()->create([
            'doctor_id' => $doctor->id,
            'day_of_week' => $dayOfWeek,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'slot_duration' => $slotDuration,
            'status' => DoctorScheduleStatus::Active,
        ]);
    }

    private function mondaySchedule(): DoctorSchedule
    {
        return DoctorSchedule::query()
            ->where('doctor_id', $this->developmentDoctor('Test Doctor 1')->id)
            ->where('day_of_week', DayOfWeek::Monday)
            ->where('start_time', '09:00:00')
            ->firstOrFail();
    }

    private function developmentDoctor(string $displayName): Doctor
    {
        return Doctor::query()->where('display_name', $displayName)->firstOrFail();
    }

    private function patient(): Patient
    {
        $this->patientSequence++;

        $user = User::factory()->create([
            'email' => "appointment-patient-{$this->patientSequence}@example.com",
        ]);

        return $user->patient()->create([
            'date_of_birth' => '1990-01-15',
            'sex' => Sex::Male,
            'contact_number' => '0917'.str_pad((string) $this->patientSequence, 7, '0', STR_PAD_LEFT),
        ]);
    }
}
