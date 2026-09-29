@extends('layouts.patient')

@section('title', 'My Appointments')

@section('content')
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

            @if ($upcoming->isEmpty() && $history->isEmpty())
                <div class="mg-appt-empty">
                    <p>No appointments yet.</p>
                    <a class="mg-appt-book" href="{{ route('patient.book-appointment') }}">Book an Appointment</a>
                </div>
            @else
                <section class="mg-appt-group" aria-labelledby="upcoming-heading">
                    <h2 id="upcoming-heading" class="mg-book-heading">Upcoming</h2>
                    @if ($upcoming->isEmpty())
                        <p class="mg-book-empty">No upcoming appointments.</p>
                    @else
                        <div class="mg-appt-list">
                            @foreach ($upcoming as $appointment)
                                @include('patient.partials.appointment-card', ['appointment' => $appointment])
                            @endforeach
                        </div>
                    @endif
                </section>

                <section class="mg-appt-group" aria-labelledby="history-heading">
                    <h2 id="history-heading" class="mg-book-heading">History</h2>
                    @if ($history->isEmpty())
                        <p class="mg-book-empty">No past appointments.</p>
                    @else
                        <div class="mg-appt-list">
                            @foreach ($history as $appointment)
                                @include('patient.partials.appointment-card', ['appointment' => $appointment])
                            @endforeach
                        </div>
                    @endif
                </section>
            @endif
        </section>
    </div>
@endsection
