@extends('layouts.patient')

@section('title', 'My Appointments')

@section('content')
    @php
        $filters = $section === 'history'
            ? [
                'all' => 'All',
                'completed' => 'Completed',
                'cancelled' => 'Cancelled',
                'rejected' => 'Rejected',
            ]
            : [
                'all' => 'All',
                'pending' => 'Pending',
                'confirmed' => 'Confirmed',
            ];
        $sectionCount = $section === 'history' ? $historyCount : $upcomingCount;
        $hasAppointments = $upcomingCount > 0 || $historyCount > 0;
    @endphp

    <div class="mg-appt-page">
        <section class="mg-panel">
            <div class="mg-appt-head">
                <div>
                    <h1 class="mg-panel-title">My Appointments</h1>
                    <p class="mg-appt-subtitle">View and manage your appointment requests.</p>
                </div>
                <a class="mg-appt-book" href="{{ route('patient.book-appointment') }}">Book New Appointment</a>
            </div>

            @if ($submittedMessage)
                <div class="mg-book-success" role="status">
                    <p>{{ $submittedMessage }}</p>
                </div>
            @endif

            @if (! $hasAppointments)
                <div class="mg-appt-empty">
                    <p>No appointments yet.</p>
                    <a class="mg-appt-book" href="{{ route('patient.book-appointment') }}">Book an Appointment</a>
                </div>
            @else
                <nav class="mg-appt-tabs" aria-label="Appointment sections">
                    <a
                        href="{{ route('patient.appointments', ['section' => 'upcoming']) }}"
                        @if ($section === 'upcoming') aria-current="page" @endif
                    >Upcoming</a>
                    <a
                        href="{{ route('patient.appointments', ['section' => 'history']) }}"
                        @if ($section === 'history') aria-current="page" @endif
                    >History</a>
                </nav>

                @if ($sectionCount === 0)
                    <p class="mg-appt-empty-note">
                        {{ $section === 'history' ? 'No appointment history yet.' : 'You have no upcoming appointments.' }}
                    </p>
                @else
                    <nav class="mg-appt-filters" aria-label="Filter {{ $section }} appointments">
                        @foreach ($filters as $value => $label)
                            <a
                                href="{{ route('patient.appointments', ['section' => $section, 'status' => $value]) }}"
                                @if ($statusFilter === $value) aria-current="true" @endif
                            >{{ $label }}</a>
                        @endforeach
                    </nav>

                    @if ($appointments->isEmpty())
                        <p class="mg-appt-empty-note">No appointments match this filter.</p>
                    @else
                        <div class="mg-appt-list">
                            @foreach ($appointments as $appointment)
                                @include('patient.partials.appointment-card', ['appointment' => $appointment])
                            @endforeach
                        </div>
                    @endif
                @endif
            @endif
        </section>
    </div>
@endsection
