<?php

namespace App\Services\Scheduling;

use App\Enums\DoctorScheduleStatus;
use App\Models\Doctor;
use App\Models\DoctorDateOverride;
use App\Models\DoctorSchedule;
use App\Support\Scheduling\ConsultationWindows;
use App\Support\Scheduling\EffectiveDay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Resolves the single schedule patients, staff, and suggested-doctor
 * availability all read for one Asia/Manila date.
 *
 * An active date override replaces the weekly hours for that date only.
 * A day-off override offers no slots. No override uses the weekly schedule.
 */
class EffectiveScheduleService
{
    public function forDate(Doctor $doctor, Carbon $date): EffectiveDay
    {
        $doctor->loadMissing('schedules');

        $override = DoctorDateOverride::query()
            ->where('doctor_id', $doctor->id)
            ->whereDate('override_date', $date->toDateString())
            ->whereNull('removed_at')
            ->with('windows')
            ->orderByDesc('id')
            ->first();

        if ($override instanceof DoctorDateOverride && $override->unavailable) {
            return new EffectiveDay(EffectiveDay::UNAVAILABLE, null, []);
        }

        if ($override instanceof DoctorDateOverride) {
            return $this->overrideDay($doctor, $date, $override);
        }

        return $this->recurringDay($doctor, $date);
    }

    private function overrideDay(Doctor $doctor, Carbon $date, DoctorDateOverride $override): EffectiveDay
    {
        $provisional = $override->windows->contains(
            fn ($window): bool => (bool) $window->provisional_demonstration,
        );
        $anchor = $this->anchor($doctor, $date, $provisional);

        $windows = [];

        foreach ($override->windows as $window) {
            $windows[] = [
                'start' => ConsultationWindows::normalize((string) $window->start_time),
                'end' => ConsultationWindows::normalize((string) $window->end_time),
                'slot_duration' => (int) $window->slot_duration,
                'provisional' => (bool) $window->provisional_demonstration,
                'schedule_id' => $anchor?->id,
            ];
        }

        return new EffectiveDay(EffectiveDay::OVERRIDE, $anchor?->id, $windows);
    }

    private function recurringDay(Doctor $doctor, Carbon $date): EffectiveDay
    {
        $weekday = strtolower($date->format('l'));
        $windows = [];

        foreach ($this->activeSchedules($doctor) as $schedule) {
            if ($schedule->day_of_week->value !== $weekday) {
                continue;
            }

            $windows[] = [
                'start' => ConsultationWindows::normalize((string) $schedule->start_time),
                'end' => ConsultationWindows::normalize((string) $schedule->end_time),
                'slot_duration' => (int) $schedule->slot_duration,
                'provisional' => (bool) $schedule->provisional_demonstration,
                'schedule_id' => $schedule->id,
            ];
        }

        return new EffectiveDay(EffectiveDay::RECURRING, null, $windows);
    }

    private function anchor(Doctor $doctor, Carbon $date, bool $provisional): ?DoctorSchedule
    {
        $weekday = strtolower($date->format('l'));
        $active = $this->activeSchedules($doctor);

        $preferred = $provisional
            ? $active->filter(fn (DoctorSchedule $schedule): bool => $schedule->provisional_demonstration)
            : $active->filter(fn (DoctorSchedule $schedule): bool => ! $schedule->provisional_demonstration);

        if ($preferred->isEmpty()) {
            $preferred = $active;
        }

        $sameDay = $preferred->filter(
            fn (DoctorSchedule $schedule): bool => $schedule->day_of_week->value === $weekday,
        );

        $pool = $sameDay->isNotEmpty() ? $sameDay : $preferred;

        return $pool->sortBy('id')->first();
    }

    /**
     * @return Collection<int, DoctorSchedule>
     */
    private function activeSchedules(Doctor $doctor): Collection
    {
        return $doctor->schedules->filter(
            fn (DoctorSchedule $schedule): bool => $schedule->status === DoctorScheduleStatus::Active,
        )->values();
    }

    public function windowsAreProvisional(Doctor $doctor, Carbon $date): bool
    {
        $doctor->loadMissing('schedules');
        $weekday = strtolower($date->format('l'));
        $active = $this->activeSchedules($doctor);
        $sameDay = $active->filter(
            fn (DoctorSchedule $schedule): bool => $schedule->day_of_week->value === $weekday,
        );

        if ($sameDay->isNotEmpty()) {
            return $sameDay->every(
                fn (DoctorSchedule $schedule): bool => (bool) $schedule->provisional_demonstration,
            );
        }

        return $active->isNotEmpty() && $active->every(
            fn (DoctorSchedule $schedule): bool => (bool) $schedule->provisional_demonstration,
        );
    }
}
