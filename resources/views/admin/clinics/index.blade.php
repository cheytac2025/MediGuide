@extends('layouts.admin')

@section('title', 'Clinic Management')

@section('content')
    <div class="mg-staff-appt-page">
        <section class="mg-panel">
            <div class="mg-appt-head">
                <div>
                    <h1 class="mg-panel-title">Clinic Management</h1>
                    <p class="mg-appt-subtitle">Manage hospital clinics and their availability.</p>
                </div>
                <a class="mg-appt-details" href="{{ route('admin.clinics.create') }}">+ Add Clinic</a>
            </div>

            @if (session('clinic_status'))
                <div class="mg-book-success" role="status">
                    <p>{{ session('clinic_status') }}</p>
                </div>
            @endif

            @if (session('clinic_error'))
                <div class="mg-book-alert" role="alert">{{ session('clinic_error') }}</div>
            @endif

            <nav class="mg-staff-filters" aria-label="Clinic status filters">
                <a
                    href="{{ route('admin.clinics') }}"
                    class="mg-staff-filter {{ $statusFilter === null ? 'is-active' : '' }}"
                    @if ($statusFilter === null) aria-current="page" @endif
                >All</a>
                <a
                    href="{{ route('admin.clinics', ['status' => 'active']) }}"
                    class="mg-staff-filter {{ $statusFilter === \App\Enums\ClinicStatus::Active ? 'is-active' : '' }}"
                    @if ($statusFilter === \App\Enums\ClinicStatus::Active) aria-current="page" @endif
                >Active</a>
                <a
                    href="{{ route('admin.clinics', ['status' => 'inactive']) }}"
                    class="mg-staff-filter {{ $statusFilter === \App\Enums\ClinicStatus::Inactive ? 'is-active' : '' }}"
                    @if ($statusFilter === \App\Enums\ClinicStatus::Inactive) aria-current="page" @endif
                >Inactive</a>
            </nav>

            @if ($clinics->isEmpty())
                <p class="mg-book-empty">No clinics match this view.</p>
            @else
                <div class="mg-staff-appt-list">
                    @foreach ($clinics as $clinic)
                        <article class="mg-staff-request-card">
                            <div class="mg-staff-request-top">
                                <div>
                                    <h3>{{ $clinic->name }}</h3>
                                    <p>{{ $clinic->department?->name ?? 'Unknown department' }}</p>
                                    <p class="mg-appt-muted">{{ $clinic->description ?: 'No description' }}</p>
                                    <p class="mg-appt-muted">Updated {{ $clinic->updated_at?->format('F j, Y') }}</p>
                                </div>
                                <span class="mg-status is-{{ $clinic->status->value === 'active' ? 'confirmed' : 'cancelled' }}">
                                    {{ $clinic->status->label() }}
                                </span>
                            </div>

                            <div class="mg-staff-actions">
                                <a class="mg-appt-details" href="{{ route('admin.clinics.edit', $clinic) }}">Edit</a>
                                @if ($clinic->status === \App\Enums\ClinicStatus::Active)
                                    <button type="button" class="mg-appt-cancel" data-mg-clinic-open="{{ $clinic->id }}">Deactivate</button>
                                @else
                                    <button type="button" class="mg-appt-details" data-mg-clinic-open="{{ $clinic->id }}">Activate</button>
                                @endif
                            </div>

                            <div class="mg-cancel-dialog mg-staff-confirm-dialog" data-mg-clinic-dialog="{{ $clinic->id }}" hidden>
                                @if ($clinic->status === \App\Enums\ClinicStatus::Active)
                                    <p>Deactivate {{ $clinic->name }}?</p>
                                    <p class="mg-staff-action-note">New bookings and AI recommendations will stop using this clinic. Existing doctors and appointments stay on record.</p>
                                @else
                                    <p>Activate {{ $clinic->name }}?</p>
                                    <p class="mg-staff-action-note">The clinic can be activated only when its department is active.</p>
                                @endif
                                <div class="mg-cancel-actions">
                                    <button type="button" class="mg-appt-keep" data-mg-clinic-cancel="{{ $clinic->id }}">Cancel</button>
                                    <form method="POST" action="{{ route('admin.clinics.status', $clinic) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="status" value="{{ $clinic->status === \App\Enums\ClinicStatus::Active ? 'inactive' : 'active' }}">
                                        <button type="submit" class="mg-appt-details">
                                            {{ $clinic->status === \App\Enums\ClinicStatus::Active ? 'Deactivate' : 'Activate' }}
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
        document.querySelectorAll('[data-mg-clinic-open]').forEach((button) => {
            button.addEventListener('click', () => {
                const id = button.getAttribute('data-mg-clinic-open');
                document.querySelectorAll('[data-mg-clinic-dialog]').forEach((dialog) => dialog.setAttribute('hidden', ''));
                document.querySelector(`[data-mg-clinic-dialog="${id}"]`)?.removeAttribute('hidden');
            });
        });

        document.querySelectorAll('[data-mg-clinic-cancel]').forEach((button) => {
            button.addEventListener('click', () => {
                const id = button.getAttribute('data-mg-clinic-cancel');
                document.querySelector(`[data-mg-clinic-dialog="${id}"]`)?.setAttribute('hidden', '');
            });
        });
    </script>
@endpush
