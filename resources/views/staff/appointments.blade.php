@extends('layouts.staff')

@section('title', 'Appointments')

@section('content')
    <div class="mg-staff-appt-page">
        <section class="mg-panel">
            <div class="mg-appt-head">
                <div>
                    <h1 class="mg-panel-title">Appointments</h1>
                    <p class="mg-appt-subtitle">View and manage patient appointment requests.</p>
                </div>
            </div>

            <nav class="mg-staff-filters" aria-label="Appointment status filters">
                <a
                    href="{{ route('staff.appointments', array_filter(['date' => $dateFilter, 'doctor' => $doctorFilter])) }}"
                    class="mg-staff-filter {{ $statusFilter === null ? 'is-active' : '' }}"
                    @if ($statusFilter === null) aria-current="page" @endif
                >
                    All
                </a>
                @foreach ($statusFilters as $filter)
                    <a
                        href="{{ route('staff.appointments', array_filter(['status' => $filter->value, 'date' => $dateFilter, 'doctor' => $doctorFilter])) }}"
                        class="mg-staff-filter {{ $statusFilter === $filter ? 'is-active' : '' }}"
                        @if ($statusFilter === $filter) aria-current="page" @endif
                    >
                        {{ $filter->label() }}
                    </a>
                @endforeach
            </nav>

            <form class="mg-staff-filter-form" method="GET" action="{{ route('staff.appointments') }}">
                @if ($statusFilter)
                    <input type="hidden" name="status" value="{{ $statusFilter->value }}">
                @endif
                <label class="mg-book-label">
                    Date
                    <input type="date" name="date" value="{{ $dateFilter }}">
                </label>
                <label class="mg-book-label">
                    Doctor
                    <select name="doctor">
                        <option value="">All doctors</option>
                        @foreach ($doctors as $doctor)
                            <option value="{{ $doctor->id }}" @selected((string) $doctorFilter === (string) $doctor->id)>
                                {{ $doctor->display_name }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <button type="submit" class="mg-appt-details">Apply filters</button>
                @if ($dateFilter || $doctorFilter || $statusFilter)
                    <a class="mg-appt-back" href="{{ route('staff.appointments') }}">Clear</a>
                @endif
            </form>

            @if ($appointments->isEmpty())
                <p class="mg-book-empty">{{ $emptyMessage }}</p>
            @else
                <div class="mg-staff-appt-list">
                    @foreach ($appointments as $appointment)
                        @php
                            $patientName = $appointment->patient?->user?->name ?? 'Unknown patient';
                            $clinicName = $appointment->doctor?->clinic?->name ?? 'Unknown clinic';
                            $doctorName = $appointment->doctor?->display_name ?? 'Unknown doctor';
                            $dateLabel = $appointment->appointment_date->format('l, F j, Y');
                            $timeLabel = \Illuminate\Support\Carbon::parse($appointment->start_time)->format('h:i A')
                                .' – '
                                .\Illuminate\Support\Carbon::parse($appointment->end_time)->format('h:i A');
                            $recordTrusted = $appointment->integrityIsValid();
                        @endphp

                        <article class="mg-staff-request-card">
                            <div class="mg-staff-request-top">
                                <div>
                                    <p class="mg-staff-appt-ref">Appointment #{{ $appointment->id }}</p>
                                    <h3>{{ $patientName }}</h3>
                                    <p>{{ $clinicName }}</p>
                                    <p class="mg-appt-muted">{{ $doctorName }}</p>
                                </div>
                                <span class="mg-status is-{{ $recordTrusted ? $appointment->status->value : 'cancelled' }}">
                                    {{ $recordTrusted ? $appointment->status->staffLabel() : 'Unverified' }}
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

                            <a class="mg-appt-details" href="{{ route('staff.appointments.show', ['appointment' => $appointment] + $listQuery) }}">
                                View Details
                            </a>
                        </article>
                    @endforeach
                </div>
            @endif

            {{ $appointments->links('pagination::bootstrap-5') }}
        </section>
    </div>
@endsection
