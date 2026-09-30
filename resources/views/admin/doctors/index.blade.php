@extends('layouts.admin')

@section('title', 'Doctor Management')

@section('content')
    <div class="mg-staff-appt-page">
        <section class="mg-panel">
            <div class="mg-appt-head">
                <div>
                    <h1 class="mg-panel-title">Doctor Management</h1>
                    <p class="mg-appt-subtitle">Manage doctors and their linked accounts.</p>
                </div>
                <a class="mg-appt-details" href="{{ route('admin.doctors.create') }}">+ Add Doctor</a>
            </div>

            @if (session('doctor_status'))
                <div class="mg-book-success" role="status"><p>{{ session('doctor_status') }}</p></div>
            @endif

            @if (session('doctor_error'))
                <div class="mg-book-alert" role="alert">{{ session('doctor_error') }}</div>
            @endif

            <nav class="mg-staff-filters" aria-label="Doctor status filters">
                <a href="{{ route('admin.doctors') }}" class="mg-staff-filter {{ $statusFilter === null ? 'is-active' : '' }}">All</a>
                <a href="{{ route('admin.doctors', ['status' => 'active']) }}" class="mg-staff-filter {{ $statusFilter === \App\Enums\DoctorStatus::Active ? 'is-active' : '' }}">Active</a>
                <a href="{{ route('admin.doctors', ['status' => 'inactive']) }}" class="mg-staff-filter {{ $statusFilter === \App\Enums\DoctorStatus::Inactive ? 'is-active' : '' }}">Inactive</a>
            </nav>

            @if ($doctors->isEmpty())
                <p class="mg-book-empty">No doctors match this view.</p>
            @else
                <div class="mg-staff-appt-list">
                    @foreach ($doctors as $doctor)
                        <article class="mg-staff-request-card">
                            <div class="mg-staff-request-top">
                                <div>
                                    <h3>{{ $doctor->display_name }}</h3>
                                    <p>{{ $doctor->specialization ?: 'No specialization listed' }}</p>
                                    <p class="mg-appt-muted">
                                        {{ $doctor->clinic?->name ?? 'Unknown clinic' }}
                                        ·
                                        {{ $doctor->clinic?->department?->name ?? 'Unknown department' }}
                                    </p>
                                    <p class="mg-appt-muted">
                                        @if ($doctor->user)
                                            {{ $doctor->user->email }} · Account {{ strtoupper($doctor->user->status->value) }}
                                        @else
                                            No linked account
                                        @endif
                                    </p>
                                    <p class="mg-appt-muted">Updated {{ $doctor->updated_at?->format('F j, Y') }}</p>
                                </div>
                                <span class="mg-status is-{{ $doctor->status->value === 'active' ? 'confirmed' : 'cancelled' }}">
                                    {{ $doctor->status->label() }}
                                </span>
                            </div>

                            <div class="mg-staff-actions">
                                <a class="mg-appt-details" href="{{ route('admin.doctors.edit', $doctor) }}">Edit</a>
                                @if ($doctor->status === \App\Enums\DoctorStatus::Active)
                                    <button type="button" class="mg-appt-cancel" data-mg-doctor-open="{{ $doctor->id }}">Deactivate</button>
                                @else
                                    <button type="button" class="mg-appt-details" data-mg-doctor-open="{{ $doctor->id }}">Activate</button>
                                @endif
                            </div>

                            <div class="mg-cancel-dialog mg-staff-confirm-dialog" data-mg-doctor-dialog="{{ $doctor->id }}" hidden>
                                <p>{{ $doctor->status === \App\Enums\DoctorStatus::Active ? 'Deactivate' : 'Activate' }} {{ $doctor->display_name }}?</p>
                                <p class="mg-staff-action-note">
                                    @if ($doctor->status === \App\Enums\DoctorStatus::Active)
                                        New bookings will stop. Existing schedules and appointments stay on record.
                                    @else
                                        The doctor can be activated only when the clinic and department are active.
                                    @endif
                                </p>
                                <div class="mg-cancel-actions">
                                    <button type="button" class="mg-appt-keep" data-mg-doctor-cancel="{{ $doctor->id }}">Cancel</button>
                                    <form method="POST" action="{{ route('admin.doctors.status', $doctor) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="status" value="{{ $doctor->status === \App\Enums\DoctorStatus::Active ? 'inactive' : 'active' }}">
                                        <button type="submit" class="mg-appt-details">
                                            {{ $doctor->status === \App\Enums\DoctorStatus::Active ? 'Deactivate' : 'Activate' }}
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
        document.querySelectorAll('[data-mg-doctor-open]').forEach((button) => {
            button.addEventListener('click', () => {
                const id = button.getAttribute('data-mg-doctor-open');
                document.querySelectorAll('[data-mg-doctor-dialog]').forEach((dialog) => dialog.setAttribute('hidden', ''));
                document.querySelector(`[data-mg-doctor-dialog="${id}"]`)?.removeAttribute('hidden');
            });
        });

        document.querySelectorAll('[data-mg-doctor-cancel]').forEach((button) => {
            button.addEventListener('click', () => {
                const id = button.getAttribute('data-mg-doctor-cancel');
                document.querySelector(`[data-mg-doctor-dialog="${id}"]`)?.setAttribute('hidden', '');
            });
        });
    </script>
@endpush
