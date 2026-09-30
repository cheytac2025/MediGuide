@extends('layouts.admin')

@section('title', 'Add Doctor')

@section('content')
    <div class="mg-staff-appt-page">
        <section class="mg-panel">
            <div class="mg-appt-head">
                <div>
                    <h1 class="mg-panel-title">Add Doctor</h1>
                    <p class="mg-appt-subtitle">Create a doctor record and a linked Doctor account.</p>
                </div>
                <a class="mg-appt-back" href="{{ route('admin.doctors') }}">Back to Doctors</a>
            </div>

            @if ($errors->any())
                <div class="mg-book-alert" role="alert">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('admin.doctors.store') }}" class="mg-staff-schedule-form">
                @csrf

                <div class="mg-staff-schedule-grid">
                    <label class="mg-book-label">
                        Display Name
                        <input type="text" name="display_name" value="{{ old('display_name') }}" maxlength="255" required>
                    </label>

                    <label class="mg-book-label">
                        Clinic
                        <select name="clinic_id" required>
                            <option value="">Select a clinic</option>
                            @foreach ($clinics as $clinic)
                                <option value="{{ $clinic->id }}" @selected((string) old('clinic_id') === (string) $clinic->id)>
                                    {{ $clinic->name }} ({{ $clinic->status->label() }}) · {{ $clinic->department?->name }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="mg-book-label">
                        Specialization
                        <input type="text" name="specialization" value="{{ old('specialization') }}" maxlength="255">
                    </label>

                    <label class="mg-book-label">
                        Status
                        <select name="status" required>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}" @selected(old('status', \App\Enums\DoctorStatus::Active->value) === $status->value)>
                                    {{ $status->label() }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="mg-book-label">
                        First Name
                        <input type="text" name="first_name" value="{{ old('first_name') }}" maxlength="100" required>
                    </label>

                    <label class="mg-book-label">
                        Middle Name
                        <input type="text" name="middle_name" value="{{ old('middle_name') }}" maxlength="100">
                    </label>

                    <label class="mg-book-label">
                        Last Name
                        <input type="text" name="last_name" value="{{ old('last_name') }}" maxlength="100" required>
                    </label>

                    <label class="mg-book-label">
                        Email
                        <input type="email" name="email" value="{{ old('email') }}" maxlength="255" required>
                    </label>

                    <label class="mg-book-label">
                        Initial Password
                        <input type="password" name="password" autocomplete="new-password" required>
                    </label>

                    <label class="mg-book-label">
                        Confirm Password
                        <input type="password" name="password_confirmation" autocomplete="new-password" required>
                    </label>
                </div>

                <div class="mg-staff-actions">
                    <button type="submit" class="mg-appt-details">+ Add Doctor</button>
                </div>
            </form>
        </section>
    </div>
@endsection
