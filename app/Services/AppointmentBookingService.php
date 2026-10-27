<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\AuditOutcome;
use App\Enums\ClinicStatus;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorScheduleStatus;
use App\Enums\DoctorStatus;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Support\Audit\AuditTrailService;
use App\Support\Integrity\IntegrityStatus;
use App\Support\Integrity\RecordIntegrityService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Validates and creates patient appointments.
 *
 * End time is always derived from the doctor schedule slot duration.
 * Pending and confirmed appointments block a doctor slot. Cancelled, rejected,
 * and completed appointments do not.
 */
class AppointmentBookingService
{
    public const PASSED_SLOT_MESSAGE = 'This appointment time has already passed. Please select another available time.';

    public function book(
        Patient $patient,
        Doctor $doctor,
        DoctorSchedule $schedule,
        string $appointmentDate,
        string $startTime,
        ?string $patientConcern = null,
    ): Appointment {
        $date = $this->parseAppointmentDate($appointmentDate);
        $start = $this->toHis($startTime);
        $concern = $this->normalizeConcern($patientConcern);

        return DB::transaction(function () use ($patient, $doctor, $schedule, $date, $start, $concern): Appointment {
            [$patient, $doctor, $schedule] = $this->lockForBooking($patient, $doctor, $schedule);

            $this->assertScheduleBelongsToDoctor($doctor, $schedule);
            $this->assertHierarchyIsActive($doctor, $schedule);
            $this->assertDateIsInsideBookingWindow($date);
            $this->assertDayMatchesSchedule($date, $schedule);

            $end = $this->resolveSlotEnd($schedule, $start);
            $this->assertStartHasNotPassed($date, $start);
            $this->assertDoctorIsAvailable($doctor, $date, $start, $end);
            $this->assertPatientIsAvailable($patient, $date, $start, $end);

            $appointment = Appointment::query()->create([
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'doctor_schedule_id' => $schedule->id,
                'appointment_date' => $date->toDateString(),
                'start_time' => $start,
                'end_time' => $end,
                'patient_concern' => $concern,
                'status' => AppointmentStatus::Pending,
            ]);

            app(AuditTrailService::class)->recordFor(
                AuditAction::AppointmentCreated,
                AuditOutcome::Success,
                $appointment,
                [
                    'doctor_id' => (int) $appointment->doctor_id,
                    'patient_id' => (int) $appointment->patient_id,
                    'schedule_id' => (int) $appointment->doctor_schedule_id,
                    'status' => AppointmentStatus::Pending->value,
                ],
                $patient->user,
            );

            return $appointment;
        });
    }

    /**
     * Bookable start times (H:i) for a doctor schedule on a date.
     *
     * @return list<string>
     */
    public function getAvailableSlots(Doctor $doctor, DoctorSchedule $schedule, string $appointmentDate): array
    {
        try {
            $date = $this->parseAppointmentDate($appointmentDate);
        } catch (ValidationException) {
            return [];
        }

        $doctor = Doctor::query()->with('clinic.department')->find($doctor->getKey());
        $schedule = DoctorSchedule::query()->find($schedule->getKey());

        if (! $doctor instanceof Doctor || ! $schedule instanceof DoctorSchedule) {
            return [];
        }

        if ((int) $schedule->doctor_id !== (int) $doctor->id) {
            return [];
        }

        if ($this->inactiveReason($doctor, $schedule) !== null) {
            return [];
        }

        if (! $this->dateIsInsideBookingWindow($date) || ! $this->dayMatchesSchedule($date, $schedule)) {
            return [];
        }

        $blocked = $this->blockingAppointments($doctor->id, $date->toDateString());
        $available = [];

        foreach ($this->scheduleSlots($schedule) as $slot) {
            if ($this->slotHasPassed($date, $slot['start']) || $this->isBlocked($slot['start'], $slot['end'], $blocked)) {
                continue;
            }

            $available[] = substr($slot['start'], 0, 5);
        }

        return $available;
    }

    /**
     * Every start time (H:i) the schedule defines, regardless of availability.
     *
     * @return list<string>
     */
    public function getScheduleSlotStarts(DoctorSchedule $schedule): array
    {
        return array_map(
            fn (array $slot): string => substr($slot['start'], 0, 5),
            $this->scheduleSlots($schedule),
        );
    }

