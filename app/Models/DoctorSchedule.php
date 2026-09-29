<?php

namespace App\Models;

use App\Enums\DayOfWeek;
use App\Enums\DoctorScheduleStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * @property int $id
 * @property int $doctor_id
 * @property DayOfWeek $day_of_week
 * @property string $start_time
 * @property string $end_time
 * @property int $slot_duration
 * @property DoctorScheduleStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Doctor $doctor
 */
#[Fillable(['doctor_id', 'day_of_week', 'start_time', 'end_time', 'slot_duration', 'status'])]
class DoctorSchedule extends Model
{
    /**
     * @return BelongsTo<Doctor, $this>
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day_of_week' => DayOfWeek::class,
            'status' => DoctorScheduleStatus::class,
            'slot_duration' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (DoctorSchedule $schedule): void {
            $schedule->normalizeTimes();
            $schedule->assertIsValid();
        });
    }

    public function overlapsActiveSchedule(): bool
    {
        if ($this->status !== DoctorScheduleStatus::Active) {
            return false;
        }

        return static::query()
            ->where('doctor_id', $this->doctor_id)
            ->where('day_of_week', $this->day_of_week)
            ->where('status', DoctorScheduleStatus::Active)
            ->when($this->exists, fn (Builder $query) => $query->whereKeyNot($this->getKey()))
            ->where('start_time', '<', $this->end_time)
            ->where('end_time', '>', $this->start_time)
            ->exists();
    }

    private function normalizeTimes(): void
    {
        $this->start_time = $this->normalizedTime($this->start_time);
        $this->end_time = $this->normalizedTime($this->end_time);
    }

    private function assertIsValid(): void
    {
        if ($this->slot_duration < 1) {
            throw ValidationException::withMessages([
                'slot_duration' => 'The slot duration must be greater than 0 minutes.',
            ]);
        }

        if ($this->start_time >= $this->end_time) {
            throw ValidationException::withMessages([
                'end_time' => 'The end time must be after the start time.',
            ]);
        }

        if ($this->overlapsActiveSchedule()) {
            throw ValidationException::withMessages([
                'start_time' => 'This schedule overlaps an existing active schedule for the same doctor and day.',
            ]);
        }
    }

    private function normalizedTime(mixed $time): string
    {
        if ($time instanceof \DateTimeInterface) {
            return Carbon::parse($time)->format('H:i:s');
        }

        $value = trim((string) $time);
        $format = strlen($value) === 5 ? 'H:i' : 'H:i:s';
        $parsed = Carbon::createFromFormat($format, $value);

        if (! $parsed instanceof Carbon) {
            throw ValidationException::withMessages([
                'start_time' => 'The schedule time is invalid.',
            ]);
        }

        return $parsed->format('H:i:s');
    }
}
