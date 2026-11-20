<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\AuditOutcome;
use App\Enums\DoctorStatus;
use App\Models\Appointment;
use App\Models\AppointmentDoctorNote;
use App\Models\AppointmentPatientInstruction;
use App\Models\AppointmentPatientInstructionRevision;
use App\Models\Doctor;
use App\Models\User;
use App\Notifications\PatientInstructionsPublishedNotification;
use App\Support\Audit\AuditTrailService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Doctor-authored consultation notes and patient instruction publish flow.
 *
 * Internal notes never leave doctor authorization. Patient-facing drafts stay
 * private until an explicit publish after consultation completion.
 */
class ConsultationDocumentationService
{
    public function __construct(private readonly AuditTrailService $audit) {}

    public function assertAssignedActiveDoctor(Appointment $appointment, Doctor $doctor, User $user): void
    {
        if ((int) $doctor->id !== (int) $appointment->doctor_id) {
            throw new InvalidArgumentException('Doctor is not assigned to this appointment.');
        }

        if ((int) ($doctor->user_id ?? 0) !== (int) $user->id) {
            throw new InvalidArgumentException('Doctor account is not linked to the authenticated user.');
        }

        if ($doctor->status !== DoctorStatus::Active) {
            throw ValidationException::withMessages([
                'appointment' => 'Only an active doctor account may manage consultation documentation.',
            ]);
        }
    }

