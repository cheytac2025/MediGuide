<?php

namespace App\Models;

use App\Casts\EncryptedPatientData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Encrypted AI Front Desk concern summary for one appointment.
 *
 * Clinical text is encrypted at rest. Like AppointmentMessage, this row is
 * intentionally outside the appointment HMAC seal so note/summary edits do
 * not look like slot tampering. Access is gated by appointment authorization.
 *
 * @property int $id
 * @property int $appointment_id
 * @property string $concern_summary
 * @property string $reported_details
 * @property string|null $suggested_specialization
 * @property string $original_inputs
 * @property string $source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Appointment $appointment
 */
#[Fillable([
    'appointment_id',
    'concern_summary',
    'reported_details',
    'suggested_specialization',
    'original_inputs',
    'source',
])]
#[Hidden([
    'concern_summary',
    'reported_details',
    'suggested_specialization',
    'original_inputs',
])]
class AppointmentAiSummary extends Model
{
    public const SOURCE_AI_FRONT_DESK = 'ai_front_desk';

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function reportedDetailsList(): array
    {
        $decoded = json_decode($this->reported_details, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<string>
     */
    public function originalInputList(): array
    {
        $decoded = json_decode($this->original_inputs, true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(
            $decoded,
            fn (mixed $line): bool => is_string($line) && trim($line) !== '',
        ));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'concern_summary' => EncryptedPatientData::class,
            'reported_details' => EncryptedPatientData::class,
            'suggested_specialization' => EncryptedPatientData::class,
            'original_inputs' => EncryptedPatientData::class,
        ];
    }
}
