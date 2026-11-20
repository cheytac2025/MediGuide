@php
    $instructions = $appointment->relationLoaded('patientInstructions')
        ? $appointment->patientInstructions
        : $appointment->patientInstructions()->first();
    $published = $instructions?->publishedContent();
    $recordTrusted = $recordTrusted ?? true;
    $showDoctorMeta = $showDoctorMeta ?? true;
@endphp

<section class="mg-postconsult" aria-labelledby="mg-postconsult-title">
    <div class="mg-postconsult-head">
        <p class="mg-postconsult-kicker">After Consultation</p>
        <h2 id="mg-postconsult-title" class="mg-postconsult-title">Doctor's Post-Consultation Instructions</h2>
    </div>

    @if (! $recordTrusted)
        <p class="mg-postconsult-empty">Unavailable</p>
    @elseif ($published === null)
        <p class="mg-postconsult-empty">No post-consultation instructions are available yet.</p>
    @else
        <dl class="mg-review-list mg-postconsult-meta">
            @if ($showDoctorMeta)
                <div>
                    <dt>Doctor</dt>
                    <dd>{{ $appointment->doctor->display_name }}</dd>
                </div>
            @endif
            <div>
                <dt>Status</dt>
                <dd>Published</dd>
            </div>
            <div>
                <dt>Published</dt>
                <dd>{{ $instructions->published_at?->timezone(config('app.timezone'))->format('F j, Y g:i A') ?? '—' }}</dd>
            </div>
            @if ((int) ($instructions->published_revision ?? 0) > 1)
                <div>
                    <dt>Revision</dt>
                    <dd>Revision {{ $instructions->published_revision }}</dd>
                </div>
            @endif
        </dl>

        <div class="mg-postconsult-block">
            <h3 class="mg-postconsult-subtitle">Prescription / Medication Instructions</h3>
            <p class="mg-postconsult-body">{!! $published['prescription'] !== '' ? nl2br(e($published['prescription'])) : '—' !!}</p>
        </div>

        <div class="mg-postconsult-block">
            <h3 class="mg-postconsult-subtitle">Patient Reminders</h3>
            <p class="mg-postconsult-body">{!! $published['reminders'] !== '' ? nl2br(e($published['reminders'])) : '—' !!}</p>
        </div>

        <div class="mg-postconsult-block">
            <h3 class="mg-postconsult-subtitle">Follow-up Instructions</h3>
            <p class="mg-postconsult-body">{!! $published['follow_up'] !== '' ? nl2br(e($published['follow_up'])) : '—' !!}</p>
        </div>
    @endif
</section>
