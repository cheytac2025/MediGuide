<?php

namespace App\Models;

use App\Casts\EncryptedPatientData;
use App\Enums\AppointmentMessageKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only patient-visible appointment message.
 *
 * The body is encrypted and is intentionally excluded from the appointment
 * integrity seal. Changing a note must not look like a slot change, and the
 * note text is not written into audit metadata.
 *
 * @property int $id
 * @property int $appointment_id
 * @property AppointmentMessageKind $kind
 * @property string $body
 * @property int|null $actor_user_id
 * @property Carbon|null $from_date
 * @property string|null $from_start_time
 * @property string|null $from_end_time
 * @property Carbon|null $to_date
 * @property string|null $to_start_time
 * @property string|null $to_end_time
 * @property Carbon|null $created_at
 * @property-read Appointment $appointment
 * @property-read User|null $actor
 */
#[Fillable([
    'appointment_id',
    'kind',
    'body',
    'actor_user_id',
    'from_date',
    'from_start_time',
    'from_end_time',
    'to_date',
    'to_start_time',
    'to_end_time',
])]
#[Hidden(['body'])]
class AppointmentMessage extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AppointmentMessageKind::class,
            'body' => EncryptedPatientData::class,
            'from_date' => 'date',
            'to_date' => 'date',
            'created_at' => 'datetime',
        ];
    }
}
