@extends('layouts.admin')

@section('title', 'User Accounts')

@section('content')
    <div class="mg-staff-appt-page">
        <section class="mg-panel">
            <div class="mg-appt-head">
                <div>
                    <h1 class="mg-panel-title">User Accounts</h1>
                    <p class="mg-appt-subtitle">Manage Hospital Staff and Doctor sign-in accounts.</p>
                    <p class="mg-appt-muted">Doctor accounts are created through Doctor Management.</p>
                </div>
                <a class="mg-appt-details" href="{{ route('admin.users.staff.create') }}">+ Add Hospital Staff</a>
            </div>

            @if (session('account_status'))
                <div class="mg-book-success" role="status"><p>{{ session('account_status') }}</p></div>
            @endif

            @if (session('account_error'))
                <div class="mg-book-alert" role="alert">{{ session('account_error') }}</div>
            @endif

            <nav class="mg-staff-filters" aria-label="Account filters">
                @foreach (['all' => 'All', 'staff' => 'Hospital Staff', 'doctors' => 'Doctors', 'active' => 'Active', 'inactive' => 'Inactive'] as $key => $label)
                    <a
                        href="{{ route('admin.users', ['filter' => $key]) }}"
                        class="mg-staff-filter {{ $filter === $key ? 'is-active' : '' }}"
                        @if ($filter === $key) aria-current="page" @endif
                    >{{ $label }}</a>
                @endforeach
            </nav>

            @if ($accounts->isEmpty())
                <p class="mg-book-empty">No accounts match this view.</p>
            @else
                <div class="mg-staff-appt-list">
                    @foreach ($accounts as $account)
                        <article class="mg-staff-request-card">
                            <div class="mg-staff-request-top">
                                <div>
                                    <h3>{{ $account->name }}</h3>
                                    <p>{{ $account->email }}</p>
                                    <p class="mg-appt-muted">{{ $account->role?->name }}</p>
                                    @if ($account->doctor)
                                        <p class="mg-appt-muted">Linked doctor: {{ $account->doctor->display_name }}</p>
                                    @endif
                                    <p class="mg-appt-muted">Created {{ $account->created_at?->format('F j, Y') }}</p>
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
                                <p class="mg-staff-action-note">This changes whether the account can sign in. It does not delete the account or linked doctor records.</p>
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
