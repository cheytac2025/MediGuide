<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One date-specific schedule decision for a doctor.
 *
 * Removed rows are kept so earlier date-specific hours remain on record.
 * The weekly schedule is never rewritten by these rows.
 *
 * @property int $id
 * @property int $doctor_id
 * @property Carbon $override_date
 * @property bool $unavailable
 * @property int|null $created_by_user_id
 * @property Carbon|null $removed_at
 * @property int|null $removed_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Doctor $doctor
 * @property-read \Illuminate\Database\Eloquent\Collection<int, DoctorDateOverrideWindow> $windows
 * @property-read User|null $createdBy
 * @property-read User|null $removedBy
 */
#[Fillable([
    'doctor_id',
    'override_date',
    'unavailable',
    'created_by_user_id',
    'removed_at',
    'removed_by_user_id',
])]
class DoctorDateOverride extends Model
{
    /**
     * @return BelongsTo<Doctor, $this>
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /**
     * @return HasMany<DoctorDateOverrideWindow, $this>
     */
    public function windows(): HasMany
    {
        return $this->hasMany(DoctorDateOverrideWindow::class)->orderBy('start_time');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'override_date' => 'date',
            'unavailable' => 'boolean',
            'removed_at' => 'datetime',
        ];
    }
}
