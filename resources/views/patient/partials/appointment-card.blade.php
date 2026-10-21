@php
    $start = \Illuminate\Support\Carbon::parse($appointment->start_time)->format('h:i A');
    $dateLabel = $appointment->appointment_date->format('l, F j, Y');
@endphp

<article class="mg-appt-card">
    <div class="mg-appt-card-main">
        <p class="mg-appt-when">
            <strong>{{ $dateLabel }}</strong>
            <span>{{ $start }}</span>
        </p>
        <h3>{{ $appointment->doctor->display_name }}</h3>
        <p class="mg-appt-muted">
            {{ $appointment->doctor->clinic->name }}
            @if ($appointment->doctor->specialization)
                · {{ $appointment->doctor->specialization }}
            @endif
        </p>
    </div>
    <div class="mg-appt-card-side">
        <span class="mg-status mg-appt-status is-{{ $appointment->status->value }}">
            {{ $appointment->status->patientLabel() }}
        </span>
        <a class="mg-appt-details" href="{{ route('patient.appointments.show', $appointment) }}">View Details</a>
    </div>
</article>
