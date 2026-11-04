@extends('layouts.admin')

@section('title', 'Edit Department')

@section('content')
    <div class="mg-staff-appt-page">
        <section class="mg-panel">
            <div class="mg-appt-head">
                <div>
                    <h1 class="mg-panel-title">Edit Department</h1>
                    <p class="mg-appt-subtitle">{{ $department->name }}</p>
                </div>
                <a class="mg-appt-back" href="{{ route('admin.departments') }}">Back to Departments</a>
            </div>

            @if (session('department_status'))
                <div class="mg-book-success" role="status">
                    <p>{{ session('department_status') }}</p>
                </div>
            @endif

            @if ($errors->any())
                <div class="mg-book-alert" role="alert">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('admin.departments.update', $department) }}" class="mg-staff-schedule-form">
                @csrf
                @method('PATCH')

                <div class="mg-staff-schedule-grid">
                    <label class="mg-book-label">
                        Department Name
                        <input type="text" name="name" value="{{ old('name', $department->name) }}" maxlength="255" required>
                    </label>

                    <label class="mg-book-label">
                        Status
                        <select name="status" required>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}" @selected(old('status', $department->status->value) === $status->value)>
                                    {{ $status->label() }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <label class="mg-book-label">
                    Description
                    <textarea name="description" class="mg-book-textarea" maxlength="2000" rows="4">{{ old('description', $department->description) }}</textarea>
                </label>

                <div class="mg-staff-actions">
                    <button type="submit" class="mg-appt-details">Save Changes</button>
                </div>
            </form>
        </section>
    </div>
@endsection
