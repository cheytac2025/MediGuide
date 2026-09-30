<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreHospitalStaffRequest;
use App\Http\Requests\Admin\UpdateManagedUserRequest;
use App\Http\Requests\Admin\UpdateManagedUserStatusRequest;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class UserAccountController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $filter = $this->resolvedFilter($request->query('filter'));

        $accounts = User::query()
            ->with(['role', 'doctor'])
            ->whereHas('role', function ($query) use ($filter): void {
                $slugs = match ($filter) {
                    'staff' => [RoleName::HospitalStaff->value],
                    'doctors' => [RoleName::Doctor->value],
                    default => [RoleName::HospitalStaff->value, RoleName::Doctor->value],
                };

                $query->whereIn('slug', $slugs);
            })
            ->when(
                in_array($filter, ['active', 'inactive'], true),
                fn ($query) => $query->where('status', $filter),
            )
            ->orderBy('name')
            ->get();

        return view('admin.users.index', [
            'admin' => $this->adminHeader($user),
            'active' => 'accounts',
            'accounts' => $accounts,
            'filter' => $filter,
        ]);
    }

    public function hospitalStaff(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $accounts = User::query()
            ->with(['hospitalStaff.departments' => fn ($query) => $query->orderBy('name')])
            ->whereHas('role', fn ($query) => $query->where('slug', RoleName::HospitalStaff->value))
            ->orderBy('name')
            ->get();

        return view('admin.hospital-staff.index', [
            'admin' => $this->adminHeader($user),
            'active' => 'hospital-staff',
            'accounts' => $accounts,
        ]);
    }

    public function createStaff(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('admin.users.create-staff', [
            'admin' => $this->adminHeader($user),
            'active' => 'accounts',
            'statuses' => UserStatus::cases(),
            'departments' => Department::query()->orderBy('name')->get(),
        ]);
    }

    public function storeStaff(StoreHospitalStaffRequest $request): RedirectResponse
    {
        $attributes = $request->accountAttributes();
        $role = Role::query()->where('slug', RoleName::HospitalStaff->value)->firstOrFail();

        DB::transaction(function () use ($attributes, $request, $role): void {
            $user = User::query()->create([
                'role_id' => $role->id,
                'first_name' => $attributes['first_name'],
                'middle_name' => $attributes['middle_name'],
                'last_name' => $attributes['last_name'],
                'name' => $this->fullName($attributes['first_name'], $attributes['middle_name'], $attributes['last_name']),
                'email' => $attributes['email'],
                'password' => $attributes['password'],
                'status' => $attributes['status'],
                'email_verified_at' => now(),
            ]);

            $this->syncStaffDepartments($user, $request->departmentIds());
        });

        return redirect()
            ->route('admin.users')
            ->with('account_status', 'Hospital Staff account created successfully.');
    }

    public function edit(Request $request, User $user): View
    {
        $this->ensureManagedAccount($request, $user);

        /** @var User $admin */
        $admin = $request->user();
        $user->load(['role', 'doctor', 'hospitalStaff.departments']);
        $isStaff = $user->hasRole(RoleName::HospitalStaff);

        return view('admin.users.edit', [
            'admin' => $this->adminHeader($admin),
            'active' => 'accounts',
            'account' => $user,
            'statuses' => UserStatus::cases(),
            'departments' => $isStaff ? Department::query()->orderBy('name')->get() : collect(),
            'assignedDepartmentIds' => $isStaff
                ? ($user->hospitalStaff?->departments->pluck('id')->map(fn (mixed $id): int => (int) $id)->all() ?? [])
                : [],
        ]);
    }

    public function update(UpdateManagedUserRequest $request, User $user): RedirectResponse
    {
        $this->ensureManagedAccount($request, $user);

        $attributes = $request->accountAttributes();

        DB::transaction(function () use ($attributes, $request, $user): void {
            $user->update([
                'first_name' => $attributes['first_name'],
                'middle_name' => $attributes['middle_name'],
                'last_name' => $attributes['last_name'],
                'name' => $this->fullName($attributes['first_name'], $attributes['middle_name'], $attributes['last_name']),
                'email' => $attributes['email'],
                'status' => $attributes['status'],
            ]);

            if ($user->hasRole(RoleName::HospitalStaff)) {
                $this->syncStaffDepartments($user, $request->departmentIds());
            }
        });

        return redirect()
            ->route('admin.users')
            ->with('account_status', 'Account updated successfully.');
    }

    public function updateStatus(UpdateManagedUserStatusRequest $request, User $user): RedirectResponse
    {
        $this->ensureManagedAccount($request, $user);

        $status = UserStatus::from((string) $request->validated('status'));
        $user->update(['status' => $status]);

        return redirect()
            ->route('admin.users')
            ->with(
                'account_status',
                $status === UserStatus::Active
                    ? 'Account activated successfully.'
                    : 'Account deactivated successfully.',
            );
    }

    /**
     * @param  list<int>  $departmentIds
     */
    private function syncStaffDepartments(User $user, array $departmentIds): void
    {
        $profile = $user->hospitalStaff()->firstOrCreate([]);
        $profile->departments()->sync($departmentIds);
    }

    private function ensureManagedAccount(Request $request, User $user): void
    {
        if ($request->user()?->is($user)) {
            abort(403, 'You cannot change your own account from this page.');
        }

        $user->loadMissing('role');

        if (! $user->hasRole(RoleName::HospitalStaff) && ! $user->hasRole(RoleName::Doctor)) {
            abort(404);
        }
    }

    private function resolvedFilter(mixed $value): string
    {
        $allowed = ['all', 'staff', 'doctors', 'active', 'inactive'];

        return is_string($value) && in_array($value, $allowed, true) ? $value : 'all';
    }

    private function fullName(string $firstName, ?string $middleName, string $lastName): string
    {
        return collect([$firstName, $middleName, $lastName])
            ->filter(fn (?string $part): bool => filled($part))
            ->implode(' ');
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