    /**
     * Days after today that a patient may still book. Today is day 0.
     */
    public function bookingWindowDays(): int
    {
        $days = config('appointments.booking_window_days', 7);

        if (! is_numeric($days)) {
            return 7;
        }

        return max(0, (int) $days);
    }

    /**
     * Last calendar date a patient may book, in the hospital timezone.
     */
    public function lastBookableDate(): Carbon
    {
        return $this->hospitalNow()->copy()->startOfDay()->addDays($this->bookingWindowDays());
    }

    /**
     * True when this start is already due on today's hospital date.
     * Future dates return false so their normal availability rules still apply.
     */
    public function appointmentStartHasPassed(string $appointmentDate, string $startTime): bool
    {
        if (trim($startTime) === '') {
            return false;
        }

        try {
            return $this->slotHasPassed(
                $this->parseAppointmentDate($appointmentDate),
                $this->toHis($startTime),
            );
        } catch (ValidationException) {
            return false;
        }
    }

    /**
     * @return array{0: Patient, 1: Doctor, 2: DoctorSchedule}
     */
    private function lockForBooking(Patient $patient, Doctor $doctor, DoctorSchedule $schedule): array
    {
        $lockedPatient = Patient::query()->whereKey($patient->getKey())->lockForUpdate()->firstOrFail();
        $lockedDoctor = Doctor::query()->whereKey($doctor->getKey())->lockForUpdate()->firstOrFail();
        $lockedSchedule = DoctorSchedule::query()->whereKey($schedule->getKey())->lockForUpdate()->firstOrFail();
        $lockedDoctor->load('clinic.department');

        return [$lockedPatient, $lockedDoctor, $lockedSchedule];
    }

    private function assertScheduleBelongsToDoctor(Doctor $doctor, DoctorSchedule $schedule): void
    {
        if ((int) $schedule->doctor_id !== (int) $doctor->id) {
            throw ValidationException::withMessages([
                'doctor_schedule_id' => 'The selected schedule does not belong to the selected doctor.',
            ]);
        }
    }

    private function assertHierarchyIsActive(Doctor $doctor, DoctorSchedule $schedule): void
    {
        $reason = $this->inactiveReason($doctor, $schedule);

        if ($reason === null) {
            return;
        }

        throw ValidationException::withMessages([
            $reason => match ($reason) {
                'department' => 'The department is not active.',
                'clinic' => 'The clinic is not active.',
                'doctor_id' => 'The doctor is not active.',
                default => 'The schedule is not active.',
            },
        ]);
    }

    private function inactiveReason(Doctor $doctor, DoctorSchedule $schedule): ?string
    {
        $clinic = $doctor->clinic;
        $department = $clinic?->department;

        if ($department === null || $department->status !== DepartmentStatus::Active) {
            return 'department';
        }

        if ($clinic->status !== ClinicStatus::Active) {
            return 'clinic';
        }

        if ($doctor->status !== DoctorStatus::Active) {
            return 'doctor_id';
        }

        if ($schedule->status !== DoctorScheduleStatus::Active) {
            return 'doctor_schedule_id';
        }

        return null;
    }

    private function assertDateIsInsideBookingWindow(Carbon $date): void
    {
        if ($date->toDateString() < $this->hospitalNow()->toDateString()) {
            throw ValidationException::withMessages([
                'appointment_date' => 'The appointment date cannot be in the past.',
            ]);
        }

        if ($date->toDateString() > $this->lastBookableDate()->toDateString()) {
            throw ValidationException::withMessages([
                'appointment_date' => 'The appointment date is outside the booking window.',
            ]);
        }
    }

    private function dateIsInsideBookingWindow(Carbon $date): bool
    {
        $day = $date->toDateString();

        return $day >= $this->hospitalNow()->toDateString()
            && $day <= $this->lastBookableDate()->toDateString();
    }

    private function assertDayMatchesSchedule(Carbon $date, DoctorSchedule $schedule): void
    {
        if (! $this->dayMatchesSchedule($date, $schedule)) {
            throw ValidationException::withMessages([
                'appointment_date' => 'The appointment date does not match the schedule day.',
            ]);
        }
    }

