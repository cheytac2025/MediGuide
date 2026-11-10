@extends('layouts.staff')

@section('title', 'Reschedule Appointment')

@php
    $doctor = $appointment->doctor;
    $currentStart = \Illuminate\Support\Carbon::parse($appointment->start_time)->format('h:i A');
    $currentEnd = \Illuminate\Support\Carbon::parse($appointment->end_time)->format('h:i A');
@endphp

@section('content')
    <div class="mg-staff-appt-page">
        <section class="mg-panel">
            <div class="mg-appt-head">
                <div>
                    <h1 class="mg-panel-title">Reschedule</h1>
                    <p class="mg-appt-subtitle">Appointment #{{ $appointment->id }} stays with the same patient and doctor.</p>
                </div>
                <a class="mg-appt-back" href="{{ route('staff.appointments.show', $appointment) }}">Back to Appointment</a>
            </div>

            <dl class="mg-review-list">
                <div>
                    <dt>Patient</dt>
                    <dd>{{ $appointment->patient?->user?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Doctor</dt>
                    <dd>{{ $doctor?->display_name ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Current schedule</dt>
                    <dd>
                        {{ $appointment->appointment_date->format('l, F j, Y') }}
                        · {{ $currentStart }} – {{ $currentEnd }}
                    </dd>
                </div>
                <div>
                    <dt>Status</dt>
                    <dd>{{ $appointment->status->staffLabel() }}</dd>
                </div>
            </dl>
            <p class="mg-schedule-note">Rescheduling keeps this status. The reason you enter is visible to the patient.</p>
        </section>

        <section class="mg-panel mt-3">
            <h2 class="mg-panel-title">Choose a new date</h2>
            <div class="mg-staff-cal-layout">
                <x-scheduling.month :calendar="$calendar" />

                <div>
                    @if ($selectedDate)
                        <h3 class="mg-sched-title">{{ \Illuminate\Support\Carbon::parse($selectedDate)->format('l, F j') }}</h3>
                    @endif

                    @if ($choices === [])
                        <p class="mg-book-empty">No times are available on this date.</p>
                    @else
                        <div class="mg-sched-slots">
                            @foreach ($choices as $choice)
                                @if ($choice['available'])
                                    <a
                                        class="mg-sched-slot {{ ($picked['start'] ?? null) === $choice['start'] ? 'is-selected' : '' }}"
                                        href="{{ route('staff.appointments.reschedule', ['appointment' => $appointment, 'date' => $selectedDate, 'start_time' => $choice['start'], 'month' => substr((string) $selectedDate, 0, 7)]) }}"
                                    >{{ $choice['label'] }}</a>
                                @else
                                    <button type="button" class="mg-sched-slot" disabled>{{ $choice['label'] }}</button>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </section>

        @if ($picked)
            <section class="mg-panel mt-3">
                <h2 class="mg-panel-title">Confirm the new schedule</h2>
                <dl class="mg-review-list">
                    <div>
                        <dt>Current</dt>
                        <dd>{{ $appointment->appointment_date->format('l, F j, Y') }} · {{ $currentStart }}</dd>
                    </div>
                    <div>
                        <dt>New</dt>
                        <dd>{{ \Illuminate\Support\Carbon::parse($selectedDate)->format('l, F j, Y') }} · {{ \Illuminate\Support\Carbon::parse($picked['start'])->format('h:i A') }}</dd>
                    </div>
                </dl>

                @if ($sameSlot)
                    <p class="mg-field-error">Choose a different date or time. This appointment is already scheduled then.</p>
                @endif

                <form method="POST" action="{{ route('staff.appointments.reschedule.store', $appointment) }}">
                    @csrf
                    <input type="hidden" name="appointment_date" value="{{ $selectedDate }}">
                    <input type="hidden" name="start_time" value="{{ $picked['start'] }}">
                    <input type="hidden" name="doctor_schedule_id" value="{{ $picked['schedule_id'] }}">
                    <label class="mg-book-label" for="reschedule-reason">
                        Reason for the patient
                        <textarea id="reschedule-reason" name="reason" maxlength="500" rows="3" required>{{ old('reason') }}</textarea>
                    </label>
                    @error('reason')
                        <p class="mg-field-error">{{ $message }}</p>
                    @enderror
                    @error('start_time')
                        <p class="mg-field-error">{{ $message }}</p>
                    @enderror
                    @error('appointment_date')
                        <p class="mg-field-error">{{ $message }}</p>
                    @enderror
                    <div class="mg-staff-actions">
                        <button type="submit" class="mg-appt-details" data-mg-once @disabled($sameSlot)>Reschedule appointment</button>
                    </div>
                </form>
            </section>
        @endif
    </div>
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('form').forEach((form) => {
            form.addEventListener('submit', () => {
                form.querySelectorAll('[data-mg-once]').forEach((button) => {
                    button.disabled = true;
                });
            });
        });
    </script>
@endpush
