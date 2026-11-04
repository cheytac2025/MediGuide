<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\AuditOutcome;
use App\Enums\DepartmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDepartmentRequest;
use App\Http\Requests\Admin\UpdateDepartmentRequest;
use App\Http\Requests\Admin\UpdateDepartmentStatusRequest;
use App\Services\HospitalImport\RecordProvenanceService;
use App\Models\Appointment;
use App\Models\Department;
use App\Models\User;
use App\Support\Audit\AuditTrailService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DepartmentController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $statusFilter = $this->resolvedStatus($request->query('status'));

        $departments = Department::query()
            ->withCount(['clinics', 'hospitalStaff'])
            ->when(
                $statusFilter instanceof DepartmentStatus,
                fn ($query) => $query->where('status', $statusFilter),
            )
            ->orderBy('name')
            ->get();

        $futureOpenAppointments = $this->futureOpenAppointmentCounts(
            $departments->pluck('id')->all(),
        );

        return view('admin.departments.index', [
            'admin' => $this->adminHeader($user),
            'active' => 'departments',
            'departments' => $departments,
            'statusFilter' => $statusFilter,
            'futureOpenAppointments' => $futureOpenAppointments,
        ]);
    }

    public function create(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('admin.departments.create', [
            'admin' => $this->adminHeader($user),
            'active' => 'departments',
            'statuses' => DepartmentStatus::cases(),
        ]);
    }

    public function store(StoreDepartmentRequest $request): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request): void {
                $attributes = $request->departmentAttributes();
                $department = Department::query()->create($attributes);

                app(AuditTrailService::class)->recordFor(
                    AuditAction::DepartmentCreated,
                    AuditOutcome::Success,
                    $department,
                    [
                        'status' => (string) $attributes['status'],
                        'data_source' => (string) $attributes['data_source'],
                    ],
                );
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'name' => 'A department with this name already exists.',
            ]);
        }

        return redirect()
            ->route('admin.departments')
            ->with('department_status', 'Department added successfully.');
    }

    public function edit(Request $request, Department $department): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('admin.departments.edit', [
            'admin' => $this->adminHeader($user),
            'active' => 'departments',
            'department' => $department,
            'statuses' => DepartmentStatus::cases(),
        ]);
    }

    public function update(UpdateDepartmentRequest $request, Department $department): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $department): void {
                $attributes = $request->departmentAttributes();
                $before = $department->only(['name', 'description']);
                $department->update($attributes);

                $actor = $request->user();
                if ($actor instanceof User) {
                    app(RecordProvenanceService::class)->revokeIfClinicalFieldsChanged(
                        $department->fresh() ?? $department,
                        $before,
                        [
                            'name' => $attributes['name'],
                            'description' => $attributes['description'] ?? null,
                        ],
                        ['name', 'description'],
                        $actor,
                    );
                }

                app(AuditTrailService::class)->recordFor(
                    AuditAction::DepartmentUpdated,
                    AuditOutcome::Success,
                    $department,
                    ['status' => (string) $attributes['status']],
                );
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'name' => 'A department with this name already exists.',
            ]);
        }

        return redirect()
            ->route('admin.departments')
            ->with('department_status', 'Department updated successfully.');
    }

    public function updateStatus(UpdateDepartmentStatusRequest $request, Department $department): RedirectResponse
    {
        $status = DepartmentStatus::from((string) $request->validated('status'));

        DB::transaction(function () use ($department, $status): void {
            $this->hasFutureOpenAppointments($department);

            $department->update(['status' => $status]);

            app(AuditTrailService::class)->recordFor(
                AuditAction::DepartmentStatusChanged,
                AuditOutcome::Success,
                $department,
                ['status' => $status->value],
            );
        });

        return redirect()
            ->route('admin.departments')
            ->with(
                'department_status',
                $status === DepartmentStatus::Active
                    ? 'Department activated successfully.'
                    : 'Department deactivated successfully.',
            );
    }

    private function resolvedStatus(mixed $value): ?DepartmentStatus
    {
        if (! is_string($value) || $value === '' || $value === 'all') {
            return null;
        }

        return DepartmentStatus::tryFrom($value);
    }

    /**
     * Future pending or confirmed visits stay on record when a department is deactivated.
     */
    private function hasFutureOpenAppointments(Department $department): bool
    {
        return Appointment::query()
            ->whereIn('status', [AppointmentStatus::Pending, AppointmentStatus::Confirmed])
            ->whereDate('appointment_date', '>=', Carbon::today()->toDateString())
            ->whereHas('doctor.clinic', fn ($query) => $query->where('department_id', $department->id))
            ->exists();
    }

    /**
     * @param  list<int>  $departmentIds
     * @return array<int, int>
     */
    private function futureOpenAppointmentCounts(array $departmentIds): array
    {
        if ($departmentIds === []) {
            return [];
        }

        return Appointment::query()
            ->selectRaw('clinics.department_id as department_id, COUNT(*) as aggregate')
            ->join('doctors', 'doctors.id', '=', 'appointments.doctor_id')
            ->join('clinics', 'clinics.id', '=', 'doctors.clinic_id')
            ->whereIn('clinics.department_id', $departmentIds)
            ->whereIn('appointments.status', [AppointmentStatus::Pending->value, AppointmentStatus::Confirmed->value])
            ->whereDate('appointments.appointment_date', '>=', Carbon::today()->toDateString())
            ->groupBy('clinics.department_id')
            ->pluck('aggregate', 'department_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * @return array{name: string, first_name: string, role: string, initials: string}
     */
    private function adminHeader(User $user): array
    {
        return [
            'name' => $user->name,
            'first_name' => $user->first_name ?: Str::before($user->name, ' ') ?: $user->name,
            'role' => 'IT Administrator',
            'initials' => $user->initials(),
        ];
    }
}