    private function dayMatchesSchedule(Carbon $date, DoctorSchedule $schedule): bool
    {
        return strtolower($date->format('l')) === $schedule->day_of_week->value;
    }

    private function resolveSlotEnd(DoctorSchedule $schedule, string $start): string
    {
        foreach ($this->scheduleSlots($schedule) as $slot) {
            if ($slot['start'] === $start) {
                return $slot['end'];
            }
        }

        $startSeconds = $this->secondsSinceMidnight($start);
        $scheduleStart = $this->secondsSinceMidnight((string) $schedule->start_time);
        $scheduleEnd = $this->secondsSinceMidnight((string) $schedule->end_time);

        if ($startSeconds < $scheduleStart) {
            throw ValidationException::withMessages([
                'start_time' => 'The start time is before the schedule starts.',
            ]);
        }

        if ($startSeconds >= $scheduleEnd) {
            throw ValidationException::withMessages([
                'start_time' => 'The start time is outside the schedule.',
            ]);
        }

        $durationSeconds = $schedule->slot_duration * 60;
        $offset = $startSeconds - $scheduleStart;

        if ($durationSeconds < 1 || $offset % $durationSeconds !== 0) {
            throw ValidationException::withMessages([
                'start_time' => 'The start time is not a valid slot for this schedule.',
            ]);
        }

        throw ValidationException::withMessages([
            'start_time' => 'The appointment would extend beyond the schedule end time.',
        ]);
    }

    private function assertStartHasNotPassed(Carbon $date, string $start): void
    {
        if ($this->slotHasPassed($date, $start)) {
            throw ValidationException::withMessages([
                'start_time' => self::PASSED_SLOT_MESSAGE,
            ]);
        }
    }

    private function assertDoctorIsAvailable(Doctor $doctor, Carbon $date, string $start, string $end): void
    {
        $blocked = $this->blockingAppointments($doctor->id, $date->toDateString(), lock: true);

        if ($this->isBlocked($start, $end, $blocked)) {
            throw ValidationException::withMessages([
                'start_time' => 'This doctor already has an appointment that overlaps the selected time.',
            ]);
        }
    }

    private function assertPatientIsAvailable(Patient $patient, Carbon $date, string $start, string $end): void
    {
        $query = Appointment::query()
            ->where('patient_id', $patient->id)
            ->whereDate('appointment_date', $date->toDateString())
            ->lockForUpdate();

        $blocked = $query->get()->filter(fn (Appointment $appointment): bool => $this->occupiesSlot($appointment))->values();
        $blocked = $this->withDisplacedSlots(
            $blocked,
            app(RecordIntegrityService::class)->displacedPatientSlots($patient->id, $date->toDateString(), true),
        );

        if ($this->isBlocked($start, $end, $blocked)) {
            throw ValidationException::withMessages([
                'patient_id' => 'This patient already has an appointment that overlaps the selected time.',
            ]);
        }
    }

    /**
     * @return list<array{start: string, end: string}>
     */
    private function scheduleSlots(DoctorSchedule $schedule): array
    {
        if ($schedule->slot_duration < 1) {
            return [];
        }

        $duration = $schedule->slot_duration * 60;
        $cursor = $this->secondsSinceMidnight((string) $schedule->start_time);
        $scheduleEnd = $this->secondsSinceMidnight((string) $schedule->end_time);
        $slots = [];

        while ($cursor + $duration <= $scheduleEnd) {
            $slots[] = [
                'start' => $this->secondsToHis($cursor),
                'end' => $this->secondsToHis($cursor + $duration),
            ];
            $cursor += $duration;
        }

        return $slots;
    }

    /**
     * @return Collection<int, Appointment>
     */
    private function blockingAppointments(int $doctorId, string $date, bool $lock = false): Collection
    {
        $query = Appointment::query()
            ->where('doctor_id', $doctorId)
            ->whereDate('appointment_date', $date);

        if ($lock) {
            $query->lockForUpdate();
        }

        $blocked = $query->get()->filter(fn (Appointment $appointment): bool => $this->occupiesSlot($appointment))->values();

        return $this->withDisplacedSlots(
            $blocked,
            app(RecordIntegrityService::class)->displacedSlots($doctorId, $date, $lock),
        );
    }

