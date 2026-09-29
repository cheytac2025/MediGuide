@extends('layouts.doctor')

@section('title', 'Appointments')

@section('content')
    <div class="mg-staff-appt-page">
        <section class="mg-panel">
            <div class="mg-appt-head">
                <div>
                    <h1 class="mg-panel-title">Appointments</h1>
                    <p class="mg-appt-subtitle">View confirmed and past appointments assigned to you.</p>
                </div>
            </div>

            <nav class="mg-staff-filters" aria-label="Appointment filters">
                @php
                    $filters = [
                        'upcoming' => 'Upcoming / Confirmed',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled',
                        'rejected' => 'Rejected',
                    ];
                @endphp

                @foreach ($filters as $key => $label)
                    <a
                        href="{{ route('doctor.appointments', ['filter' => $key]) }}"
                        class="mg-staff-filter {{ $filter === $key ? 'is-active' : '' }}"
                        @if ($filter === $key) aria-current="page" @endif
                    >
                        {{ $label }}
                    </a>
                @endforeach
            </nav>

            @if ($appointments->isEmpty())
                <p class="mg-book-empty">{{ $emptyMessage }}</p>
            @else
                <div class="mg-staff-appt-list">
                    @foreach ($appointments as $appointment)
                        @php
                            $patientName = $appointment->patient?->user?->name ?? 'Unknown patient';
                            $clinicName = $appointment->doctor?->clinic?->name ?? 'Unknown clinic';
                            $dateLabel = $appointment->appointment_date->format('l, F j, Y');
                            $timeLabel = \Illuminate\Support\Carbon::parse($appointment->start_time)->format('h:i A')
                                .' – '
                                .\Illuminate\Support\Carbon::parse($appointment->end_time)->format('h:i A');
                        @endphp

                        <article class="mg-staff-request-card">
                            <div class="mg-staff-request-top">
                                <div>
                                    <p class="mg-staff-appt-ref">Appointment #{{ $appointment->id }}</p>
                                    <h3>{{ $patientName }}</h3>
                                    <p>{{ $clinicName }}</p>
                                </div>
                                <span class="mg-status is-{{ $appointment->status->value }}">
                                    {{ $appointment->status->label() }}
                                </span>
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
    </div>
@endsection
