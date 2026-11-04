@extends('layouts.admin')

@section('title', 'Department Management')

@section('content')
    <div class="mg-staff-appt-page">
        <section class="mg-panel">
            <div class="mg-appt-head">
                <div>
                    <h1 class="mg-panel-title">Department Management</h1>
                    <p class="mg-appt-subtitle">Manage hospital departments and their availability.</p>
                </div>
                <a class="mg-appt-details" href="{{ route('admin.departments.create') }}">+ Add Department</a>
            </div>

            @if (session('department_status'))
                <div class="mg-book-success" role="status">
                    <p>{{ session('department_status') }}</p>
                </div>
            @endif

            @if (session('department_error'))
                <div class="mg-book-alert" role="alert">{{ session('department_error') }}</div>
            @endif

            <nav class="mg-staff-filters" aria-label="Department status filters">
                <a
                    href="{{ route('admin.departments') }}"
                    class="mg-staff-filter {{ $statusFilter === null ? 'is-active' : '' }}"
                    @if ($statusFilter === null) aria-current="page" @endif
                >All</a>
                <a
                    href="{{ route('admin.departments', ['status' => 'active']) }}"
                    class="mg-staff-filter {{ $statusFilter === \App\Enums\DepartmentStatus::Active ? 'is-active' : '' }}"
                    @if ($statusFilter === \App\Enums\DepartmentStatus::Active) aria-current="page" @endif
                >Active</a>
                <a
                    href="{{ route('admin.departments', ['status' => 'inactive']) }}"
                    class="mg-staff-filter {{ $statusFilter === \App\Enums\DepartmentStatus::Inactive ? 'is-active' : '' }}"
                    @if ($statusFilter === \App\Enums\DepartmentStatus::Inactive) aria-current="page" @endif
                >Inactive</a>
            </nav>

            @if ($departments->isEmpty())
                <p class="mg-book-empty">No departments match this view.</p>
            @else
                <div class="mg-staff-appt-list">
                    @foreach ($departments as $department)
                        @php
                            $openAppointments = (int) ($futureOpenAppointments[$department->id] ?? 0);
                        @endphp
                        <article class="mg-staff-request-card">
                            <div class="mg-staff-request-top">
                                <div>
                                    <h3>{{ $department->name }}</h3>
                                    <p class="mg-appt-muted">{{ $department->description ?: 'No description' }}</p>
                                    <p class="mg-appt-muted">
                                        {{ $department->clinics_count }} clinic{{ $department->clinics_count === 1 ? '' : 's' }}
                                        ·
                                        {{ $department->hospital_staff_count }} assigned staff
                                    </p>
                                    <p class="mg-appt-muted">Updated {{ $department->updated_at?->format('F j, Y') }}</p>
                                </div>
                                <span class="mg-status is-{{ $department->status->value === 'active' ? 'confirmed' : 'cancelled' }}">
                                    {{ $department->status->label() }}
                                </span>
                            </div>

                            <div class="mg-staff-actions">
                                <a class="mg-appt-details" href="{{ route('admin.departments.edit', $department) }}">Edit</a>
                                @if ($department->status === \App\Enums\DepartmentStatus::Active)
                                    <button type="button" class="mg-appt-cancel" data-mg-department-open="{{ $department->id }}">Deactivate</button>
                                @else
                                    <button type="button" class="mg-appt-details" data-mg-department-open="{{ $department->id }}">Activate</button>
                                @endif
                            </div>

                            <div class="mg-cancel-dialog mg-staff-confirm-dialog" data-mg-department-dialog="{{ $department->id }}" hidden>
                                @if ($department->status === \App\Enums\DepartmentStatus::Active)
                                    <p>Deactivate {{ $department->name }}?</p>
                                    <p class="mg-staff-action-note">
                                        New bookings and AI recommendations will stop using clinics in this department. Existing appointments stay on record.
                                        @if ($openAppointments > 0)
                                            This department currently has {{ $openAppointments }} pending or confirmed future appointment{{ $openAppointments === 1 ? '' : 's' }}.
                                        @endif
                                    </p>
                                @else
                                    <p>Activate {{ $department->name }}?</p>
                                    <p class="mg-staff-action-note">Active clinics in this department can become eligible for bookings and AI recommendations again.</p>
                                @endif
                                <div class="mg-cancel-actions">
                                    <button type="button" class="mg-appt-keep" data-mg-department-cancel="{{ $department->id }}">Cancel</button>
                                    <form method="POST" action="{{ route('admin.departments.status', $department) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="status" value="{{ $department->status === \App\Enums\DepartmentStatus::Active ? 'inactive' : 'active' }}">
                                        <button type="submit" class="mg-appt-details">
                                            {{ $department->status === \App\Enums\DepartmentStatus::Active ? 'Deactivate' : 'Activate' }}
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
        document.querySelectorAll('[data-mg-department-open]').forEach((button) => {
            button.addEventListener('click', () => {
                const id = button.getAttribute('data-mg-department-open');
                document.querySelectorAll('[data-mg-department-dialog]').forEach((dialog) => dialog.setAttribute('hidden', ''));
                document.querySelector(`[data-mg-department-dialog="${id}"]`)?.removeAttribute('hidden');
            });
        });

        document.querySelectorAll('[data-mg-department-cancel]').forEach((button) => {
            button.addEventListener('click', () => {
                const id = button.getAttribute('data-mg-department-cancel');
                document.querySelector(`[data-mg-department-dialog="${id}"]`)?.setAttribute('hidden', '');
            });
        });
    </script>
@endpush
