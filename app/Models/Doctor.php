<?php

namespace App\Models;

use App\Enums\DoctorStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $clinic_id
 * @property int|null $user_id
 * @property string $display_name
 * @property string|null $specialization
 * @property DoctorStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Clinic $clinic
 * @property-read User|null $user
 * @property-read Collection<int, DoctorSchedule> $schedules
 * @property-read Collection<int, Appointment> $appointments
 */
#[Fillable(['clinic_id', 'user_id', 'display_name', 'specialization', 'status'])]
class Doctor extends Model
{
    /**
     * @return BelongsTo<Clinic, $this>
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<DoctorSchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(DoctorSchedule::class);
    }

    /**
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DoctorStatus::class,
        ];
    }
}
