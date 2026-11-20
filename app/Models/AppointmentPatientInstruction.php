<?php

namespace App\Models;

use App\Casts\EncryptedPatientData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Draft and latest published patient-facing post-consultation instructions.
 *
 * Draft fields are doctor-only. Published fields are visible to the patient
 * owner and department-authorized Hospital Staff. Encrypted at rest; outside
 * the appointment HMAC seal.
 *
 * @property int $id
 * @property int $appointment_id
 * @property int $author_user_id
 * @property string|null $draft_prescription
 * @property string|null $draft_reminders
 * @property string|null $draft_follow_up
 * @property string|null $published_prescription
 * @property string|null $published_reminders
 * @property string|null $published_follow_up
 * @property int|null $published_revision
 * @property Carbon|null $published_at
 * @property int|null $published_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, AppointmentPatientInstructionRevision> $revisions
 */
#[Fillable([
    'appointment_id',
    'author_user_id',
    'draft_prescription',
    'draft_reminders',
    'draft_follow_up',
    'published_prescription',
    'published_reminders',
    'published_follow_up',
    'published_revision',
    'published_at',
    'published_by_user_id',
])]
#[Hidden([
    'draft_prescription',
    'draft_reminders',
    'draft_follow_up',
    'published_prescription',
    'published_reminders',
    'published_follow_up',
])]
class AppointmentPatientInstruction extends Model
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
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    /**
     * @return HasMany<AppointmentPatientInstructionRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(AppointmentPatientInstructionRevision::class, 'instruction_id')
            ->orderByDesc('revision_number');
    }

    public function isPublished(): bool
    {
        return $this->published_revision !== null && $this->published_at !== null;
    }

    public function hasMeaningfulDraft(): bool
    {
        return $this->normalize($this->draft_prescription) !== ''
            || $this->normalize($this->draft_reminders) !== ''
            || $this->normalize($this->draft_follow_up) !== '';
    }

    public function hasUnpublishedDraftChanges(): bool
    {
        if (! $this->isPublished()) {
            return $this->hasMeaningfulDraft();
        }

        return $this->normalize($this->draft_prescription) !== $this->normalize($this->published_prescription)
            || $this->normalize($this->draft_reminders) !== $this->normalize($this->published_reminders)
            || $this->normalize($this->draft_follow_up) !== $this->normalize($this->published_follow_up);
    }

    public function statusLabel(): string
    {
        if (! $this->isPublished()) {
            return $this->hasMeaningfulDraft() ? 'Draft' : 'Not started';
        }

        if ($this->hasUnpublishedDraftChanges()) {
            return 'Published · Draft revision';
        }

        return 'Published';
    }

    /**
     * @return array{prescription: string, reminders: string, follow_up: string}
     */
    public function draftContent(): array
    {
        return [
            'prescription' => $this->normalize($this->draft_prescription),
            'reminders' => $this->normalize($this->draft_reminders),
            'follow_up' => $this->normalize($this->draft_follow_up),
        ];
    }

    /**
     * @return array{prescription: string, reminders: string, follow_up: string}|null
     */
    public function publishedContent(): ?array
    {
        if (! $this->isPublished()) {
            return null;
        }

        return [
            'prescription' => $this->normalize($this->published_prescription),
            'reminders' => $this->normalize($this->published_reminders),
            'follow_up' => $this->normalize($this->published_follow_up),
        ];
    }

    private function normalize(?string $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'draft_prescription' => EncryptedPatientData::class,
            'draft_reminders' => EncryptedPatientData::class,
            'draft_follow_up' => EncryptedPatientData::class,
            'published_prescription' => EncryptedPatientData::class,
            'published_reminders' => EncryptedPatientData::class,
            'published_follow_up' => EncryptedPatientData::class,
            'published_at' => 'datetime',
            'published_revision' => 'integer',
        ];
    }
}
