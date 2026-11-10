<?php

namespace App\Services;

use App\Enums\AppointmentMessageKind;
use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\AuditOutcome;
use App\Enums\ClinicStatus;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorScheduleStatus;
use App\Enums\DoctorStatus;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorDateOverride;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\User;
use App\Services\Scheduling\EffectiveScheduleService;
use App\Support\Audit\AuditTrailService;
use App\Support\Integrity\IntegrityStatus;
use App\Support\Integrity\RecordIntegrityService;
use App\Support\Scheduling\ConsultationWindows;
use App\Support\Scheduling\EffectiveDay;
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
            $this->lockDateOverride($doctor, $date);

            $end = $this->resolveEffectiveSlotEnd($doctor, $schedule, $date, $start);
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
     * A date override replaces the weekly hours. The anchor schedule is the
     * only schedule that returns those replacement slots.
     *
     * @return list<string>
     */
    public function getAvailableSlots(
        Doctor $doctor,
        DoctorSchedule $schedule,
        string $appointmentDate,
        ?int $exceptAppointmentId = null,
    ): array {
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

        if (! $this->dateIsInsideBookingWindow($date)) {
            return [];
        }

        $day = app(EffectiveScheduleService::class)->forDate($doctor, $date);
        $slots = $this->slotsForSchedule($day, $schedule, $date);

        if ($slots === []) {
            return [];
        }

        $blocked = $this->blockingAppointments($doctor->id, $date->toDateString(), false, $exceptAppointmentId);
        $available = [];

        foreach ($slots as $slot) {
            if ($this->slotHasPassed($date, $slot['start']) || $this->isBlocked($slot['start'], $slot['end'], $blocked)) {
                continue;
            }

            $available[] = substr($slot['start'], 0, 5);
        }

        return $available;
    }

    /**
     * Open start times for a date, each paired with the schedule row that books it.
     *
     * @return list<array{start: string, schedule_id: int}>
     */
    public function openSlots(Doctor $doctor, string $appointmentDate, ?int $exceptAppointmentId = null): array
    {
        $doctor = Doctor::query()->find($doctor->getKey());

        if (! $doctor instanceof Doctor) {
            return [];
        }

        $choices = [];

        $schedules = DoctorSchedule::query()
            ->where('doctor_id', $doctor->id)
            ->where('status', DoctorScheduleStatus::Active)
            ->orderBy('id')
            ->get();

        foreach ($schedules as $schedule) {
            foreach ($this->getAvailableSlots($doctor, $schedule, $appointmentDate, $exceptAppointmentId) as $start) {
                $choices[$start] = [
                    'start' => $start,
                    'schedule_id' => $schedule->id,
                ];
            }
        }

        ksort($choices);

        return array_values($choices);
    }

    /**
     * Every start the effective schedule defines, including times that are already booked.
     *
     * @return list<array{start: string, schedule_id: int}>
     */
    public function definedSlotChoices(Doctor $doctor, string $appointmentDate): array
    {
        try {
            $date = $this->parseAppointmentDate($appointmentDate);
        } catch (ValidationException) {
            return [];
        }

        $doctor = Doctor::query()->with(['clinic.department', 'schedules'])->find($doctor->getKey());

        if (! $doctor instanceof Doctor || ! $this->dateIsInsideBookingWindow($date)) {
            return [];
        }

        $day = app(EffectiveScheduleService::class)->forDate($doctor, $date);
        $choices = [];

        foreach ($day->slots() as $slot) {
            if ($slot['schedule_id'] === null) {
                continue;
            }

            if ($slot['provisional'] && ! $this->provisionalBookingAllowed()) {
                continue;
            }

            $schedule = $doctor->schedules->firstWhere('id', $slot['schedule_id']);

            if (! $schedule instanceof DoctorSchedule || $this->inactiveReason($doctor, $schedule) !== null) {
                continue;
            }

            $choices[substr($slot['start'], 0, 5)] = [
                'start' => substr($slot['start'], 0, 5),
                'schedule_id' => (int) $slot['schedule_id'],
            ];
        }

        ksort($choices);

        return array_values($choices);
    }

    public function scheduleApplies(Doctor $doctor, DoctorSchedule $schedule, string $appointmentDate): bool
    {
        try {
            $date = $this->parseAppointmentDate($appointmentDate);
        } catch (ValidationException) {
            return false;
        }

        if ((int) $schedule->doctor_id !== (int) $doctor->id || $schedule->status !== DoctorScheduleStatus::Active) {
            return false;
        }

        $day = app(EffectiveScheduleService::class)->forDate($doctor, $date);

        if ($day->source === EffectiveDay::UNAVAILABLE) {
            return false;
        }

        if ($day->source === EffectiveDay::OVERRIDE) {
            return (int) $day->anchorScheduleId === (int) $schedule->id;
        }

        return $this->dayMatchesSchedule($date, $schedule);
    }

    /**
     * Earliest bookable start inside the current seven-day window, or null.
     *
     * @return array{date: string, time: string}|null
     */
    public function nextAvailableSlot(Doctor $doctor): ?array
    {
        $doctor = Doctor::query()->with(['clinic.department', 'schedules'])->find($doctor->getKey());

        if (! $doctor instanceof Doctor) {
            return null;
        }

        $schedules = $doctor->schedules->filter(
            fn (DoctorSchedule $schedule): bool => $schedule->status === DoctorScheduleStatus::Active,
        );

        if ($schedules->isEmpty()) {
            return null;
        }

        $date = $this->hospitalNow()->copy()->startOfDay();
        $last = $this->lastBookableDate();

        while ($date->lte($last)) {
            $earliest = null;

            foreach ($schedules as $schedule) {
                foreach ($this->getAvailableSlots($doctor, $schedule, $date->toDateString()) as $start) {
                    if ($earliest === null || $start < $earliest) {
                        $earliest = $start;
                    }
                }
            }

            if ($earliest !== null) {
                return [
                    'date' => $date->toDateString(),
                    'time' => $earliest,
                ];
            }

            $date->addDay();
        }

        return null;
    }

    /**
     * Move a pending or confirmed appointment without changing its identity or status.
     *
     * The original row is left unchanged when validation fails.
     */
    public function reschedule(
        Appointment $appointment,
        DoctorSchedule $schedule,
        string $appointmentDate,
        string $startTime,
        string $reason,
        User $actor,
    ): Appointment {
        $date = $this->parseAppointmentDate($appointmentDate);
        $start = $this->toHis($startTime);

        return DB::transaction(function () use ($appointment, $schedule, $date, $start, $reason, $actor): Appointment {
            $patient = Patient::query()->whereKey($appointment->patient_id)->lockForUpdate()->firstOrFail();
            $doctor = Doctor::query()->whereKey($appointment->doctor_id)->lockForUpdate()->firstOrFail();
            $lockedSchedule = DoctorSchedule::query()->whereKey($schedule->getKey())->lockForUpdate()->firstOrFail();
            $doctor->load('clinic.department');

            /** @var Appointment $locked */
            $locked = Appointment::query()->whereKey($appointment->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $locked->patient_id !== (int) $patient->id || (int) $locked->doctor_id !== (int) $doctor->id) {
                throw ValidationException::withMessages([
                    'appointment' => 'This appointment can no longer be rescheduled.',
                ]);
            }

            if (! $locked->status->canStaffReschedule()) {
                throw ValidationException::withMessages([
                    'appointment' => 'This appointment can no longer be rescheduled.',
                ]);
            }

            $this->assertScheduleBelongsToDoctor($doctor, $lockedSchedule);
            $this->assertHierarchyIsActive($doctor, $lockedSchedule);
            $this->assertDateIsInsideBookingWindow($date);
            $this->lockDateOverride($doctor, $date);

            if ($locked->appointment_date->toDateString() === $date->toDateString()
                && ConsultationWindows::normalize((string) $locked->start_time) === $start) {
                throw ValidationException::withMessages([
                    'start_time' => 'Choose a different date or time. This appointment is already scheduled then.',
                ]);
            }

            $end = $this->resolveEffectiveSlotEnd($doctor, $lockedSchedule, $date, $start);
            $this->assertStartHasNotPassed($date, $start);
            $this->assertDoctorIsAvailable($doctor, $date, $start, $end, $locked->id);
            $this->assertPatientIsAvailable($patient, $date, $start, $end, $locked->id);

            $offered = $this->openSlots($doctor, $date->toDateString(), $locked->id);
            $match = collect($offered)->first(
                fn (array $slot): bool => $slot['start'] === substr($start, 0, 5) && (int) $slot['schedule_id'] === (int) $lockedSchedule->id,
            );

            if ($match === null) {
                throw ValidationException::withMessages([
                    'start_time' => 'That time is no longer available.',
                ]);
            }

            $fromDate = $locked->appointment_date->toDateString();
            $fromStart = ConsultationWindows::normalize((string) $locked->start_time);
            $fromEnd = ConsultationWindows::normalize((string) $locked->end_time);

            $locked->update([
                'doctor_schedule_id' => $lockedSchedule->id,
                'appointment_date' => $date->toDateString(),
                'start_time' => $start,
                'end_time' => $end,
            ]);

            app(AppointmentMessageRecorder::class)->record(
                $locked,
                AppointmentMessageKind::RescheduleReason,
                $reason,
                $actor,
                [
                    'from_date' => $fromDate,
                    'from_start' => $fromStart,
                    'from_end' => $fromEnd,
                    'to_date' => $date->toDateString(),
                    'to_start' => $start,
                    'to_end' => $end,
                ],
            );

            app(AuditTrailService::class)->recordFor(
                AuditAction::AppointmentRescheduled,
                AuditOutcome::Success,
                $locked,
                [
                    'doctor_id' => (int) $locked->doctor_id,
                    'patient_id' => (int) $locked->patient_id,
                    'status' => $locked->status->value,
                    'from_date' => $fromDate,
                    'to_date' => $date->toDateString(),
                ],
                $actor,
            );

            return $locked->fresh();
        });
    }

    public function formatSlotLabel(string $date, string $time): string
    {
        return Carbon::parse($date.' '.$time, config('app.timezone'))->format('D, M j, Y, g:i A');
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
                'provisional_schedule' => 'This demonstration schedule is not available for booking in this environment.',
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

        if ($schedule->provisional_demonstration && ! $this->provisionalBookingAllowed()) {
            return 'provisional_schedule';
        }

        return null;
    }

    /**
     * Poster demonstration schedules stay hidden unless this process is the
     * local MediGuide database with the explicit local flag enabled.
     */
    private function provisionalBookingAllowed(): bool
    {
        if (config('hospital.provisional_local_booking') !== true) {
            return false;
        }

        $connection = (string) config('database.default');

        if ($connection === 'sqlite') {
            return app()->environment('testing');
        }

        $host = (string) config("database.connections.{$connection}.host");

        return app()->environment('local')
            && in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
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

    private function resolveEffectiveSlotEnd(Doctor $doctor, DoctorSchedule $schedule, Carbon $date, string $start): string
    {
        $day = app(EffectiveScheduleService::class)->forDate($doctor, $date);

        if ($day->source === EffectiveDay::UNAVAILABLE) {
            throw ValidationException::withMessages([
                'appointment_date' => 'The doctor is not available on this date.',
            ]);
        }

        if ($day->source === EffectiveDay::OVERRIDE) {
            if ((int) $day->anchorScheduleId !== (int) $schedule->id) {
                throw ValidationException::withMessages([
                    'doctor_schedule_id' => 'The selected schedule does not match this date.',
                ]);
            }

            foreach ($day->slots() as $slot) {
                if ($slot['start'] !== $start || (int) $slot['schedule_id'] !== (int) $schedule->id) {
                    continue;
                }

                if ($slot['provisional'] && ! $this->provisionalBookingAllowed()) {
                    throw ValidationException::withMessages([
                        'provisional_schedule' => 'This demonstration schedule is not available for booking in this environment.',
                    ]);
                }

                return $slot['end'];
            }

            throw ValidationException::withMessages([
                'start_time' => 'The start time is not a valid slot for this schedule.',
            ]);
        }

        $this->assertDayMatchesSchedule($date, $schedule);

        return $this->resolveSlotEnd($schedule, $start);
    }

    /**
     * @return list<array{start: string, end: string}>
     */
    private function slotsForSchedule(EffectiveDay $day, DoctorSchedule $schedule, Carbon $date): array
    {
        if ($day->source === EffectiveDay::UNAVAILABLE) {
            return [];
        }

        if ($day->source === EffectiveDay::OVERRIDE) {
            if ((int) $day->anchorScheduleId !== (int) $schedule->id) {
                return [];
            }

            $slots = [];

            foreach ($day->slots() as $slot) {
                if ((int) $slot['schedule_id'] !== (int) $schedule->id) {
                    continue;
                }

                if ($slot['provisional'] && ! $this->provisionalBookingAllowed()) {
                    continue;
                }

                $slots[] = [
                    'start' => $slot['start'],
                    'end' => $slot['end'],
                ];
            }

            return $slots;
        }

        if (! $this->dayMatchesSchedule($date, $schedule)) {
            return [];
        }

        return $this->scheduleSlots($schedule);
    }

    private function lockDateOverride(Doctor $doctor, Carbon $date): void
    {
        DoctorDateOverride::query()
            ->where('doctor_id', $doctor->id)
            ->whereDate('override_date', $date->toDateString())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
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

    private function assertDoctorIsAvailable(
        Doctor $doctor,
        Carbon $date,
        string $start,
        string $end,
        ?int $exceptAppointmentId = null,
    ): void {
        $blocked = $this->blockingAppointments($doctor->id, $date->toDateString(), true, $exceptAppointmentId);

        if ($this->isBlocked($start, $end, $blocked)) {
            throw ValidationException::withMessages([
                'start_time' => 'This doctor already has an appointment that overlaps the selected time.',
            ]);
        }
    }

    private function assertPatientIsAvailable(
        Patient $patient,
        Carbon $date,
        string $start,
        string $end,
        ?int $exceptAppointmentId = null,
    ): void {
        $query = Appointment::query()
            ->where('patient_id', $patient->id)
            ->whereDate('appointment_date', $date->toDateString())
            ->orderBy('id')
            ->lockForUpdate();

        if ($exceptAppointmentId !== null) {
            $query->whereKeyNot($exceptAppointmentId);
        }

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
    private function blockingAppointments(int $doctorId, string $date, bool $lock = false, ?int $exceptAppointmentId = null): Collection
    {
        $query = Appointment::query()
            ->where('doctor_id', $doctorId)
            ->whereDate('appointment_date', $date)
            ->orderBy('id');

        if ($exceptAppointmentId !== null) {
            $query->whereKeyNot($exceptAppointmentId);
        }

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
