@extends('layouts.patient')

@section('title', 'Appointment Details')

@php
    $start = \Illuminate\Support\Carbon::parse($appointment->start_time)->format('h:i A');
    $end = \Illuminate\Support\Carbon::parse($appointment->end_time)->format('h:i A');
@endphp

@section('content')
    <div class="mg-appt-page">
        <section class="mg-panel">
            <div class="mg-appt-head">
                <div>
                    <h1 class="mg-panel-title">Appointment Details</h1>
                    <p class="mg-appt-subtitle">Appointment #{{ $appointment->id }}</p>
                </div>
                <a class="mg-appt-back" href="{{ route('patient.appointments') }}">Back to My Appointments</a>
            </div>

            @if (session('appointment_status'))
                <div class="mg-book-success" role="status">
                    <p>{{ session('appointment_status') }}</p>
                </div>
            @endif

            @if (session('appointment_error'))
                <div class="mg-book-alert" role="alert">{{ session('appointment_error') }}</div>
            @endif

            <span class="mg-status mg-appt-status is-{{ $appointment->status->value }}">
                {{ $appointment->status->patientLabel() }}
            </span>

            <dl class="mg-review-list mg-appt-detail-list">
                <div>
                    <dt>Appointment reference</dt>
                    <dd>#{{ $appointment->id }}</dd>
                </div>
                <div>
                    <dt>Status</dt>
                    <dd>{{ $appointment->status->patientLabel() }}</dd>
                </div>
                <div>
                    <dt>Clinic</dt>
                    <dd>{{ $appointment->doctor->clinic->name }}</dd>
                </div>
                <div>
                    <dt>Department</dt>
                    <dd>{{ $appointment->doctor->clinic->department->name }}</dd>
                </div>
                <div>
                    <dt>Doctor</dt>
                    <dd>{{ $appointment->doctor->display_name }}</dd>
                </div>
                <div>
                    <dt>Specialization</dt>
                    <dd>{{ $appointment->doctor->specialization ?: '—' }}</dd>
                </div>
                <div>
                    <dt>Date</dt>
                    <dd>{{ $appointment->appointment_date->format('l, F j, Y') }}</dd>
                </div>
                <div>
                    <dt>Start time</dt>
                    <dd>{{ $start }}</dd>
                </div>
                <div>
                    <dt>End time</dt>
                    <dd>{{ $end }}</dd>
                </div>
                <div>
                    <dt>Patient concern</dt>
                    <dd>{{ $appointment->patient_concern ?: 'None' }}</dd>
                </div>
                <div>
                    <dt>Requested</dt>
                    <dd>{{ $appointment->created_at?->timezone(config('app.timezone'))->format('l, F j, Y g:i A') }}</dd>
                </div>
            </dl>

            @if ($appointment->status->canBeCancelledByPatient())
                <button type="button" class="mg-appt-cancel" data-mg-cancel-open>Cancel appointment</button>

                <div class="mg-cancel-dialog" data-mg-cancel-dialog hidden>
                    <p>Are you sure you want to cancel this appointment?</p>
                    <div class="mg-cancel-actions">
                        <button type="button" class="mg-appt-keep" data-mg-cancel-keep>Keep Appointment</button>
                        <form method="POST" action="{{ route('patient.appointments.cancel', $appointment) }}">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="mg-appt-cancel">Cancel Appointment</button>
                        </form>
                    </div>
                </div>
            @endif
        </section>
    </div>
@endsection

@push('scripts')
    <script>
        const dialog = document.querySelector('[data-mg-cancel-dialog]');
        const openButton = document.querySelector('[data-mg-cancel-open]');
        const keepButton = document.querySelector('[data-mg-cancel-keep]');

        openButton?.addEventListener('click', () => {
            dialog?.removeAttribute('hidden');
            keepButton?.focus();
        });

        keepButton?.addEventListener('click', () => {
            dialog?.setAttribute('hidden', '');
            openButton?.focus();
        });
    </script>
@endpush
