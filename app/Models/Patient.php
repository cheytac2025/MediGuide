<?php

namespace App\Models;

use App\Casts\EncryptedPatientData;
use App\Enums\Sex;
use App\Models\Concerns\ChecksRecordIntegrity;
use App\Models\Concerns\WritesRecordIntegrity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property Carbon $date_of_birth
 * @property Sex $sex
 * @property string $contact_number
 * @property-read Collection<int, Appointment> $appointments
 */
#[Fillable(['date_of_birth', 'sex', 'contact_number'])]
#[Hidden(['date_of_birth', 'contact_number'])]
class Patient extends Model
{
    use ChecksRecordIntegrity, WritesRecordIntegrity;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
            'date_of_birth' => EncryptedPatientData::class.':date,profile',
            'sex' => Sex::class,
            'contact_number' => EncryptedPatientData::class.':string,profile',
        ];
    }
}
