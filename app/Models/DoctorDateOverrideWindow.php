<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $doctor_date_override_id
 * @property string $start_time
 * @property string $end_time
 * @property int $slot_duration
 * @property bool $provisional_demonstration
 * @property-read DoctorDateOverride $override
 */
#[Fillable([
    'doctor_date_override_id',
    'start_time',
    'end_time',
    'slot_duration',
    'provisional_demonstration',
])]
class DoctorDateOverrideWindow extends Model
{
    /**
     * @return BelongsTo<DoctorDateOverride, $this>
     */
    public function override(): BelongsTo
    {
        return $this->belongsTo(DoctorDateOverride::class, 'doctor_date_override_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'slot_duration' => 'integer',
            'provisional_demonstration' => 'boolean',
        ];
    }
}
