<?php

namespace App\Services\Scheduling;

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\AuditOutcome;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorDateOverride;
use App\Models\User;
use App\Support\Audit\AuditTrailService;
use App\Support\Scheduling\AppointmentOnlyDoctors;
use App\Support\Scheduling\ConsultationWindows;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves a single-date schedule without changing the weekly hours.
 */
class DoctorDateOverrideService
{
    public function __construct(
        private readonly EffectiveScheduleService $effective,
    ) {}

    /**
     * @param  list<array{start_time: string, end_time: string, slot_duration: int}>  $windows
     */
    public function save(Doctor $doctor, string $date, bool $unavailable, array $windows, User $actor): DoctorDateOverride
    {
        $day = $this->date($date);

        return DB::transaction(function () use ($doctor, $day, $unavailable, $windows, $actor): DoctorDateOverride {
            /** @var Doctor $lockedDoctor */
            $lockedDoctor = Doctor::query()->whereKey($doctor->getKey())->lockForUpdate()->firstOrFail();
            $lockedDoctor->load('schedules');

            $this->guardAppointmentOnly($lockedDoctor);

            $normalized = $unavailable ? [] : $this->normalizeWindows($windows);
            $this->guardAppointments($lockedDoctor, $day, $normalized);

            $this->retireActive($lockedDoctor, $day, $actor);

            $override = DoctorDateOverride::query()->create([
                'doctor_id' => $lockedDoctor->id,
                'override_date' => $day->toDateString(),
                'unavailable' => $unavailable,
                'created_by_user_id' => $actor->id,
            ]);

            $provisional = $this->effective->windowsAreProvisional($lockedDoctor, $day);

            foreach ($normalized as $window) {
                $override->windows()->create([
                    'start_time' => $window['start'],
                    'end_time' => $window['end'],
                    'slot_duration' => $window['slot_duration'],
                    'provisional_demonstration' => $provisional,
                ]);
            }

            app(AuditTrailService::class)->recordFor(
                AuditAction::DateOverrideSaved,
                AuditOutcome::Success,
                $override,
                [
                    'doctor_id' => (int) $lockedDoctor->id,
                    'override_date' => $day->toDateString(),
                    'unavailable' => $unavailable,
                    'window_count' => count($normalized),
                ],
                $actor,
            );

            return $override->load('windows');
        });
    }

    public function remove(Doctor $doctor, string $date, User $actor): void
    {
        $day = $this->date($date);

        DB::transaction(function () use ($doctor, $day, $actor): void {
            /** @var Doctor $lockedDoctor */
            $lockedDoctor = Doctor::query()->whereKey($doctor->getKey())->lockForUpdate()->firstOrFail();
            $this->guardAppointmentOnly($lockedDoctor);

            $active = $this->lockActive($lockedDoctor, $day);

            if ($active->isEmpty()) {
                throw ValidationException::withMessages([
                    'date' => 'This date already uses the weekly schedule.',
                ]);
            }

            foreach ($active as $override) {
                $override->forceFill([
                    'removed_at' => now(),
                    'removed_by_user_id' => $actor->id,
                ])->save();

                app(AuditTrailService::class)->recordFor(
                    AuditAction::DateOverrideRemoved,
                    AuditOutcome::Success,
                    $override,
                    [
                        'doctor_id' => (int) $lockedDoctor->id,
                        'override_date' => $day->toDateString(),
                    ],
                    $actor,
                );
            }
        });
    }

    private function guardAppointmentOnly(Doctor $doctor): void
    {
        if (! AppointmentOnlyDoctors::excludes($doctor)) {
            return;
        }

        throw ValidationException::withMessages([
            'doctor' => 'This doctor is available by appointment only. Ordinary public hours cannot be added.',
        ]);
    }

    /**
     * @param  list<array{start_time: string, end_time: string, slot_duration: int}>  $windows
     * @return list<array{start: string, end: string, slot_duration: int}>
     */
    private function normalizeWindows(array $windows): array
    {
        if ($windows === []) {
            throw ValidationException::withMessages([
                'windows' => 'Add at least one consultation window, or mark the date unavailable.',
            ]);
        }

        $normalized = [];

        foreach ($windows as $index => $window) {
            $start = ConsultationWindows::normalize((string) ($window['start_time'] ?? ''));
            $end = ConsultationWindows::normalize((string) ($window['end_time'] ?? ''));
            $duration = (int) ($window['slot_duration'] ?? 0);
            $field = "windows.{$index}.start_time";

            if ($duration < 1) {
                throw ValidationException::withMessages([
                    "windows.{$index}.slot_duration" => 'Slot duration must be greater than zero.',
                ]);
            }

            if ($start >= $end) {
                throw ValidationException::withMessages([
                    "windows.{$index}.end_time" => 'End time must be after start time.',
                ]);
            }

            if (ConsultationWindows::slots([[
                'start' => $start,
                'end' => $end,
                'slot_duration' => $duration,
            ]]) === []) {
                throw ValidationException::withMessages([
                    $field => 'The consultation window is shorter than the slot length.',
                ]);
            }

            foreach ($normalized as $existing) {
                if ($start < $existing['end'] && $end > $existing['start']) {
                    throw ValidationException::withMessages([
                        $field => 'Consultation windows on the same date cannot overlap.',
                    ]);
                }
            }

            $normalized[] = [
                'start' => $start,
                'end' => $end,
                'slot_duration' => $duration,
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<array{start: string, end: string, slot_duration: int}>  $windows
     */
    private function guardAppointments(Doctor $doctor, Carbon $day, array $windows): void
    {
        $slots = ConsultationWindows::slots($windows);
        $appointments = Appointment::query()
            ->where('doctor_id', $doctor->id)
            ->whereDate('appointment_date', $day->toDateString())
            ->whereIn('status', [AppointmentStatus::Pending, AppointmentStatus::Confirmed])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $conflicts = [];

        foreach ($appointments as $appointment) {
            $start = ConsultationWindows::normalize((string) $appointment->start_time);
            $end = ConsultationWindows::normalize((string) $appointment->end_time);
            $fits = false;

            foreach ($slots as $slot) {
                if ($slot['start'] === $start && $slot['end'] === $end) {
                    $fits = true;
                    break;
                }
            }

            if (! $fits) {
                $conflicts[] = Carbon::parse($start)->format('g:i A');
            }
        }

        if ($conflicts === []) {
            return;
        }

        $listed = implode(', ', array_slice($conflicts, 0, 4));

        throw ValidationException::withMessages([
            'date' => 'This date still has a pending or confirmed appointment at '.$listed.'. Resolve that appointment before changing these hours.',
        ]);
    }

    private function retireActive(Doctor $doctor, Carbon $day, User $actor): void
    {
        foreach ($this->lockActive($doctor, $day) as $override) {
            $override->forceFill([
                'removed_at' => now(),
                'removed_by_user_id' => $actor->id,
            ])->save();
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, DoctorDateOverride>
     */
    private function lockActive(Doctor $doctor, Carbon $day)
    {
        return DoctorDateOverride::query()
            ->where('doctor_id', $doctor->id)
            ->whereDate('override_date', $day->toDateString())
            ->whereNull('removed_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function date(string $value): Carbon
    {
        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', $value, config('app.timezone'));
        } catch (\Throwable) {
            $parsed = false;
        }

        if (! $parsed instanceof Carbon || $parsed->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([
                'date' => 'Choose a valid date.',
            ]);
        }

        return $parsed->startOfDay();
    }
}
