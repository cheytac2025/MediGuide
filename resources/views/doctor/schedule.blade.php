@extends('layouts.doctor')

@section('title', 'My Schedule')

@section('content')
    <div class="mg-staff-appt-page">
        <section class="mg-panel">
            <div class="mg-appt-head">
                <div>
                    <h1 class="mg-panel-title">My Schedule</h1>
                    <p class="mg-appt-subtitle">Your weekly clinic hours (read-only).</p>
                </div>
            </div>

            @if ($schedules->isEmpty())
                <p class="mg-book-empty">No schedules on file.</p>
            @else
                <div class="mg-staff-appt-list">
                    @foreach ($schedules as $schedule)
                        @php
                            $start = \Illuminate\Support\Carbon::parse($schedule->start_time)->format('h:i A');
                            $end = \Illuminate\Support\Carbon::parse($schedule->end_time)->format('h:i A');
                        @endphp

                        <article class="mg-staff-request-card">
                            <div class="mg-staff-request-top">
                                <div>
                                    <h3>{{ $schedule->day_of_week->label() }}</h3>
                                    <p>{{ $start }} – {{ $end }}</p>
                                    <p class="mg-appt-muted">{{ $schedule->slot_duration }} minute slots</p>
                                </div>
                                <span class="mg-status is-{{ $schedule->status->value === 'active' ? 'confirmed' : 'cancelled' }}">
                                    {{ $schedule->status->label() }}
                                </span>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
@endsection
