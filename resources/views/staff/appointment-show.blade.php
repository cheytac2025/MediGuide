@extends('layouts.staff')

@section('title', 'Appointment Details')

@php
    $patient = $appointment->patient;
    $patientUser = $patient?->user;
    $doctor = $appointment->doctor;
    $clinic = $doctor?->clinic;
    $department = $clinic?->department;
    $start = \Illuminate\Support\Carbon::parse($appointment->start_time)->format('h:i A');
    $end = \Illuminate\Support\Carbon::parse($appointment->end_time)->format('h:i A');
@endphp

@section('content')
    <div class="mg-staff-appt-page">
        <section class="mg-panel">
            <div class="mg-appt-head">
                <div>
                    <h1 class="mg-panel-title">Appointment Details</h1>
                    <p class="mg-appt-subtitle">Appointment #{{ $appointment->id }}</p>
                </div>
                <a class="mg-appt-back" href="{{ route('staff.appointments') }}">Back to Appointments</a>
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
                {{ $appointment->status->staffLabel() }}
            </span>

            <dl class="mg-review-list mg-appt-detail-list">
                <div>
                    <dt>Appointment reference</dt>
                    <dd>#{{ $appointment->id }}</dd>
                </div>
                <div>
                    <dt>Status</dt>
                    <dd>{{ $appointment->status->staffLabel() }}</dd>
                </div>
                <div>
                    <dt>Patient name</dt>
                    <dd>{{ $patientUser?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Patient email</dt>
                    <dd>{{ $patientUser?->email ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Patient contact number</dt>
                    <dd>{{ $patient?->contact_number ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Clinic</dt>
                    <dd>{{ $clinic?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Department</dt>
                    <dd>{{ $department?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Doctor</dt>
                    <dd>{{ $doctor?->display_name ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Specialization</dt>
                    <dd>{{ $doctor?->specialization ?: '—' }}</dd>
                </div>
                <div>
                    <dt>Appointment date</dt>
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

            @if ($appointment->status->canStaffConfirm() || $appointment->status->canStaffReject())
                <div class="mg-staff-actions">
                    @if ($appointment->status->canStaffConfirm())
                        <button type="button" class="mg-appt-details" data-mg-confirm-open>
                            Confirm Appointment
                        </button>
                    @endif

                    @if ($appointment->status->canStaffReject())
                        <button type="button" class="mg-appt-cancel" data-mg-reject-open>
                            Reject Appointment
                        </button>
                    @endif
                </div>

                @if ($appointment->status->canStaffConfirm())
                    <div class="mg-cancel-dialog mg-staff-confirm-dialog" data-mg-confirm-dialog hidden>
                        <p>Confirm this appointment?</p>
                        <p class="mg-staff-action-note">
                            The appointment will become confirmed and will be visible as a confirmed booking for the assigned doctor.
                        </p>
                        <div class="mg-cancel-actions">
                            <button type="button" class="mg-appt-keep" data-mg-confirm-cancel>Cancel</button>
                            <form method="POST" action="{{ route('staff.appointments.confirm', $appointment) }}">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="mg-appt-details">Confirm Appointment</button>
                            </form>
                        </div>
                    </div>
                @endif

                @if ($appointment->status->canStaffReject())
                    <div class="mg-cancel-dialog" data-mg-reject-dialog hidden>
                        <p>Reject this appointment request?</p>
                        <p class="mg-staff-action-note">
                            Rejecting the request will release the appointment slot for booking again.
                        </p>
                        <div class="mg-cancel-actions">
                            <button type="button" class="mg-appt-keep" data-mg-reject-keep>Keep Request</button>
                            <form method="POST" action="{{ route('staff.appointments.reject', $appointment) }}">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="mg-appt-cancel">Reject Appointment</button>
                            </form>
                        </div>
                    </div>
                @endif
            @endif
        </section>
    </div>
@endsection

@push('scripts')
    <script>
        const confirmDialog = document.querySelector('[data-mg-confirm-dialog]');
        const confirmOpen = document.querySelector('[data-mg-confirm-open]');
        const confirmCancel = document.querySelector('[data-mg-confirm-cancel]');
        const rejectDialog = document.querySelector('[data-mg-reject-dialog]');
        const rejectOpen = document.querySelector('[data-mg-reject-open]');
        const rejectKeep = document.querySelector('[data-mg-reject-keep]');

        confirmOpen?.addEventListener('click', () => {
            rejectDialog?.setAttribute('hidden', '');
            confirmDialog?.removeAttribute('hidden');
            confirmCancel?.focus();
        });

        confirmCancel?.addEventListener('click', () => {
            confirmDialog?.setAttribute('hidden', '');
            confirmOpen?.focus();
        });

        rejectOpen?.addEventListener('click', () => {
            confirmDialog?.setAttribute('hidden', '');
            rejectDialog?.removeAttribute('hidden');
            rejectKeep?.focus();
        });

        rejectKeep?.addEventListener('click', () => {
            rejectDialog?.setAttribute('hidden', '');
            rejectOpen?.focus();
        });
    </script>
@endpush
