<?php

namespace App\Services\HospitalImport;

use App\Enums\AppointmentStatus;
use App\Enums\ClinicStatus;
use App\Enums\DayOfWeek;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorScheduleStatus;
use App\Enums\DoctorStatus;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Services\AppointmentBookingService;
use App\Support\HospitalImport\QueenMaryClinicScheduleCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only preview of poster hours against current schedules and appointments.
 *
 * This service does not create schedules, change appointments, or mark poster
 * hours as hospital-verified. A row becomes "would be online-bookable" only
 * when the caller supplies explicit test or review approvals. The default
 * preview supplies none.
 */
class HospitalScheduleActivationPreview
{
    public function __construct(
        private AppointmentBookingService $booking,
    ) {}

    /**
     * @param  array{
     *     proposals?: list<array<string, mixed>>,
     *     approved_durations?: array<string, int>,
     *     authorized_keys?: list<string>,
     *     operationally_valid_keys?: list<string>,
     *     confirmed_identity_keys?: list<string>
     * }  $options
     * @return array<string, mixed>
     */
    public function preview(array $options = []): array
    {
        $proposals = $options['proposals'] ?? QueenMaryClinicScheduleCatalog::doctors();
        $approvedDurations = $options['approved_durations'] ?? [];
        $authorized = array_fill_keys($options['authorized_keys'] ?? [], true);
        $operational = array_fill_keys($options['operationally_valid_keys'] ?? [], true);
        $confirmed = array_fill_keys($options['confirmed_identity_keys'] ?? [], true);

        $doctors = Doctor::query()->with('clinic.department')->get()->keyBy(
            fn (Doctor $doctor): string => mb_strtolower($doctor->display_name),
        );

        $rows = [];

        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }

