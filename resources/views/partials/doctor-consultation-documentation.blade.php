@php
    /** @var \App\Models\Appointment $appointment */
    $note = $doctorNote ?? $appointment->doctorNote;
    $instructions = $patientInstructions ?? $appointment->patientInstructions;
    $statusLabel = $instructions?->statusLabel() ?? 'Not started';
    $canComplete = $appointment->status->canDoctorComplete();
    $canPublish = $appointment->status->allowsPostConsultationInstructions();
    $isPublished = $instructions?->isPublished() ?? false;
@endphp

<section class="mg-postconsult mg-postconsult-doctor" aria-labelledby="mg-doctor-postconsult-title">
    <div class="mg-postconsult-head">
        <p class="mg-postconsult-kicker">Clinical Documentation</p>
        <h2 id="mg-doctor-postconsult-title" class="mg-postconsult-title">Post-Consultation Notes</h2>
        <p class="mg-postconsult-status" aria-live="polite">
            Status: <strong>{{ $statusLabel }}</strong>
        </p>
    </div>

    @if (session('consultation_status'))
        <div class="mg-book-success" role="status">
            <p>{{ session('consultation_status') }}</p>
        </div>
    @endif

    @if ($errors->any())
        <div class="mg-book-alert" role="alert">{{ $errors->first() }}</div>
    @endif

    @if ($canComplete)
        <form method="POST" action="{{ route('doctor.appointments.complete', $appointment) }}" class="mg-postconsult-complete">
            @csrf
            @method('PATCH')
            <p class="mg-postconsult-hint">Mark the consultation completed before publishing patient instructions.</p>
            <button type="submit" class="mg-appt-details">Mark Consultation Completed</button>
        </form>
    @endif

    <form method="POST" action="{{ route('doctor.appointments.consultation.save', $appointment) }}" class="mg-postconsult-form" id="mg-consultation-form">
        @csrf

        <div class="mg-postconsult-field">
            <label for="internal_notes">Internal Consultation Notes</label>
            <p class="mg-postconsult-hint">Visible only to you. Never sent to the patient or Hospital Staff.</p>
            <textarea id="internal_notes" name="internal_notes" rows="5" maxlength="10000">{{ old('internal_notes', $note?->internal_notes) }}</textarea>
        </div>

        <div class="mg-postconsult-field">
            <label for="prescription">Prescription / Medication Instructions</label>
            <p class="mg-postconsult-hint">Doctor-authored free text for the patient. Not an electronic prescribing system.</p>
            <textarea id="prescription" name="prescription" rows="4" maxlength="5000">{{ old('prescription', $instructions?->draft_prescription) }}</textarea>
        </div>

        <div class="mg-postconsult-field">
            <label for="reminders">Patient Reminders</label>
            <textarea id="reminders" name="reminders" rows="3" maxlength="5000">{{ old('reminders', $instructions?->draft_reminders) }}</textarea>
        </div>

        <div class="mg-postconsult-field">
            <label for="follow_up">Follow-up Instructions</label>
            <textarea id="follow_up" name="follow_up" rows="3" maxlength="5000">{{ old('follow_up', $instructions?->draft_follow_up) }}</textarea>
        </div>

        @if ($isPublished)
            <div class="mg-postconsult-field">
                <label for="revision_reason">Revision reason (optional)</label>
                <input id="revision_reason" type="text" name="revision_reason" maxlength="255" value="{{ old('revision_reason') }}">
            </div>
        @endif

        <div class="mg-postconsult-actions">
            <button type="submit" class="mg-appt-details">Save Draft</button>
            <button type="submit" class="mg-appt-keep" formaction="{{ route('doctor.appointments.consultation.preview', $appointment) }}">
                Preview Patient Instructions
            </button>
            @if ($canPublish)
                <button type="submit" class="mg-appt-details" formaction="{{ route('doctor.appointments.consultation.publish', $appointment) }}">
                    {{ $isPublished ? 'Publish Revision' : 'Publish Instructions' }}
                </button>
            @else
                <p class="mg-postconsult-hint">Publishing unlocks after the consultation is marked completed.</p>
            @endif
        </div>
    </form>

    @if ($isPublished)
        <div class="mg-postconsult-published-preview">
            <h3 class="mg-postconsult-subtitle">Currently visible to patient</h3>
            <p class="mg-postconsult-hint">Revision {{ $instructions->published_revision }} · {{ $instructions->published_at?->timezone(config('app.timezone'))->format('F j, Y g:i A') }}</p>
            @include('partials.appointment-patient-instructions', [
                'appointment' => $appointment,
                'recordTrusted' => true,
                'showDoctorMeta' => false,
            ])
        </div>
    @endif
</section>
