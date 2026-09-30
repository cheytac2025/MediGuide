@extends('layouts.admin')

@section('title', 'Hospital Staff Management')

@section('content')
    <div class="mg-staff-appt-page">
        <section class="mg-panel">
            <div class="mg-appt-head">
                <div>
                    <h1 class="mg-panel-title">Hospital Staff Management</h1>
                    <p class="mg-appt-subtitle">Manage Hospital Staff accounts and department assignments.</p>
                </div>
                <a class="mg-appt-details" href="{{ route('admin.users.staff.create') }}">+ Add Hospital Staff</a>
            </div>

            @if (session('account_status'))
                <div class="mg-book-success" role="status"><p>{{ session('account_status') }}</p></div>
            @endif

            @if (session('account_error'))
                <div class="mg-book-alert" role="alert">{{ session('account_error') }}</div>
            @endif

            @if ($accounts->isEmpty())
                <p class="mg-book-empty">No Hospital Staff accounts yet.</p>
            @else
                <div class="mg-staff-appt-list">
                    @foreach ($accounts as $account)
                        <article class="mg-staff-request-card">
                            <div class="mg-staff-request-top">
                                <div>
                                    <h3>{{ $account->name }}</h3>
                                    <p>{{ $account->email }}</p>
                                    <p class="mg-appt-muted">Created {{ $account->created_at?->format('F j, Y') }}</p>
                                    <p>Assigned Departments:</p>
                                    @if ($account->hospitalStaff === null || $account->hospitalStaff->departments->isEmpty())
                                        <p>No departments assigned</p>
                                    @else
                                        <ul>
                                            @foreach ($account->hospitalStaff->departments as $department)
                                                <li>{{ $department->name }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                                <span class="mg-status is-{{ $account->status->value === 'active' ? 'confirmed' : 'cancelled' }}">
                                    {{ strtoupper($account->status->value) }}
                                </span>
                            </div>

                            <div class="mg-staff-actions">
                                <a class="mg-appt-details" href="{{ route('admin.users.edit', $account) }}">Edit</a>
                                @if ($account->status === \App\Enums\UserStatus::Active)
                                    <button type="button" class="mg-appt-cancel" data-mg-account-open="{{ $account->id }}">Deactivate</button>
                                @else
                                    <button type="button" class="mg-appt-details" data-mg-account-open="{{ $account->id }}">Activate</button>
                                @endif
                            </div>

                            <div class="mg-cancel-dialog mg-staff-confirm-dialog" data-mg-account-dialog="{{ $account->id }}" hidden>
                                <p>{{ $account->status === \App\Enums\UserStatus::Active ? 'Deactivate' : 'Activate' }} {{ $account->name }}?</p>
                                <p class="mg-staff-action-note">This changes whether the account can sign in. It does not delete the account.</p>
                                <div class="mg-cancel-actions">
                                    <button type="button" class="mg-appt-keep" data-mg-account-cancel="{{ $account->id }}">Cancel</button>
                                    <form method="POST" action="{{ route('admin.users.status', $account) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="status" value="{{ $account->status === \App\Enums\UserStatus::Active ? 'inactive' : 'active' }}">
                                        <button type="submit" class="mg-appt-details">
                                            {{ $account->status === \App\Enums\UserStatus::Active ? 'Deactivate' : 'Activate' }}
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('[data-mg-account-open]').forEach((button) => {
            button.addEventListener('click', () => {
                const id = button.getAttribute('data-mg-account-open');
                document.querySelectorAll('[data-mg-account-dialog]').forEach((dialog) => dialog.setAttribute('hidden', ''));
                document.querySelector(`[data-mg-account-dialog="${id}"]`)?.removeAttribute('hidden');
            });
        });

        document.querySelectorAll('[data-mg-account-cancel]').forEach((button) => {
            button.addEventListener('click', () => {
                const id = button.getAttribute('data-mg-account-cancel');
                document.querySelector(`[data-mg-account-dialog="${id}"]`)?.setAttribute('hidden', '');
            });
        });
    </script>
@endpush
