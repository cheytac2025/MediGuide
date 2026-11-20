<?php

namespace App\Models;

use App\Casts\EncryptedPatientData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only published snapshot of patient-facing instructions.
 *
 * @property int $id
 * @property int $appointment_id
 * @property int $instruction_id
 * @property int $revision_number
 * @property string|null $prescription
 * @property string|null $reminders
 * @property string|null $follow_up
 * @property string|null $revision_reason
 * @property int $published_by_user_id
 * @property Carbon $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'appointment_id',
    'instruction_id',
    'revision_number',
    'prescription',
    'reminders',
    'follow_up',
    'revision_reason',
    'published_by_user_id',
    'published_at',
])]
#[Hidden([
    'prescription',
    'reminders',
    'follow_up',
])]
class AppointmentPatientInstructionRevision extends Model
{
    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * @return BelongsTo<AppointmentPatientInstruction, $this>
     */
    public function instruction(): BelongsTo
    {
        return $this->belongsTo(AppointmentPatientInstruction::class, 'instruction_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'prescription' => EncryptedPatientData::class,
            'reminders' => EncryptedPatientData::class,
            'follow_up' => EncryptedPatientData::class,
            'published_at' => 'datetime',
            'revision_number' => 'integer',
        ];
    }
}
