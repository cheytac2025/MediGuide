@extends('layouts.doctor')

@section('title', 'Preview Patient Instructions')

@section('content')
    <div class="mg-staff-appt-page">
        <section class="mg-panel">
            <div class="mg-appt-head">
                <div>
                    <h1 class="mg-panel-title">Preview Patient Instructions</h1>
                    <p class="mg-appt-subtitle">Appointment #{{ $appointment->id }} · Not published</p>
                </div>
                <a class="mg-appt-back" href="{{ route('doctor.appointments.show', $appointment) }}">Back to Appointment</a>
            </div>

            <p class="mg-postconsult-hint">This preview shows only patient-facing fields. Internal consultation notes are never included.</p>

            <section class="mg-postconsult" aria-labelledby="preview-title">
                <h2 id="preview-title" class="mg-postconsult-title">Doctor's Post-Consultation Instructions</h2>

                <div class="mg-postconsult-block">
                    <h3 class="mg-postconsult-subtitle">Prescription / Medication Instructions</h3>
                    <p class="mg-postconsult-body">{!! $preview['prescription'] !== '' ? nl2br(e($preview['prescription'])) : '—' !!}</p>
                </div>

                <div class="mg-postconsult-block">
                    <h3 class="mg-postconsult-subtitle">Patient Reminders</h3>
                    <p class="mg-postconsult-body">{!! $preview['reminders'] !== '' ? nl2br(e($preview['reminders'])) : '—' !!}</p>
                </div>

                <div class="mg-postconsult-block">
                    <h3 class="mg-postconsult-subtitle">Follow-up Instructions</h3>
                    <p class="mg-postconsult-body">{!! $preview['follow_up'] !== '' ? nl2br(e($preview['follow_up'])) : '—' !!}</p>
                </div>
            </section>
        </section>
    </div>
@endsection
