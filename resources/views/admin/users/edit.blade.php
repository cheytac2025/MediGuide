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

            @if (session('account_status'))
                <div class="mg-book-alert" role="status">{{ session('account_status') }}</div>
            @endif

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

            <section class="mg-panel" style="margin-top: 1.5rem;">
                <h2 class="mg-panel-title">Account invitation</h2>
                @if ($account->status === \App\Enums\UserStatus::PendingActivation)
                    <p class="mg-appt-subtitle">
                        This account is pending activation.
                        @if ($openInvitation)
                            An open invitation expires {{ $openInvitation->expires_at->timezone(config('app.timezone'))->format('M j, Y g:i A T') }}.
                        @else
                            No open invitation is available — resend after creating a new one is not automatic; use Resend if one exists, or create a claim path after correcting email.
                        @endif
                    </p>
                    <div class="mg-staff-actions">
                        <form method="POST" action="{{ route('admin.users.invitation.resend', $account) }}">
                            @csrf
                            <button type="submit" class="mg-appt-details">Resend activation invitation</button>
                        </form>
                        <form method="POST" action="{{ route('admin.users.invitation.revoke', $account) }}">
                            @csrf
                            <button type="submit" class="mg-appt-details">Revoke invitation</button>
                        </form>
                    </div>
                @else
                    <p class="mg-appt-subtitle">
                        For existing Active/Inactive accounts with an inaccessible or corrected email: update the email above,
                        save, then send a claim invitation. The recipient verifies inbox ownership and sets a password.
                        This does not convert the account to Pending Activation automatically.
                    </p>
                    <div class="mg-staff-actions">
                        <form method="POST" action="{{ route('admin.users.invitation.claim', $account) }}">
                            @csrf
                            <button type="submit" class="mg-appt-details">Send claim invitation</button>
                        </form>
                        @if ($openInvitation)
                            <form method="POST" action="{{ route('admin.users.invitation.resend', $account) }}">
                                @csrf
                                <button type="submit" class="mg-appt-details">Resend open invitation</button>
                            </form>
                            <form method="POST" action="{{ route('admin.users.invitation.revoke', $account) }}">
                                @csrf
                                <button type="submit" class="mg-appt-details">Revoke open invitation</button>
                            </form>
                        @endif
                    </div>
                @endif
            </section>
        </section>
    </div>
@endsection
