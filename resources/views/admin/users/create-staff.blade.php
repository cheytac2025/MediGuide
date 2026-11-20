@extends('layouts.admin')

@section('title', 'Add Hospital Staff')

@section('content')
    <div class="mg-staff-appt-page">
        <section class="mg-panel">
            <div class="mg-appt-head">
                <div>
                    <h1 class="mg-panel-title">Add Hospital Staff</h1>
                    <p class="mg-appt-subtitle">Create a Hospital Staff sign-in account. The role is assigned automatically.</p>
                </div>
                <a class="mg-appt-back" href="{{ route('admin.users') }}">Back to User Accounts</a>
            </div>

            @if ($errors->any())
                <div class="mg-book-alert" role="alert">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('admin.users.staff.store') }}" class="mg-staff-schedule-form">
                @csrf

                <div class="mg-staff-schedule-grid">
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
                </div>

                <p class="mg-appt-subtitle">
                    The account starts as Pending Activation. MediGuide emails a single-use invitation (48 hours)
                    so the staff member verifies their inbox and chooses their own password.
                </p>

                <div class="mg-book-label">
                    <span>Assigned Departments</span>
                    @foreach ($departments as $department)
                        <label>
                            <input
                                type="checkbox"
                                name="departments[]"
                                value="{{ $department->id }}"
                                @checked(collect(old('departments', []))->map(fn ($id) => (int) $id)->contains($department->id))
                            >
                            {{ $department->name }}
                        </label>
                    @endforeach
                </div>

                <div class="mg-staff-actions">
                    <button type="submit" class="mg-appt-details">+ Add Hospital Staff</button>
                </div>
            </form>
        </section>
    </div>
@endsection
