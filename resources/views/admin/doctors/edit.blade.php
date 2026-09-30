@extends('layouts.admin')

@section('title', 'Edit Doctor')

@section('content')
    <div class="mg-staff-appt-page">
        <section class="mg-panel">
            <div class="mg-appt-head">
                <div>
                    <h1 class="mg-panel-title">Edit Doctor</h1>
                    <p class="mg-appt-subtitle">{{ $doctorRecord->display_name }}</p>
                </div>
                <a class="mg-appt-back" href="{{ route('admin.doctors') }}">Back to Doctors</a>
            </div>

            @if ($errors->any())
                <div class="mg-book-alert" role="alert">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('admin.doctors.update', $doctorRecord) }}" class="mg-staff-schedule-form">
                @csrf
                @method('PATCH')

                <div class="mg-staff-schedule-grid">
                    <label class="mg-book-label">
                        Display Name
                        <input type="text" name="display_name" value="{{ old('display_name', $doctorRecord->display_name) }}" maxlength="255" required>
                    </label>

                    <label class="mg-book-label">
                        Clinic
                        <select name="clinic_id" required>
                            @foreach ($clinics as $clinic)
                                <option value="{{ $clinic->id }}" @selected((string) old('clinic_id', $doctorRecord->clinic_id) === (string) $clinic->id)>
                                    {{ $clinic->name }} ({{ $clinic->status->label() }}) · {{ $clinic->department?->name }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="mg-book-label">
                        Specialization
                        <input type="text" name="specialization" value="{{ old('specialization', $doctorRecord->specialization) }}" maxlength="255">
                    </label>

                    <label class="mg-book-label">
                        Status
                        <select name="status" required>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}" @selected(old('status', $doctorRecord->status->value) === $status->value)>
                                    {{ $status->label() }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    @if ($doctorRecord->user)
                        <label class="mg-book-label">
                            First Name
                            <input type="text" name="first_name" value="{{ old('first_name', $doctorRecord->user->first_name) }}" maxlength="100" required>
                        </label>

                        <label class="mg-book-label">
                            Middle Name
                            <input type="text" name="middle_name" value="{{ old('middle_name', $doctorRecord->user->middle_name) }}" maxlength="100">
                        </label>

                        <label class="mg-book-label">
                            Last Name
                            <input type="text" name="last_name" value="{{ old('last_name', $doctorRecord->user->last_name) }}" maxlength="100" required>
                        </label>

                        <label class="mg-book-label">
                            Email
                            <input type="email" name="email" value="{{ old('email', $doctorRecord->user->email) }}" maxlength="255" required>
                        </label>
                    @endif
                </div>

                <div class="mg-staff-actions">
                    <button type="submit" class="mg-appt-details">Save Changes</button>
                </div>
            </form>
        </section>
    </div>
@endsection
