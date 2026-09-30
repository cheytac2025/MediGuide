<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DoctorStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDoctorRequest;
use App\Http\Requests\Admin\UpdateDoctorRequest;
use App\Http\Requests\Admin\UpdateDoctorStatusRequest;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\User;
use App\Services\DoctorManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class DoctorController extends Controller
{
    public function __construct(
        private readonly DoctorManagementService $doctors,
    ) {}

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $statusFilter = $this->resolvedStatus($request->query('status'));

        $doctors = Doctor::query()
            ->with(['clinic.department', 'user'])
            ->when(
                $statusFilter instanceof DoctorStatus,
                fn ($query) => $query->where('status', $statusFilter),
            )
            ->orderBy('display_name')
            ->get();

        return view('admin.doctors.index', [
            'admin' => $this->adminHeader($user),
            'active' => 'doctors',
            'doctors' => $doctors,
            'statusFilter' => $statusFilter,
        ]);
    }

    public function create(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('admin.doctors.create', [
            'admin' => $this->adminHeader($user),
            'active' => 'doctors',
            'clinics' => $this->clinics(),
            'statuses' => DoctorStatus::cases(),
        ]);
    }

    public function store(StoreDoctorRequest $request): RedirectResponse
    {
        try {
            $this->doctors->create($request->doctorAttributes(), $request->accountAttributes());
        } catch (ValidationException $exception) {
            return back()->withInput($request->except(['password', 'password_confirmation']))->withErrors($exception->errors());
        } catch (Throwable) {
            return back()
                ->withInput($request->except(['password', 'password_confirmation']))
                ->withErrors(['email' => 'The doctor account could not be created. Please try again.']);
        }

        return redirect()
            ->route('admin.doctors')
            ->with('doctor_status', 'Doctor added successfully.');
    }

    public function edit(Request $request, Doctor $doctor): View
    {
        /** @var User $user */
        $user = $request->user();
        $doctor->load(['clinic.department', 'user']);

        return view('admin.doctors.edit', [
            'admin' => $this->adminHeader($user),
            'active' => 'doctors',
            'doctorRecord' => $doctor,
            'clinics' => $this->clinics(),
            'statuses' => DoctorStatus::cases(),
        ]);
    }

    public function update(UpdateDoctorRequest $request, Doctor $doctor): RedirectResponse
    {
        try {
            $this->doctors->update($doctor, $request->doctorAttributes(), $request->accountAttributes());
        } catch (ValidationException $exception) {
            return back()->withInput()->withErrors($exception->errors());
        }

        return redirect()
            ->route('admin.doctors')
            ->with('doctor_status', 'Doctor updated successfully.');
    }

    public function updateStatus(UpdateDoctorStatusRequest $request, Doctor $doctor): RedirectResponse
    {
        $status = DoctorStatus::from((string) $request->validated('status'));

        try {
            $this->doctors->updateStatus($doctor, $status);
        } catch (ValidationException $exception) {
            return redirect()
                ->route('admin.doctors')
                ->with('doctor_error', collect($exception->errors())->flatten()->first());
        }

        return redirect()
            ->route('admin.doctors')
            ->with(
                'doctor_status',
                $status === DoctorStatus::Active
                    ? 'Doctor activated successfully.'
                    : 'Doctor deactivated successfully.',
            );
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Clinic>
     */
    private function clinics()
    {
        return Clinic::query()->with('department')->orderBy('name')->get();
    }

    private function resolvedStatus(mixed $value): ?DoctorStatus
    {
        if (! is_string($value) || $value === '' || $value === 'all') {
            return null;
        }

        return DoctorStatus::tryFrom($value);
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