    public function completeConsultation(Appointment $appointment, Doctor $doctor, User $user): Appointment
    {
        $this->assertAssignedActiveDoctor($appointment, $doctor, $user);

        return DB::transaction(function () use ($appointment, $user): Appointment {
            /** @var Appointment $locked */
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canDoctorComplete()) {
                throw ValidationException::withMessages([
                    'appointment' => 'Only a confirmed appointment can be marked completed.',
                ]);
            }

            $locked->update([
                'status' => AppointmentStatus::Completed,
            ]);

            $this->audit->recordFor(
                AuditAction::AppointmentCompleted,
                AuditOutcome::Success,
                $locked,
                [
                    'doctor_id' => (int) $locked->doctor_id,
                    'patient_id' => (int) $locked->patient_id,
                    'status' => AppointmentStatus::Completed->value,
                ],
                $user,
            );

            return $locked->fresh();
        });
    }

    /**
     * @param  array{
     *     internal_notes?: string|null,
     *     prescription?: string|null,
     *     reminders?: string|null,
     *     follow_up?: string|null
     * }  $input
     * @return array{note: AppointmentDoctorNote, instructions: AppointmentPatientInstruction}
     */
    public function saveDraft(Appointment $appointment, Doctor $doctor, User $user, array $input): array
    {
        $this->assertAssignedActiveDoctor($appointment, $doctor, $user);

        return DB::transaction(function () use ($appointment, $user, $input): array {
            /** @var Appointment $locked */
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [AppointmentStatus::Cancelled, AppointmentStatus::Rejected], true)) {
                throw ValidationException::withMessages([
                    'appointment' => 'Consultation documentation cannot be saved for cancelled or rejected appointments.',
                ]);
            }

            $note = AppointmentDoctorNote::query()->updateOrCreate(
                ['appointment_id' => $locked->id],
                [
                    'author_user_id' => $user->id,
                    'internal_notes' => $this->nullableText($input['internal_notes'] ?? null) ?? '',
                ],
            );

            $instructions = AppointmentPatientInstruction::query()->updateOrCreate(
                ['appointment_id' => $locked->id],
                [
                    'author_user_id' => $user->id,
                    'draft_prescription' => $this->nullableText($input['prescription'] ?? null),
                    'draft_reminders' => $this->nullableText($input['reminders'] ?? null),
                    'draft_follow_up' => $this->nullableText($input['follow_up'] ?? null),
                ],
            );

            $this->audit->recordFor(
                AuditAction::ConsultationNotesSaved,
                AuditOutcome::Success,
                $locked,
                [
                    'doctor_id' => (int) $locked->doctor_id,
                    'patient_id' => (int) $locked->patient_id,
                    'has_internal_notes' => trim((string) $note->internal_notes) !== '',
                    'has_draft_instructions' => $instructions->hasMeaningfulDraft(),
                ],
                $user,
            );

            $this->audit->recordFor(
                AuditAction::PatientInstructionsDraftSaved,
                AuditOutcome::Success,
                $locked,
                [
                    'doctor_id' => (int) $locked->doctor_id,
                    'patient_id' => (int) $locked->patient_id,
                    'instruction_id' => (int) $instructions->id,
                    'has_draft_instructions' => $instructions->hasMeaningfulDraft(),
                ],
                $user,
            );

            return [
                'note' => $note->fresh(),
                'instructions' => $instructions->fresh(),
            ];
        });
    }

    /**
     * @param  array{
     *     prescription?: string|null,
     *     reminders?: string|null,
     *     follow_up?: string|null,
     *     revision_reason?: string|null
     * }  $input
     * @return array{instructions: AppointmentPatientInstruction, revision: AppointmentPatientInstructionRevision, notified: bool}
     */
    public function publish(Appointment $appointment, Doctor $doctor, User $user, array $input): array
    {
        $this->assertAssignedActiveDoctor($appointment, $doctor, $user);

        $prescription = $this->nullableText($input['prescription'] ?? null);
        $reminders = $this->nullableText($input['reminders'] ?? null);
        $followUp = $this->nullableText($input['follow_up'] ?? null);
        $reason = $this->nullableText($input['revision_reason'] ?? null);

        if ($prescription === null && $reminders === null && $followUp === null) {
            throw ValidationException::withMessages([
                'prescription' => 'Publish at least one patient-facing instruction field.',
            ]);
        }

        $result = DB::transaction(function () use ($appointment, $user, $prescription, $reminders, $followUp, $reason): array {
            /** @var Appointment $locked */
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->allowsPostConsultationInstructions()) {
                throw ValidationException::withMessages([
                    'appointment' => 'Publish is allowed only after the consultation is marked completed.',
                ]);
            }

            $instructions = AppointmentPatientInstruction::query()
                ->where('appointment_id', $locked->id)
                ->lockForUpdate()
                ->first();

            if ($instructions === null) {
                $instructions = AppointmentPatientInstruction::query()->create([
                    'appointment_id' => $locked->id,
                    'author_user_id' => $user->id,
                    'draft_prescription' => $prescription,
                    'draft_reminders' => $reminders,
                    'draft_follow_up' => $followUp,
                ]);
            } else {
                $instructions->fill([
                    'author_user_id' => $user->id,
                    'draft_prescription' => $prescription,
                    'draft_reminders' => $reminders,
                    'draft_follow_up' => $followUp,
                ]);
            }

            if (
                $instructions->isPublished()
                && $this->samePublishedContent($instructions, $prescription, $reminders, $followUp)
            ) {
                throw ValidationException::withMessages([
                    'prescription' => 'These instructions are already published. Change the content before publishing a revision.',
                ]);
            }

            $nextRevision = ((int) ($instructions->published_revision ?? 0)) + 1;
            $publishedAt = now();

            $instructions->fill([
                'published_prescription' => $prescription,
                'published_reminders' => $reminders,
                'published_follow_up' => $followUp,
                'published_revision' => $nextRevision,
                'published_at' => $publishedAt,
                'published_by_user_id' => $user->id,
            ]);
            $instructions->save();

            $revision = AppointmentPatientInstructionRevision::query()->create([
                'appointment_id' => $locked->id,
                'instruction_id' => $instructions->id,
                'revision_number' => $nextRevision,
                'prescription' => $prescription,
                'reminders' => $reminders,
                'follow_up' => $followUp,
                'revision_reason' => $nextRevision > 1 ? $reason : null,
                'published_by_user_id' => $user->id,
                'published_at' => $publishedAt,
            ]);

            $this->audit->recordFor(
                AuditAction::PatientInstructionsPublished,
                AuditOutcome::Success,
                $locked,
                [
                    'doctor_id' => (int) $locked->doctor_id,
                    'patient_id' => (int) $locked->patient_id,
                    'instruction_id' => (int) $instructions->id,
                    'revision' => $nextRevision,
                    'is_revision' => $nextRevision > 1,
                ],
                $user,
            );

            return [
                'appointment' => $locked->fresh(['patient.user', 'doctor']),
                'instructions' => $instructions->fresh(),
                'revision' => $revision,
            ];
        });

        $notified = $this->notifyPatientOnce(
            $result['appointment'],
            $result['revision'],
        );

        return [
            'instructions' => $result['instructions'],
            'revision' => $result['revision'],
            'notified' => $notified,
        ];
    }

    private function notifyPatientOnce(
        Appointment $appointment,
        AppointmentPatientInstructionRevision $revision,
    ): bool {
        $patientUser = $appointment->patient?->user;

        if ($patientUser === null) {
            return false;
        }

        $event = $revision->revision_number > 1
            ? 'patient_instructions_revised'
            : 'patient_instructions_published';

        $exists = $patientUser->notifications()
            ->where('type', PatientInstructionsPublishedNotification::class)
            ->where('data->appointment_id', $appointment->id)
            ->where('data->revision', $revision->revision_number)
            ->exists();

        if ($exists) {
            return false;
        }

        $patientUser->notify(new PatientInstructionsPublishedNotification(
            $appointment,
            $revision->revision_number,
            $event,
        ));

        return true;
    }

    private function samePublishedContent(
        AppointmentPatientInstruction $instructions,
        ?string $prescription,
        ?string $reminders,
        ?string $followUp,
    ): bool {
        return $this->nullableText($instructions->published_prescription) === $prescription
            && $this->nullableText($instructions->published_reminders) === $reminders
            && $this->nullableText($instructions->published_follow_up) === $followUp;
    }

    private function nullableText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
