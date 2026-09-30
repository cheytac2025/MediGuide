@extends('layouts.admin')

@section('title', 'Add Clinic')

@section('content')
    <div class="mg-staff-appt-page">
        <section class="mg-panel">
            <div class="mg-appt-head">
                <div>
                    <h1 class="mg-panel-title">Add Clinic</h1>
                    <p class="mg-appt-subtitle">Create a clinic under an existing department.</p>
                </div>
                <a class="mg-appt-back" href="{{ route('admin.clinics') }}">Back to Clinics</a>
            </div>

            @if ($errors->any())
                <div class="mg-book-alert" role="alert">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('admin.clinics.store') }}" class="mg-staff-schedule-form">
                @csrf

                <div class="mg-staff-schedule-grid">
                    <label class="mg-book-label">
                        Clinic Name
                        <input type="text" name="name" value="{{ old('name') }}" maxlength="255" required>
                    </label>

                    <label class="mg-book-label">
                        Department
                        <select name="department_id" required>
                            <option value="">Select a department</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}" @selected((string) old('department_id') === (string) $department->id)>
                                    {{ $department->name }} ({{ $department->status->label() }})
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="mg-book-label">
                        Status
                        <select name="status" required>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}" @selected(old('status', \App\Enums\ClinicStatus::Active->value) === $status->value)>
                                    {{ $status->label() }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <label class="mg-book-label">
                    Description
                    <textarea name="description" class="mg-book-textarea" maxlength="2000" rows="4">{{ old('description') }}</textarea>
                </label>

                <div class="mg-staff-actions">
                    <button type="submit" class="mg-appt-details">+ Add Clinic</button>
                </div>
            </form>
        </section>
    </div>
@endsection
