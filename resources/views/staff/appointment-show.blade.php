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
    $recordTrusted = $appointment->integrityIsValid();
    $patientTrusted = $patient === null || $patient->integrityIsValid();
@endphp

@section('content')
    <div class="mg-staff-appt-page">
        <section class="mg-panel">
            <div class="mg-appt-head">
                <div>
                    <h1 class="mg-panel-title">Appointment Details</h1>
                    <p class="mg-appt-subtitle">Appointment #{{ $appointment->id }}</p>
                </div>
                <a class="mg-appt-back" href="{{ route('staff.appointments', $listQuery ?? []) }}">Back to Appointments</a>
            </div>

            @if (session('appointment_status'))
                <div class="mg-book-success" role="status">
                    <p>{{ session('appointment_status') }}</p>
                </div>
            @endif

            @if (session('appointment_error'))
                <div class="mg-book-alert" role="alert">{{ session('appointment_error') }}</div>
            @endif

            <span class="mg-status mg-appt-status is-{{ $recordTrusted ? $appointment->status->value : 'cancelled' }}">
                {{ $recordTrusted ? $appointment->status->staffLabel() : 'Unverified' }}
            </span>

            <dl class="mg-review-list mg-appt-detail-list">
                <div>
                    <dt>Appointment reference</dt>
                    <dd>#{{ $appointment->id }}</dd>
                </div>
                <div>
                    <dt>Status</dt>
                    <dd>{{ $recordTrusted ? $appointment->status->staffLabel() : 'Unverified' }}</dd>
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
                    <dd>{{ $patientTrusted ? ($patient->contact_number ?? '—') : 'Unavailable' }}</dd>
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
                    <dd>{{ $recordTrusted ? ($appointment->patient_concern ?: 'None') : 'Unavailable' }}</dd>
                </div>
                <div>
                    <dt>Requested</dt>
                    <dd>{{ $appointment->created_at?->timezone(config('app.timezone'))->format('l, F j, Y g:i A') }}</dd>
                </div>
            </dl>

            @include('partials.appointment-messages', ['recordTrusted' => $recordTrusted])

            @if ($recordTrusted && ($appointment->status->canStaffConfirm() || $appointment->status->canStaffReject() || $appointment->status->canStaffReschedule()))
                <div class="mg-staff-actions">
                    @if ($appointment->status->canStaffConfirm())
                        <button type="button" class="mg-appt-details" data-mg-confirm-open>
                            Approve
                        </button>
                    @endif

                    @if ($appointment->status->canStaffReject())
                        <button type="button" class="mg-appt-cancel" data-mg-reject-open>
                            Reject
                        </button>
                    @endif

                    @if ($appointment->status->canStaffReschedule())
                        <a class="mg-appt-details" href="{{ route('staff.appointments.reschedule', $appointment) }}">
                            Reschedule
                        </a>
                    @endif
                </div>

                @if ($errors->any())
                    <div class="mg-book-alert" role="alert">{{ $errors->first() }}</div>
                @endif

                @if ($appointment->status->canStaffConfirm())
                    <div class="mg-cancel-dialog mg-staff-confirm-dialog" data-mg-confirm-dialog @if (! $errors->has('note')) hidden @endif>
                        <p>Approve this appointment?</p>
                        <p class="mg-staff-action-note">
                            The patient will see this appointment as confirmed. An optional note below is also visible to the patient.
                        </p>
                        <form method="POST" action="{{ route('staff.appointments.confirm', $appointment) }}">
                                @csrf
                                @method('PATCH')
                                @foreach (($listQuery ?? []) as $key => $value)
                                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                @endforeach
                                <label class="mg-book-label" for="approval-note">
                                Note for the patient (optional)
                                <textarea id="approval-note" name="note" maxlength="500" rows="3">{{ old('note') }}</textarea>
                            </label>
                            @error('note')
                                <p class="mg-field-error">{{ $message }}</p>
                            @enderror
                            <div class="mg-cancel-actions">
                                <button type="button" class="mg-appt-keep" data-mg-confirm-cancel>Cancel</button>
                                <button type="submit" class="mg-appt-details" data-mg-once>Approve</button>
                            </div>
                        </form>
                    </div>
                @endif

                @if ($appointment->status->canStaffReject())
                    <div class="mg-cancel-dialog" data-mg-reject-dialog @if (! $errors->has('reason')) hidden @endif>
                        <p>Reject this appointment request?</p>
                        <p class="mg-staff-action-note">
                            The patient will see the reason below. Rejecting the request releases the time.
                        </p>
                        <form method="POST" action="{{ route('staff.appointments.reject', $appointment) }}">
                                @csrf
                                @method('PATCH')
                                @foreach (($listQuery ?? []) as $key => $value)
                                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                @endforeach
                                <label class="mg-book-label" for="rejection-reason">
                                Reason for the patient
                                <textarea id="rejection-reason" name="reason" maxlength="500" rows="3" required>{{ old('reason') }}</textarea>
                            </label>
                            @error('reason')
                                <p class="mg-field-error">{{ $message }}</p>
                            @enderror
                            <div class="mg-cancel-actions">
                                <button type="button" class="mg-appt-keep" data-mg-reject-keep>Keep Request</button>
                                <button type="submit" class="mg-appt-cancel" data-mg-once>Reject Appointment</button>
                            </div>
                        </form>
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

        document.querySelectorAll('form').forEach((form) => {
            form.addEventListener('submit', () => {
                form.querySelectorAll('[data-mg-once]').forEach((button) => {
                    button.disabled = true;
                });
            });
        });
    </script>
@endpush
