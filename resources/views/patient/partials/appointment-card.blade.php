@php
    $start = \Illuminate\Support\Carbon::parse($appointment->start_time)->format('h:i A');
    $dateLabel = $appointment->appointment_date->format('l, F j, Y');
@endphp

<article class="mg-appt-card">
    <div class="mg-appt-card-top">
        <div>
            <h3>{{ $appointment->doctor->clinic->name }}</h3>
            <p>{{ $appointment->doctor->display_name }}</p>
            @if ($appointment->doctor->specialization)
                <p class="mg-appt-muted">{{ $appointment->doctor->specialization }}</p>
            @endif
        </div>
        <span class="mg-status mg-appt-status is-{{ $appointment->status->value }}">
            {{ $appointment->status->patientLabel() }}
        </span>
    </div>

    <dl class="mg-appt-facts">
        <div>
            <dt>Date</dt>
            <dd>{{ $dateLabel }}</dd>
        </div>
        <div>
            <dt>Time</dt>
            <dd>{{ $start }}</dd>
        </div>
    </dl>

    @if ($appointment->patient_concern)
        <p class="mg-appt-concern">{{ $appointment->patient_concern }}</p>
    @endif

    <a class="mg-appt-details" href="{{ route('patient.appointments.show', $appointment) }}">View Details</a>
</article>
