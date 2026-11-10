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
                    <p class="mg-appt-subtitle">{{ \App\Support\DoctorPlacement::labels($doctorRecord)['subtitle'] }}</p>
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
                    {{ $selectedDate->format('l, F j, Y') }}
                </h2>
            </div>

            <div class="mg-staff-cal-layout">
                <x-scheduling.month :calendar="$calendar" />

                <div>
                    <p class="mg-schedule-note">
                        @if ($effectiveDay->source === \App\Support\Scheduling\EffectiveDay::UNAVAILABLE)
                            <strong>Day off.</strong> This date has no bookable slots. The weekly schedule is unchanged.
                        @elseif ($effectiveDay->source === \App\Support\Scheduling\EffectiveDay::OVERRIDE)
                            <strong>Date-specific hours.</strong> These hours replace the weekly schedule for this date only.
                        @else
                            <strong>Weekly hours.</strong> No date-specific change is saved for this date.
                        @endif
                    </p>
                    <p class="mg-schedule-note">
                        A date-specific schedule replaces the weekly hours for that date. A day off offers no slots. With neither, the weekly schedule is used.
                    </p>
                    <p>{{ $bookedCount === 1 ? '1 pending or confirmed appointment' : $bookedCount.' pending or confirmed appointments' }} on this date.</p>

                    @if ($effectiveDay->windows === [])
                        <p class="mg-book-empty">No consultation windows on this date.</p>
                    @else
                        <ul class="mg-message-list">
                            @foreach ($effectiveDay->windows as $window)
                                <li>
                                    {{ \Illuminate\Support\Carbon::parse($window['start'])->format('g:i A') }}
                                    –
                                    {{ \Illuminate\Support\Carbon::parse($window['end'])->format('g:i A') }}
                                    · {{ $window['slot_duration'] }} minute slots
                                    @if ($window['provisional'])
                                        · demonstration hours
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($overrideHistory->isNotEmpty())
                        <h3 class="mg-book-heading">Earlier changes for this date</h3>
                        <ul class="mg-message-list">
                            @foreach ($overrideHistory as $previous)
                                <li>
                                    {{ $previous->unavailable ? 'Marked unavailable' : 'Date-specific hours' }}
                                    · {{ $previous->created_at?->timezone(config('app.timezone'))->format('M j, Y g:i A') }}
                                    @if ($previous->createdBy)
                                        · {{ $previous->createdBy->name }}
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            @unless ($appointmentOnly)
                <div class="mg-date-override">
                    <h3 class="mg-book-heading">This date only</h3>
                    <p class="mg-schedule-note">This does not change the weekly schedule. Existing appointments stay in place; a change that would leave a pending or confirmed appointment outside the new hours is blocked.</p>

                    <form method="POST" action="{{ route('staff.doctor-schedules.date-overrides.store', $doctorRecord) }}" class="mg-staff-schedule-form">
                        @csrf
                        <input type="hidden" name="date" value="{{ $selectedDate->toDateString() }}">
                        <fieldset class="mg-book-label">
                            <legend>Hours for {{ $selectedDate->format('F j, Y') }}</legend>
                            <label>
                                <input type="radio" name="unavailable" value="0" @checked(! old('unavailable', $effectiveDay->source === \App\Support\Scheduling\EffectiveDay::UNAVAILABLE))>
                                Replacement consultation windows
                            </label>
                            <label>
                                <input type="radio" name="unavailable" value="1" @checked(old('unavailable', $effectiveDay->source === \App\Support\Scheduling\EffectiveDay::UNAVAILABLE))>
                                Mark the whole date unavailable
                            </label>
                        </fieldset>
                        @error('date')
                            <p class="mg-field-error">{{ $message }}</p>
                        @enderror
                        @error('windows')
                            <p class="mg-field-error">{{ $message }}</p>
                        @enderror

                        @for ($index = 0; $index < 3; $index++)
                            <div class="mg-staff-schedule-grid">
                                <label class="mg-book-label">
                                    Window {{ $index + 1 }} start
                                    <input type="time" name="windows[{{ $index }}][start_time]" value="{{ old('windows.'.$index.'.start_time') }}">
                                </label>
                                <label class="mg-book-label">
                                    End
                                    <input type="time" name="windows[{{ $index }}][end_time]" value="{{ old('windows.'.$index.'.end_time') }}">
                                </label>
                                <label class="mg-book-label">
                                    Slot minutes
                                    <input type="number" min="1" name="windows[{{ $index }}][slot_duration]" value="{{ old('windows.'.$index.'.slot_duration', $index === 0 ? 30 : '') }}">
                                </label>
                            </div>
                            @error('windows.'.$index.'.start_time')
                                <p class="mg-field-error">{{ $message }}</p>
                            @enderror
                            @error('windows.'.$index.'.end_time')
                                <p class="mg-field-error">{{ $message }}</p>
                            @enderror
                            @error('windows.'.$index.'.slot_duration')
                                <p class="mg-field-error">{{ $message }}</p>
                            @enderror
                        @endfor

                        <div class="mg-staff-actions">
                            <button type="submit" class="mg-appt-details" data-mg-once>Save this date</button>
                        </div>
                    </form>

                    @if ($effectiveDay->source !== \App\Support\Scheduling\EffectiveDay::RECURRING)
                        <form method="POST" action="{{ route('staff.doctor-schedules.date-overrides.destroy', $doctorRecord) }}">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="date" value="{{ $selectedDate->toDateString() }}">
                            <p class="mg-schedule-note">Removing this change restores the weekly hours. Appointments already booked stay where they are.</p>
                            <button type="submit" class="mg-appt-cancel" data-mg-once>Use weekly hours again</button>
                        </form>
                    @endif
                </div>
            @else
                <p class="mg-book-alert" role="status">This doctor is available by appointment only. Ordinary public hours cannot be added.</p>
            @endunless
        </section>

        <section class="mg-panel mt-3">
            <div class="mg-panel-head">
                <h2 class="mg-panel-title">
                    <x-patient.icon name="calendar" />
                    Weekly schedule
                </h2>
            </div>
            <p class="mg-schedule-note">These hours repeat on the selected weekday. Editing them changes future occurrences of that weekday, not one date.</p>

            @unless ($appointmentOnly)
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
                                        @selected(old('day_of_week', $selectedWeekday ?? 'monday') === $day->value)
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
            @endunless
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
