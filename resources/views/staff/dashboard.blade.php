@extends('layouts.staff')

@section('title', 'Dashboard')

@section('content')
    <section class="mg-hero">
        <div class="mg-hero-copy">
            <h1 class="mg-greeting">Good day, {{ $staff['first_name'] }}!</h1>
            <p class="mg-greeting-sub">Review appointment requests and today’s hospital schedule.</p>
        </div>
    </section>

    <section class="mg-staff-stats" aria-label="Appointment summary">
        <article class="mg-staff-stat">
            <p class="mg-staff-stat-label">Pending Requests</p>
            <p class="mg-staff-stat-value" data-stat="pending">{{ $pendingCount }}</p>
        </article>
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
                <x-patient.icon name="clipboard" />
                Recent Appointment Requests
            </h2>
        </div>

        @if ($recentPending->isEmpty())
            <p class="mg-book-empty">No pending appointment requests.</p>
        @else
            <div class="mg-staff-request-list">
                @foreach ($recentPending as $appointment)
                    @php
                        $patientName = $appointment->patient?->user?->name ?? 'Unknown patient';
                        $clinicName = $appointment->doctor?->clinic?->name ?? 'Unknown clinic';
                        $doctorName = $appointment->doctor?->display_name ?? 'Unknown doctor';
                        $dateLabel = $appointment->appointment_date->format('l, F j, Y');
                        $timeLabel = \Illuminate\Support\Carbon::parse($appointment->start_time)->format('h:i A')
                            .' – '
                            .\Illuminate\Support\Carbon::parse($appointment->end_time)->format('h:i A');
                    @endphp

                    <article class="mg-staff-request-card">
                        <div class="mg-staff-request-top">
                            <div>
                                <h3>{{ $patientName }}</h3>
                                <p>{{ $clinicName }}</p>
                                <p class="mg-appt-muted">{{ $doctorName }}</p>
                            </div>
                            <span class="mg-status is-pending">{{ $appointment->status->label() }}</span>
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
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@endsection
