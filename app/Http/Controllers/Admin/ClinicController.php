<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ClinicStatus;
use App\Enums\DepartmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreClinicRequest;
use App\Http\Requests\Admin\UpdateClinicRequest;
use App\Http\Requests\Admin\UpdateClinicStatusRequest;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ClinicController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $statusFilter = $this->resolvedStatus($request->query('status'));

        $clinics = Clinic::query()
            ->with('department')
            ->when(
                $statusFilter instanceof ClinicStatus,
                fn ($query) => $query->where('status', $statusFilter),
            )
            ->orderBy('name')
            ->get();

        return view('admin.clinics.index', [
            'admin' => $this->adminHeader($user),
            'active' => 'clinics',
            'clinics' => $clinics,
            'statusFilter' => $statusFilter,
        ]);
    }

    public function create(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('admin.clinics.create', [
            'admin' => $this->adminHeader($user),
            'active' => 'clinics',
            'departments' => $this->departments(),
            'statuses' => ClinicStatus::cases(),
        ]);
    }

    public function store(StoreClinicRequest $request): RedirectResponse
    {
        try {
            Clinic::query()->create($request->clinicAttributes());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'name' => 'A clinic with this name already exists in the selected department.',
            ]);
        }

        return redirect()
            ->route('admin.clinics')
            ->with('clinic_status', 'Clinic added successfully.');
    }

    public function edit(Request $request, Clinic $clinic): View
    {
        /** @var User $user */
        $user = $request->user();
        $clinic->load('department');

        return view('admin.clinics.edit', [
            'admin' => $this->adminHeader($user),
            'active' => 'clinics',
            'clinic' => $clinic,
            'departments' => $this->departments(),
            'statuses' => ClinicStatus::cases(),
        ]);
    }

    public function update(UpdateClinicRequest $request, Clinic $clinic): RedirectResponse
    {
        try {
            $clinic->update($request->clinicAttributes());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'name' => 'A clinic with this name already exists in the selected department.',
            ]);
        }

        return redirect()
            ->route('admin.clinics')
            ->with('clinic_status', 'Clinic updated successfully.');
    }

    public function updateStatus(UpdateClinicStatusRequest $request, Clinic $clinic): RedirectResponse
    {
        $status = ClinicStatus::from((string) $request->validated('status'));
        $clinic->loadMissing('department');

        if ($status === ClinicStatus::Active && $clinic->department->status !== DepartmentStatus::Active) {
            return redirect()
                ->route('admin.clinics')
                ->with('clinic_error', 'This clinic cannot be activated because its department is inactive.');
        }

        $clinic->update(['status' => $status]);

        return redirect()
            ->route('admin.clinics')
            ->with(
                'clinic_status',
                $status === ClinicStatus::Active
                    ? 'Clinic activated successfully.'
                    : 'Clinic deactivated successfully.',
            );
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Department>
     */
    private function departments()
    {
        return Department::query()->orderBy('name')->get();
    }

    private function resolvedStatus(mixed $value): ?ClinicStatus
    {
        if (! is_string($value) || $value === '' || $value === 'all') {
            return null;
        }

        return ClinicStatus::tryFrom($value);
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
