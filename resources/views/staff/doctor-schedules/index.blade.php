@extends('layouts.staff')

@section('title', 'Doctor Schedules')

@section('content')
    <div class="mg-staff-appt-page">
        <section class="mg-panel">
            <div class="mg-appt-head">
                <div>
                    <h1 class="mg-panel-title">Doctor Schedules</h1>
                    <p class="mg-appt-subtitle">Manage weekly clinic hours for active doctors.</p>
                </div>
            </div>

            @if ($doctors->isEmpty())
                <p class="mg-book-empty">No active doctors are available.</p>
            @else
                <div class="mg-staff-appt-list">
                    @foreach ($doctors as $doctor)
                        <article class="mg-staff-request-card">
                            <div class="mg-staff-request-top">
                                <div>
                                    <h3>{{ $doctor->display_name }}</h3>
                                    <p>{{ $doctor->specialization ?: 'No specialization listed' }}</p>
                                    <p class="mg-appt-muted">
                                        {{ $doctor->clinic?->name ?? 'Unknown clinic' }}
                                        ·
                                        {{ $doctor->clinic?->department?->name ?? 'Unknown department' }}
                                    </p>
                                    <p class="mg-appt-muted">
                                        {{ $doctor->active_schedules_count }} active schedule{{ $doctor->active_schedules_count === 1 ? '' : 's' }}
                                    </p>
                                </div>
                            </div>

                            <a class="mg-appt-details" href="{{ route('staff.doctor-schedules.show', $doctor) }}">
                                Manage Schedule
                            </a>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
@endsection
