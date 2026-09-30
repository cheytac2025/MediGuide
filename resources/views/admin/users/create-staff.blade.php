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
                    <label class="mg-book-label">
                        Initial Password
                        <input type="password" name="password" autocomplete="new-password" required>
                    </label>
                    <label class="mg-book-label">
                        Confirm Password
                        <input type="password" name="password_confirmation" autocomplete="new-password" required>
                    </label>
                    <label class="mg-book-label">
                        Account Status
                        <select name="status" required>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}" @selected(old('status', \App\Enums\UserStatus::Active->value) === $status->value)>
                                    {{ strtoupper($status->value) }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <div class="mg-staff-actions">
                    <button type="submit" class="mg-appt-details">+ Add Hospital Staff</button>
                </div>
            </form>
        </section>
    </div>
@endsection
