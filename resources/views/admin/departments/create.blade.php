@extends('layouts.admin')

@section('title', 'Add Department')

@section('content')
    <div class="mg-staff-appt-page">
        <section class="mg-panel">
            <div class="mg-appt-head">
                <div>
                    <h1 class="mg-panel-title">Add Department</h1>
                    <p class="mg-appt-subtitle">Create a hospital department for clinics and staff assignments.</p>
                </div>
                <a class="mg-appt-back" href="{{ route('admin.departments') }}">Back to Departments</a>
            </div>

            @if ($errors->any())
                <div class="mg-book-alert" role="alert">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('admin.departments.store') }}" class="mg-staff-schedule-form">
                @csrf

                <div class="mg-staff-schedule-grid">
                    <label class="mg-book-label">
                        Department Name
                        <input type="text" name="name" value="{{ old('name') }}" maxlength="255" required>
                    </label>

                    <label class="mg-book-label">
                        Status
                        <select name="status" required>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}" @selected(old('status', \App\Enums\DepartmentStatus::Active->value) === $status->value)>
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
                    <button type="submit" class="mg-appt-details">+ Add Department</button>
                </div>
            </form>
        </section>
    </div>
@endsection