    /**
     * @param  Collection<int, Appointment>  $blocked
     * @param  list<array{start: string, end: string}>  $slots
     * @return Collection<int, Appointment>
     */
    private function withDisplacedSlots(Collection $blocked, array $slots): Collection
    {
        foreach ($slots as $slot) {
            $blocked->push(new Appointment([
                'start_time' => $slot['start'],
                'end_time' => $slot['end'],
            ]));
        }

        return $blocked;
    }

    /**
     * A failed seal still occupies the slot. Trusting a tampered status would
     * free a time the hospital can no longer verify. Strict mode also keeps
     * an unsigned row occupied until it has been enrolled.
     */
    private function occupiesSlot(Appointment $appointment): bool
    {
        $status = app(RecordIntegrityService::class)->statusOf($appointment);

        if ($status === IntegrityStatus::Invalid) {
            return true;
        }

        if ($status === IntegrityStatus::Unsigned && config('integrity.strict')) {
            return true;
        }

        return in_array($appointment->status, $this->blockingStatuses(), true);
    }

    /**
     * @return list<AppointmentStatus>
     */
    private function blockingStatuses(): array
    {
        return [
            AppointmentStatus::Pending,
            AppointmentStatus::Confirmed,
        ];
    }

    /**
     * @param  Collection<int, Appointment>  $appointments
     */
    private function isBlocked(string $start, string $end, Collection $appointments): bool
    {
        foreach ($appointments as $appointment) {
            if ($this->overlaps($start, $end, (string) $appointment->start_time, (string) $appointment->end_time)) {
                return true;
            }
        }

        return false;
    }

    private function overlaps(string $start, string $end, string $otherStart, string $otherEnd): bool
    {
        return $this->toHis($start) < $this->toHis($otherEnd)
            && $this->toHis($end) > $this->toHis($otherStart);
    }

    /**
     * A slot on today's hospital date is unavailable once its start time is reached.
     * Future dates are left to the schedule and existing appointments.
     */
    private function slotHasPassed(Carbon $date, string $start): bool
    {
        $now = $this->hospitalNow();

        if ($date->toDateString() !== $now->toDateString()) {
            return false;
        }

        try {
            $slotAt = Carbon::parse($date->toDateString().' '.$this->toHis($start), config('app.timezone'));
        } catch (\Throwable) {
            return true;
        }

        if (! $slotAt instanceof CarbonInterface) {
            return true;
        }

        return $slotAt->lte($now);
    }

    private function hospitalNow(): Carbon
    {
        return Carbon::now(config('app.timezone'));
    }

    private function parseAppointmentDate(string $appointmentDate): Carbon
    {
        $value = trim($appointmentDate);

        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', $value, config('app.timezone'));
        } catch (\Throwable) {
            $parsed = null;
        }

        if (! $parsed instanceof Carbon || $parsed->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([
                'appointment_date' => 'The appointment date is invalid.',
            ]);
        }

        return $parsed->startOfDay();
    }

    private function toHis(string $time): string
    {
        $value = trim($time);

        if (preg_match('/^\d{2}:\d{2}$/', $value) === 1) {
            $value .= ':00';
        }

        try {
            $parsed = Carbon::createFromFormat('!H:i:s', $value);
        } catch (\Throwable) {
            $parsed = null;
        }

        if (! $parsed instanceof Carbon || $parsed->format('H:i:s') !== $value) {
            throw ValidationException::withMessages([
                'start_time' => 'The appointment start time is invalid.',
            ]);
        }

        return $parsed->format('H:i:s');
    }

    private function secondsSinceMidnight(string $time): int
    {
        [$hours, $minutes, $seconds] = array_map(intval(...), explode(':', $this->toHis($time)));

        return ($hours * 3600) + ($minutes * 60) + $seconds;
    }

    private function secondsToHis(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $remainingSeconds);
    }

    private function normalizeConcern(?string $concern): ?string
    {
        if ($concern === null) {
            return null;
        }

        $concern = trim($concern);

        return $concern === '' ? null : $concern;
    }
}