            $rows = array_merge($rows, $this->rowsFor(
                $proposal,
                $doctors,
                $approvedDurations,
                $authorized,
                $operational,
                $confirmed,
            ));
        }

        return [
            'dry_run' => true,
            'writes' => false,
            'source' => QueenMaryClinicScheduleCatalog::SOURCE_TITLE,
            'source_note' => QueenMaryClinicScheduleCatalog::SOURCE_NOTE,
            'verification' => 'poster_reference_not_hospital_confirmed',
            'timezone' => (string) config('app.timezone'),
            'booking_window_days' => $this->booking->bookingWindowDays(),
            'rows' => $rows,
            'online_bookable_count' => count(array_filter(
                $rows,
                fn (array $row): bool => $row['would_become_online_bookable'] === true,
            )),
        ];
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @param  Collection<string, Doctor>  $doctors
     * @param  array<string, int>  $approvedDurations
     * @param  array<string, true>  $authorized
     * @param  array<string, true>  $operational
     * @param  array<string, true>  $confirmed
     * @return list<array<string, mixed>>
     */
    private function rowsFor(
        array $proposal,
        Collection $doctors,
        array $approvedDurations,
        array $authorized,
        array $operational,
        array $confirmed,
    ): array {
        $key = (string) ($proposal['key'] ?? '');
        $name = (string) ($proposal['display_name'] ?? '');
        $mode = (string) ($proposal['booking_mode'] ?? 'fixed');
        $doctor = $doctors->get(mb_strtolower($name));
        $duration = $approvedDurations[$key] ?? null;
        $duration = is_int($duration) && $duration > 0 ? $duration : null;

        $windows = $this->windowsFor($proposal, $mode);

        if ($windows === []) {
            return [$this->row(
                $proposal,
                $doctor,
                $mode,
                null,
                $duration,
                isset($authorized[$key]),
                isset($operational[$key]),
                isset($confirmed[$key]),
                'no_fixed_hours',
            )];
        }

        $rows = [];

        foreach ($windows as $window) {
            $rows[] = $this->row(
                $proposal,
                $doctor,
                $mode,
                $window,
                $duration,
                isset($authorized[$key]),
                isset($operational[$key]),
                isset($confirmed[$key]),
                $mode === 'fixed' ? 'hospital_provided_poster_hours' : 'appointment_only_listed_hours',
            );
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return list<array{day: mixed, start: mixed, end: mixed}>
     */
    private function windowsFor(array $proposal, string $mode): array
    {
        $fixed = $proposal['schedules'] ?? [];
        $listed = $proposal['listed_hours'] ?? [];
        $windows = $mode === 'fixed' ? $fixed : $listed;

        return is_array($windows) ? array_values(array_filter($windows, 'is_array')) : [];
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @param  array{day: mixed, start: mixed, end: mixed}|null  $window
     * @return array<string, mixed>
     */
    private function row(
        array $proposal,
        ?Doctor $doctor,
        string $mode,
        ?array $window,
        ?int $duration,
        bool $authorized,
        bool $operational,
        bool $confirmed,
        string $classification,
    ): array {
        $key = (string) ($proposal['key'] ?? '');
        $day = $window['day'] ?? null;
        $day = $day instanceof DayOfWeek ? $day : null;
        $start = is_string($window['start'] ?? null) ? $this->his($window['start']) : null;
        $end = is_string($window['end'] ?? null) ? $this->his($window['end']) : null;
        $invalid = $window !== null && ($day === null || $start === null || $end === null || $start >= $end);

        $scheduleConflicts = [];
        $appointmentConflicts = [];

        if (! $invalid && $doctor instanceof Doctor && $day instanceof DayOfWeek && $start !== null && $end !== null) {
            $scheduleConflicts = $this->scheduleConflicts($doctor, $day, $start, $end);
            $appointmentConflicts = $this->appointmentConflicts($doctor, $day, $start, $end);
        }

        $clinic = $doctor?->clinic;
        $department = $clinic?->department;
        $activeDoctor = $doctor instanceof Doctor && $doctor->status === DoctorStatus::Active;
        $activeClinic = $clinic !== null && $clinic->status === ClinicStatus::Active;
        $activeDepartment = $department !== null && $department->status === DepartmentStatus::Active;
        $durationFits = $duration !== null && $start !== null && $end !== null && ! $invalid
            && $this->seconds($start) + ($duration * 60) <= $this->seconds($end);

        $missing = [];

        if (! $confirmed) {
            $missing[] = 'doctor_identity';
            $missing[] = 'specialization';
            $missing[] = 'clinic_assignment';
        }

        if ($window === null) {
            $missing[] = 'consultation_day';
            $missing[] = 'consultation_hours';
        }

        if ($invalid) {
            $missing[] = 'valid_consultation_time';
        }

        if ($duration === null) {
            $missing[] = 'approved_appointment_duration';
        } elseif (! $durationFits) {
            $missing[] = 'duration_fits_consultation_window';
        }

        if ($mode !== 'fixed') {
            $missing[] = 'appointment_only_policy';
        }

        if (! $operational) {
            $missing[] = 'operational_validity';
        }

        if (! $authorized) {
            $missing[] = 'hospital_authorization';
            $missing[] = 'online_booking_permission';
        }

        if (! $doctor instanceof Doctor) {
            $missing[] = 'imported_doctor_record';
        } elseif (! $activeDoctor) {
            $missing[] = 'active_doctor';
        }

        if (! $activeClinic) {
            $missing[] = 'active_clinic';
        }

        if (! $activeDepartment) {
            $missing[] = 'active_department';
        }

        if ($scheduleConflicts !== []) {
            $missing[] = 'schedule_conflict_resolution';
        }

        if ($appointmentConflicts !== []) {
            $missing[] = 'appointment_conflict_resolution';
        }

        $wouldBook = $missing === []
            && $mode === 'fixed'
            && ! $invalid
            && $durationFits
            && $scheduleConflicts === []
            && $appointmentConflicts === [];

        return [
            'doctor_key' => $key,
            'doctor' => (string) ($proposal['display_name'] ?? ''),
            'specialization' => (string) ($proposal['specialization'] ?? ''),
            'clinic' => $clinic?->name,
            'department' => $department?->name,
            'consultation_day' => $day?->value,
            'start' => $start === null ? null : substr($start, 0, 5),
            'end' => $end === null ? null : substr($end, 0, 5),
            'booking_mode' => $mode,
            'poster_classification' => $classification,
            'proposed_slot_duration' => $duration,
            'existing_schedule_conflicts' => $scheduleConflicts,
            'existing_appointment_conflicts' => $appointmentConflicts,
            'invalid_consultation_time' => $invalid,
            'would_become_online_bookable' => $wouldBook,
            'missing_hospital_approvals' => $missing,
            'matched_doctor_id' => $doctor?->id,
        ];
    }

    /**
     * @return list<string>
     */
    private function scheduleConflicts(Doctor $doctor, DayOfWeek $day, string $start, string $end): array
    {
        $conflicts = [];

        $schedules = DoctorSchedule::query()
            ->where('doctor_id', $doctor->id)
            ->where('day_of_week', $day)
            ->get();

        foreach ($schedules as $schedule) {
            $existingStart = $this->his((string) $schedule->start_time);
            $existingEnd = $this->his((string) $schedule->end_time);

            if ($existingStart === null || $existingEnd === null) {
                continue;
            }

            $sameWindow = $existingStart === $start && $existingEnd === $end;

            if ($sameWindow) {
                $conflicts[] = $schedule->status === DoctorScheduleStatus::Active
                    ? 'duplicate_active_schedule'
                    : 'duplicate_inactive_schedule';

                continue;
            }

            if ($schedule->status === DoctorScheduleStatus::Active && $existingStart < $end && $existingEnd > $start) {
                $conflicts[] = 'overlapping_active_schedule';
            }
        }

        return array_values(array_unique($conflicts));
    }

    /**
     * @return list<string>
     */
    private function appointmentConflicts(Doctor $doctor, DayOfWeek $day, string $start, string $end): array
    {
        $today = Carbon::now((string) config('app.timezone'))->startOfDay();
        $conflicts = [];

        $appointments = Appointment::query()
            ->where('doctor_id', $doctor->id)
            ->whereIn('status', [AppointmentStatus::Pending, AppointmentStatus::Confirmed])
            ->whereDate('appointment_date', '>=', $today->toDateString())
            ->get();

        foreach ($appointments as $appointment) {
            $date = $appointment->appointment_date instanceof Carbon
                ? $appointment->appointment_date->copy()->timezone((string) config('app.timezone'))
                : Carbon::parse((string) $appointment->appointment_date, (string) config('app.timezone'));

            if (strtolower($date->format('l')) !== $day->value) {
                continue;
            }

            $appointmentStart = $this->his((string) $appointment->start_time);
            $appointmentEnd = $this->his((string) $appointment->end_time);

            if ($appointmentStart === null || $appointmentEnd === null) {
                continue;
            }

            if ($appointmentStart < $end && $appointmentEnd > $start) {
                $conflicts[] = 'pending_or_confirmed_appointment';
            }
        }

        return array_values(array_unique($conflicts));
    }

    private function his(string $time): ?string
    {
        $time = trim($time);

        if (preg_match('/^\d{2}:\d{2}$/', $time) === 1) {
            $time .= ':00';
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time) !== 1) {
            return null;
        }

        try {
            $parsed = Carbon::createFromFormat('!H:i:s', $time);
        } catch (\Throwable) {
            return null;
        }

        if (! $parsed instanceof Carbon || $parsed->format('H:i:s') !== $time) {
            return null;
        }

        return $parsed->format('H:i:s');
    }

    private function seconds(string $time): int
    {
        [$hours, $minutes, $seconds] = array_map(intval(...), explode(':', $time));

        return ($hours * 3600) + ($minutes * 60) + $seconds;
    }
}
