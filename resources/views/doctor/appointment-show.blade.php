@extends('layouts.doctor')

@section('title', 'Appointment Details')

@php
    $patient = $appointment->patient;
    $patientUser = $patient?->user;
    $clinic = $appointment->doctor?->clinic;
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
                <a class="mg-appt-back" href="{{ route('doctor.appointments') }}">Back to Appointments</a>
            </div>

            <span class="mg-status mg-appt-status is-{{ $appointment->status->value }}">
                {{ $appointment->status->label() }}
            </span>

            <dl class="mg-review-list mg-appt-detail-list">
                <div>
                    <dt>Appointment reference</dt>
                    <dd>#{{ $appointment->id }}</dd>
                </div>
                <div>
                    <dt>Status</dt>
                    <dd>{{ $appointment->status->label() }}</dd>
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
                    <dd>{{ $appointment->patient_concern ?: '—' }}</dd>
                </div>
            </dl>
        </section>
    </div>
@endsection
