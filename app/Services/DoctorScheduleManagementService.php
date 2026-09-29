<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\DayOfWeek;
use App\Enums\DoctorScheduleStatus;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff-facing doctor schedule create/update/status changes with appointment protection.
 */
class DoctorScheduleManagementService
{
    /**
     * @param  array{day_of_week: string, start_time: string, end_time: string, slot_duration: int, status: string}  $attributes
     */
    public function create(Doctor $doctor, array $attributes): DoctorSchedule
    {
        return DB::transaction(function () use ($doctor, $attributes): DoctorSchedule {
            try {
                return DoctorSchedule::query()->create([
                    'doctor_id' => $doctor->id,
                    'day_of_week' => $attributes['day_of_week'],
                    'start_time' => $attributes['start_time'],
                    'end_time' => $attributes['end_time'],
                    'slot_duration' => $attributes['slot_duration'],
                    'status' => $attributes['status'],
                ]);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'start_time' => 'An identical schedule already exists for this doctor.',
                ]);
            } catch (ValidationException $exception) {
                throw $this->friendlyOverlap($exception);
            }
        });
    }

    /**
     * @param  array{day_of_week: string, start_time: string, end_time: string, slot_duration: int, status: string}  $attributes
     */
    public function update(DoctorSchedule $schedule, array $attributes): DoctorSchedule
    {
        return DB::transaction(function () use ($schedule, $attributes): DoctorSchedule {
            /** @var DoctorSchedule $locked */
            $locked = DoctorSchedule::query()->whereKey($schedule->getKey())->lockForUpdate()->firstOrFail();

            $proposedStatus = DoctorScheduleStatus::from($attributes['status']);

            if ($proposedStatus === DoctorScheduleStatus::Inactive) {
                $this->assertCanDeactivate($locked);
            }

            $this->assertProposedScheduleKeepsFutureAppointmentsValid($locked, [
                'day_of_week' => DayOfWeek::from($attributes['day_of_week']),
                'start_time' => $attributes['start_time'],
                'end_time' => $attributes['end_time'],
                'slot_duration' => (int) $attributes['slot_duration'],
                'status' => $proposedStatus,
            ]);

            try {
                $locked->fill([
                    'day_of_week' => $attributes['day_of_week'],
                    'start_time' => $attributes['start_time'],
                    'end_time' => $attributes['end_time'],
                    'slot_duration' => $attributes['slot_duration'],
                    'status' => $attributes['status'],
                ]);
                $locked->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'start_time' => 'An identical schedule already exists for this doctor.',
                ]);
            } catch (ValidationException $exception) {
                throw $this->friendlyOverlap($exception);
            }

            return $locked->fresh();
        });
    }

    public function updateStatus(DoctorSchedule $schedule, DoctorScheduleStatus $status): DoctorSchedule
    {
        return DB::transaction(function () use ($schedule, $status): DoctorSchedule {
            /** @var DoctorSchedule $locked */
            $locked = DoctorSchedule::query()->whereKey($schedule->getKey())->lockForUpdate()->firstOrFail();

            if ($status === DoctorScheduleStatus::Inactive) {
                $this->assertCanDeactivate($locked);
            }

            try {
                $locked->status = $status;
                $locked->save();
            } catch (ValidationException $exception) {
                throw $this->friendlyOverlap($exception);
            }

            return $locked->fresh();
        });
    }

    private function assertCanDeactivate(DoctorSchedule $schedule): void
    {
        if ($this->futureBlockingAppointments($schedule)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'status' => 'This schedule cannot be deactivated while future appointments are assigned to it.',
            ]);
        }
    }

    /**
     * @param  array{day_of_week: DayOfWeek, start_time: string, end_time: string, slot_duration: int, status: DoctorScheduleStatus}  $proposed
     */
    private function assertProposedScheduleKeepsFutureAppointmentsValid(DoctorSchedule $schedule, array $proposed): void
    {
        $unchanged = $schedule->day_of_week === $proposed['day_of_week']
            && $this->normalizedTime($schedule->start_time) === $this->normalizedTime($proposed['start_time'])
            && $this->normalizedTime($schedule->end_time) === $this->normalizedTime($proposed['end_time'])
            && (int) $schedule->slot_duration === (int) $proposed['slot_duration'];

        if ($unchanged) {
            return;
        }

        $probe = $schedule->replicate();
        $probe->id = $schedule->id;
        $probe->doctor_id = $schedule->doctor_id;
        $probe->day_of_week = $proposed['day_of_week'];
        $probe->start_time = $this->normalizedTime($proposed['start_time']);
        $probe->end_time = $this->normalizedTime($proposed['end_time']);
        $probe->slot_duration = $proposed['slot_duration'];
        $probe->status = $proposed['status'];

        foreach ($this->futureBlockingAppointments($schedule) as $appointment) {
            if (! $this->appointmentFitsSchedule($appointment, $probe)) {
                throw ValidationException::withMessages([
                    'start_time' => 'This schedule cannot be changed because it conflicts with an existing appointment.',
                ]);
            }
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Appointment>
     */
    private function futureBlockingAppointments(DoctorSchedule $schedule)
    {
        return Appointment::query()
            ->where('doctor_schedule_id', $schedule->id)
            ->whereIn('status', [AppointmentStatus::Pending, AppointmentStatus::Confirmed])
            ->whereDate('appointment_date', '>=', Carbon::today())
            ->get();
    }

    private function appointmentFitsSchedule(Appointment $appointment, DoctorSchedule $schedule): bool
    {
        $appointmentDay = strtolower($appointment->appointment_date->format('l'));

        if ($appointmentDay !== $schedule->day_of_week->value) {
            return false;
        }

        $start = $this->normalizedTime((string) $appointment->start_time);
        $end = $this->normalizedTime((string) $appointment->end_time);
        $scheduleStart = $this->normalizedTime((string) $schedule->start_time);
        $scheduleEnd = $this->normalizedTime((string) $schedule->end_time);

        if ($start < $scheduleStart || $end > $scheduleEnd) {
            return false;
        }

        $durationSeconds = max(0, (int) $schedule->slot_duration) * 60;

        if ($durationSeconds < 1) {
            return false;
        }

        $startSeconds = $this->secondsSinceMidnight($start);
        $endSeconds = $this->secondsSinceMidnight($end);
        $scheduleStartSeconds = $this->secondsSinceMidnight($scheduleStart);

        if (($endSeconds - $startSeconds) !== $durationSeconds) {
            return false;
        }

        $offset = $startSeconds - $scheduleStartSeconds;

        return $offset >= 0 && ($offset % $durationSeconds) === 0;
    }

    private function friendlyOverlap(ValidationException $exception): ValidationException
    {
        $errors = $exception->errors();

        if (isset($errors['start_time'])) {
            foreach ($errors['start_time'] as $index => $message) {
                if (str_contains(strtolower($message), 'overlap')) {
                    $errors['start_time'][$index] = 'This schedule overlaps another active schedule for this doctor.';
                }
            }
        }

        if (isset($errors['end_time'])) {
            foreach ($errors['end_time'] as $index => $message) {
                if (str_contains(strtolower($message), 'after the start')) {
                    $errors['end_time'][$index] = 'End time must be after start time.';
                }
            }
        }

        if (isset($errors['slot_duration'])) {
            foreach ($errors['slot_duration'] as $index => $message) {
                if (str_contains(strtolower($message), 'greater than')) {
                    $errors['slot_duration'][$index] = 'Slot duration must be greater than zero.';
                }
            }
        }

        return ValidationException::withMessages($errors);
    }

    private function normalizedTime(mixed $time): string
    {
        if ($time instanceof \DateTimeInterface) {
            return Carbon::parse($time)->format('H:i:s');
        }

        $value = trim((string) $time);

        if (preg_match('/^\d{2}:\d{2}$/', $value) === 1) {
            $value .= ':00';
        }

        return Carbon::createFromFormat('H:i:s', $value)->format('H:i:s');
    }

    private function secondsSinceMidnight(string $time): int
    {
        [$hours, $minutes, $seconds] = array_map(intval(...), explode(':', $this->normalizedTime($time)));

        return ($hours * 3600) + ($minutes * 60) + $seconds;
    }
}
