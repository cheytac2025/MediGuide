@extends('layouts.admin')

@section('title', 'Edit Account')

@section('content')
    <div class="mg-staff-appt-page">
        <section class="mg-panel">
            <div class="mg-appt-head">
                <div>
                    <h1 class="mg-panel-title">Edit Account</h1>
                    <p class="mg-appt-subtitle">{{ $account->role?->name }} · {{ $account->email }}</p>
                </div>
                <a class="mg-appt-back" href="{{ route('admin.users') }}">Back to User Accounts</a>
            </div>

            @if ($errors->any())
                <div class="mg-book-alert" role="alert">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('admin.users.update', $account) }}" class="mg-staff-schedule-form">
                @csrf
                @method('PATCH')

                <div class="mg-staff-schedule-grid">
                    <label class="mg-book-label">
                        First Name
                        <input type="text" name="first_name" value="{{ old('first_name', $account->first_name) }}" maxlength="100" required>
                    </label>
                    <label class="mg-book-label">
                        Middle Name
                        <input type="text" name="middle_name" value="{{ old('middle_name', $account->middle_name) }}" maxlength="100">
                    </label>
                    <label class="mg-book-label">
                        Last Name
                        <input type="text" name="last_name" value="{{ old('last_name', $account->last_name) }}" maxlength="100" required>
                    </label>
                    <label class="mg-book-label">
                        Email
                        <input type="email" name="email" value="{{ old('email', $account->email) }}" maxlength="255" required>
                    </label>
                    <label class="mg-book-label">
                        Account Status
                        <select name="status" required>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}" @selected(old('status', $account->status->value) === $status->value)>
                                    {{ strtoupper($status->value) }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                </div>

                @if ($account->hasRole(\App\Enums\RoleName::HospitalStaff))
                    <div class="mg-book-label">
                        <span>Assigned Departments</span>
                        @foreach ($departments as $department)
                            <label>
                                <input
                                    type="checkbox"
                                    name="departments[]"
                                    value="{{ $department->id }}"
                                    @checked(collect(old('departments', $assignedDepartmentIds))->map(fn ($id) => (int) $id)->contains($department->id))
                                >
                                {{ $department->name }}
                            </label>
                        @endforeach
                    </div>
                @endif

                <div class="mg-staff-actions">
                    <button type="submit" class="mg-appt-details">Save Changes</button>
                </div>
            </form>
        </section>
    </div>
@endsection
