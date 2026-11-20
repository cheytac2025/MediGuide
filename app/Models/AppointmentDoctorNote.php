<?php

namespace App\Models;

use App\Casts\EncryptedPatientData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Doctor-only internal consultation notes for one appointment.
 *
 * Never exposed to patients or Hospital Staff. Encrypted at rest and kept
 * outside the appointment HMAC seal.
 *
 * @property int $id
 * @property int $appointment_id
 * @property int $author_user_id
 * @property string $internal_notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'appointment_id',
    'author_user_id',
    'internal_notes',
])]
#[Hidden([
    'internal_notes',
])]
class AppointmentDoctorNote extends Model
{
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
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'internal_notes' => EncryptedPatientData::class,
        ];
    }
}
