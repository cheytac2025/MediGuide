@extends('layouts.staff')

@section('title', 'Manage Doctor Schedule')

@php
    $formatTime = static function (string $time): string {
        return \Illuminate\Support\Carbon::parse($time)->format('H:i');
    };
    $displayTime = static function (string $time): string {
        return \Illuminate\Support\Carbon::parse($time)->format('h:i A');
    };
@endphp

@section('content')
    <div class="mg-staff-appt-page">
        <section class="mg-panel">
            <div class="mg-appt-head">
                <div>
                    <h1 class="mg-panel-title">{{ $doctorRecord->display_name }}</h1>
                    <p class="mg-appt-subtitle">
                        {{ $doctorRecord->specialization ?: 'No specialization listed' }}
                        ·
                        {{ $doctorRecord->clinic?->name ?? 'Unknown clinic' }}
                        ·
                        {{ $doctorRecord->clinic?->department?->name ?? 'Unknown department' }}
                    </p>
                </div>
                <a class="mg-appt-back" href="{{ route('staff.doctor-schedules') }}">Back to Doctors</a>
            </div>

            @if (session('schedule_status'))
                <div class="mg-book-success" role="status">
                    <p>{{ session('schedule_status') }}</p>
                </div>
            @endif

            @if (session('schedule_error'))
                <div class="mg-book-alert" role="alert">{{ session('schedule_error') }}</div>
            @endif

            @if ($errors->any() && ! session('schedule_error'))
                <div class="mg-book-alert" role="alert">{{ $errors->first() }}</div>
            @endif
        </section>

        <section class="mg-panel mt-3">
            <div class="mg-panel-head">
                <h2 class="mg-panel-title">
                    <x-patient.icon name="calendar" />
                    {{ $editingSchedule ? 'Edit Schedule' : 'Add Schedule' }}
                </h2>
            </div>

            @if ($editingSchedule)
                <form
                    method="POST"
                    action="{{ route('staff.doctor-schedules.update', $editingSchedule) }}"
                    class="mg-staff-schedule-form"
                >
                    @csrf
                    @method('PATCH')

                    <div class="mg-staff-schedule-grid">
                        <label class="mg-book-label">
                            Day of Week
                            <select name="day_of_week" required>
                                @foreach ($days as $day)
                                    <option
                                        value="{{ $day->value }}"
                                        @selected(old('day_of_week', $editingSchedule->day_of_week->value) === $day->value)
                                    >
                                        {{ $day->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </label>

                        <label class="mg-book-label">
                            Start Time
                            <input
                                type="time"
                                name="start_time"
                                value="{{ old('start_time', $formatTime($editingSchedule->start_time)) }}"
                                required
                            >
                        </label>

                        <label class="mg-book-label">
                            End Time
                            <input
                                type="time"
                                name="end_time"
                                value="{{ old('end_time', $formatTime($editingSchedule->end_time)) }}"
                                required
                            >
                        </label>

                        <label class="mg-book-label">
                            Slot Duration (minutes)
                            <input
                                type="number"
                                name="slot_duration"
                                min="1"
                                value="{{ old('slot_duration', $editingSchedule->slot_duration) }}"
                                required
                            >
                        </label>

                        <label class="mg-book-label">
                            Status
                            <select name="status" required>
                                @foreach ($statuses as $status)
                                    <option
                                        value="{{ $status->value }}"
                                        @selected(old('status', $editingSchedule->status->value) === $status->value)
                                    >
                                        {{ $status->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </label>
                    </div>

                    <div class="mg-staff-actions">
                        <button type="submit" class="mg-appt-details">Save Changes</button>
                        <a class="mg-appt-cancel" href="{{ route('staff.doctor-schedules.show', $doctorRecord) }}">Cancel</a>
                    </div>
                </form>
            @else
                <form
                    method="POST"
                    action="{{ route('staff.doctor-schedules.store', $doctorRecord) }}"
                    class="mg-staff-schedule-form"
                >
                    @csrf

                    <div class="mg-staff-schedule-grid">
                        <label class="mg-book-label">
                            Day of Week
                            <select name="day_of_week" required>
                                @foreach ($days as $day)
                                    <option
                                        value="{{ $day->value }}"
                                        @selected(old('day_of_week', 'monday') === $day->value)
                                    >
                                        {{ $day->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </label>

                        <label class="mg-book-label">
                            Start Time
                            <input type="time" name="start_time" value="{{ old('start_time', '09:00') }}" required>
                        </label>

                        <label class="mg-book-label">
                            End Time
                            <input type="time" name="end_time" value="{{ old('end_time', '12:00') }}" required>
                        </label>

                        <label class="mg-book-label">
                            Slot Duration (minutes)
                            <input type="number" name="slot_duration" min="1" value="{{ old('slot_duration', 30) }}" required>
                        </label>

                        <label class="mg-book-label">
                            Status
                            <select name="status" required>
                                @foreach ($statuses as $status)
                                    <option
                                        value="{{ $status->value }}"
                                        @selected(old('status', \App\Enums\DoctorScheduleStatus::Active->value) === $status->value)
                                    >
                                        {{ $status->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </label>
                    </div>

                    <div class="mg-staff-actions">
                        <button type="submit" class="mg-appt-details">+ Add Schedule</button>
                    </div>
                </form>
            @endif
        </section>

        <section class="mg-panel mt-3">
            <div class="mg-panel-head">
                <h2 class="mg-panel-title">
                    <x-patient.icon name="clipboard" />
                    Weekly Schedules
                </h2>
            </div>

            @if ($schedules->isEmpty())
                <p class="mg-book-empty">No schedules on file for this doctor.</p>
            @else
                <div class="mg-staff-appt-list">
                    @foreach ($schedules as $schedule)
                        <article class="mg-staff-request-card">
                            <div class="mg-staff-request-top">
                                <div>
                                    <h3>{{ $schedule->day_of_week->label() }}</h3>
                                    <p>
                                        {{ $displayTime($schedule->start_time) }}
                                        –
                                        {{ $displayTime($schedule->end_time) }}
                                    </p>
                                    <p class="mg-appt-muted">{{ $schedule->slot_duration }} minute slots</p>
                                </div>
                                <span class="mg-status is-{{ $schedule->status->value === 'active' ? 'confirmed' : 'cancelled' }}">
                                    {{ $schedule->status->label() }}
                                </span>
                            </div>

                            <div class="mg-staff-actions">
                                <a
                                    class="mg-appt-details"
                                    href="{{ route('staff.doctor-schedules.show', ['doctor' => $doctorRecord, 'edit' => $schedule->id]) }}"
                                >
                                    Edit
                                </a>

                                @if ($schedule->status === \App\Enums\DoctorScheduleStatus::Active)
                                    <form
                                        method="POST"
                                        action="{{ route('staff.doctor-schedules.status', $schedule) }}"
                                    >
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="status" value="inactive">
                                        <button type="submit" class="mg-appt-cancel">Deactivate</button>
                                    </form>
                                @else
                                    <form
                                        method="POST"
                                        action="{{ route('staff.doctor-schedules.status', $schedule) }}"
                                    >
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="status" value="active">
                                        <button type="submit" class="mg-appt-details">Activate</button>
                                    </form>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
@endsection
