@extends('layouts.doctor')

@section('title', 'Dashboard')

@section('content')
    <section class="mg-hero">
        <div class="mg-hero-copy">
            <h1 class="mg-greeting">Good day, {{ $doctor['first_name'] }}!</h1>
            <p class="mg-greeting-sub">Review today’s confirmed appointments and your clinic schedule.</p>
        </div>
    </section>

    <section class="mg-staff-stats" aria-label="Appointment summary">
        <article class="mg-staff-stat">
            <p class="mg-staff-stat-label">Today’s Appointments</p>
            <p class="mg-staff-stat-value" data-stat="today">{{ $todaysCount }}</p>
        </article>
        <article class="mg-staff-stat">
            <p class="mg-staff-stat-label">Upcoming Confirmed</p>
            <p class="mg-staff-stat-value" data-stat="upcoming">{{ $upcomingCount }}</p>
        </article>
        <article class="mg-staff-stat">
            <p class="mg-staff-stat-label">Completed Appointments</p>
            <p class="mg-staff-stat-value" data-stat="completed">{{ $completedCount }}</p>
        </article>
    </section>

    <section class="mg-panel mt-3">
        <div class="mg-panel-head">
            <h2 class="mg-panel-title">
                <x-patient.icon name="calendar" />
                Today’s Schedule
            </h2>
        </div>

        @if ($todaysSchedules->isEmpty())
            <p class="mg-book-empty">No active schedule blocks for today.</p>
        @else
            <div class="mg-staff-request-list">
                @foreach ($todaysSchedules as $schedule)
                    @php
                        $start = \Illuminate\Support\Carbon::parse($schedule->start_time)->format('h:i A');
                        $end = \Illuminate\Support\Carbon::parse($schedule->end_time)->format('h:i A');
                    @endphp
                    <article class="mg-staff-request-card">
                        <div class="mg-staff-request-top">
                            <div>
                                <h3>{{ $schedule->day_of_week->label() }}</h3>
                                <p>{{ $start }} – {{ $end }}</p>
                                <p class="mg-appt-muted">{{ $schedule->slot_duration }} minute slots · {{ $schedule->status->label() }}</p>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <section class="mg-panel mt-3">
        <div class="mg-panel-head">
            <h2 class="mg-panel-title">
                <x-patient.icon name="clipboard" />
                Today’s Appointments
            </h2>
        </div>

        @if ($todaysAppointments->isEmpty())
            <p class="mg-book-empty">No confirmed appointments for today.</p>
        @else
            <div class="mg-staff-request-list">
                @foreach ($todaysAppointments as $appointment)
                    @php
                        $patientName = $appointment->patient?->user?->name ?? 'Unknown patient';
                        $clinicName = $appointment->doctor?->clinic?->name ?? 'Unknown clinic';
                        $timeLabel = \Illuminate\Support\Carbon::parse($appointment->start_time)->format('h:i A');
                    @endphp

                    <article class="mg-staff-request-card">
                        <div class="mg-staff-request-top">
                            <div>
                                <h3>{{ $patientName }}</h3>
                                <p>{{ $clinicName }}</p>
                                @if ($appointment->patient_concern)
                                    <p class="mg-appt-muted">{{ $appointment->patient_concern }}</p>
                                @endif
                            </div>
                            <span class="mg-status is-confirmed">{{ $appointment->status->label() }}</span>
                        </div>

                        <dl class="mg-appt-facts">
                            <div>
                                <dt>Time</dt>
                                <dd>{{ $timeLabel }}</dd>
                            </div>
                        </dl>

                        <a class="mg-appt-details" href="{{ route('doctor.appointments.show', $appointment) }}">
                            View Details
                        </a>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <section class="mg-panel mt-3">
        <div class="mg-panel-head">
            <h2 class="mg-panel-title">
                <x-patient.icon name="calendar" />
                Upcoming Appointments
            </h2>
        </div>

        @if ($upcomingAppointments->isEmpty())
            <p class="mg-book-empty">No upcoming appointments.</p>
        @else
            <div class="mg-staff-request-list">
                @foreach ($upcomingAppointments as $appointment)
                    @php
                        $patientName = $appointment->patient?->user?->name ?? 'Unknown patient';
                        $clinicName = $appointment->doctor?->clinic?->name ?? 'Unknown clinic';
                        $dateLabel = $appointment->appointment_date->format('l, F j, Y');
                        $timeLabel = \Illuminate\Support\Carbon::parse($appointment->start_time)->format('h:i A');
                    @endphp

                    <article class="mg-staff-request-card">
                        <div class="mg-staff-request-top">
                            <div>
                                <h3>{{ $patientName }}</h3>
                                <p>{{ $clinicName }}</p>
                            </div>
                            <span class="mg-status is-confirmed">{{ $appointment->status->label() }}</span>
                        </div>

                        <dl class="mg-appt-facts">
                            <div>
                                <dt>Date</dt>
                                <dd>{{ $dateLabel }}</dd>
                            </div>
                            <div>
                                <dt>Time</dt>
                                <dd>{{ $timeLabel }}</dd>
                            </div>
                        </dl>

                        <a class="mg-appt-details" href="{{ route('doctor.appointments.show', $appointment) }}">
                            View Details
                        </a>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@endsection
