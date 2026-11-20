<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountInvitationPurpose;
use App\Enums\AuditAction;
use App\Enums\AuditOutcome;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreHospitalStaffRequest;
use App\Http\Requests\Admin\UpdateManagedUserRequest;
use App\Http\Requests\Admin\UpdateManagedUserStatusRequest;
use App\Models\AccountInvitation;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\AccountInvitationService;
use App\Support\Audit\AuditTrailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class UserAccountController extends Controller
{
    public function __construct(private readonly AccountInvitationService $invitations) {}

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
                in_array($filter, ['active', 'inactive', 'pending'], true),
                fn ($query) => $query->where(
                    'status',
                    $filter === 'pending' ? UserStatus::PendingActivation->value : $filter,
                ),
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
            'departments' => Department::query()->orderBy('name')->get(),
        ]);
    }

    public function storeStaff(StoreHospitalStaffRequest $request): RedirectResponse
    {
        $attributes = $request->accountAttributes();
        $role = Role::query()->where('slug', RoleName::HospitalStaff->value)->firstOrFail();
        /** @var User $admin */
        $admin = $request->user();

        try {
            $user = DB::transaction(function () use ($attributes, $request, $role): User {
                $user = User::query()->create([
                    'role_id' => $role->id,
                    'first_name' => $attributes['first_name'],
                    'middle_name' => $attributes['middle_name'],
                    'last_name' => $attributes['last_name'],
                    'name' => $this->fullName($attributes['first_name'], $attributes['middle_name'], $attributes['last_name']),
                    'email' => $attributes['email'],
                    'password' => Str::password(32),
                    'status' => UserStatus::PendingActivation,
                    'email_verified_at' => null,
                ]);
                $departmentIds = $request->departmentIds();
                $this->syncStaffDepartments($user, $departmentIds);

                app(AuditTrailService::class)->recordFor(
                    AuditAction::StaffAccountCreated,
                    AuditOutcome::Success,
                    $user,
                    [
                        'department_ids' => $departmentIds,
                        'role' => RoleName::HospitalStaff->value,
                        'status' => UserStatus::PendingActivation->value,
                    ],
                );

                return $user;
            });

            $this->invitations->issue($user, AccountInvitationPurpose::Activation, $admin);
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput($request->except([]))
                ->withErrors(['email' => 'The account could not be created or the invitation email failed. Try again or resend from the account page.']);
        }

        return redirect()
            ->route('admin.users')
            ->with('account_status', 'Hospital Staff account created. An activation invitation was sent.');
    }

    public function edit(Request $request, User $user): View
    {
        $this->ensureManagedAccount($request, $user);

        /** @var User $admin */
        $admin = $request->user();
        $user->load(['role', 'doctor', 'hospitalStaff.departments']);
        $isStaff = $user->hasRole(RoleName::HospitalStaff);
        $openInvitation = AccountInvitation::query()
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();

        return view('admin.users.edit', [
            'admin' => $this->adminHeader($admin),
            'active' => 'accounts',
            'account' => $user,
            'statuses' => $this->editableStatusesFor($user),
            'departments' => $isStaff ? Department::query()->orderBy('name')->get() : collect(),
            'assignedDepartmentIds' => $isStaff
                ? ($user->hospitalStaff?->departments->pluck('id')->map(fn (mixed $id): int => (int) $id)->all() ?? [])
                : [],
            'openInvitation' => $openInvitation,
        ]);
    }

    public function update(UpdateManagedUserRequest $request, User $user): RedirectResponse
    {
        $this->ensureManagedAccount($request, $user);

        $attributes = $request->accountAttributes();

        DB::transaction(function () use ($attributes, $request, $user): void {
            $previousStatus = $user->status instanceof UserStatus ? $user->status->value : (string) $user->status;
            $previousEmail = (string) $user->email;
            $user->loadMissing('hospitalStaff.departments');
            $previousDepartments = $user->hospitalStaff?->departments->pluck('id')->map(fn (mixed $id): int => (int) $id)->all() ?? [];
            sort($previousDepartments);
            $user->update([
                'first_name' => $attributes['first_name'],
                'middle_name' => $attributes['middle_name'],
                'last_name' => $attributes['last_name'],
                'name' => $this->fullName($attributes['first_name'], $attributes['middle_name'], $attributes['last_name']),
                'email' => $attributes['email'],
                'status' => $attributes['status'],
            ]);

            if ($previousEmail !== (string) $attributes['email'] && $user->status !== UserStatus::PendingActivation) {
                $user->forceFill(['email_verified_at' => null])->save();
            }

            if ($user->hasRole(RoleName::HospitalStaff)) {
                $departmentIds = array_map(intval(...), $request->departmentIds());
                sort($departmentIds);
                $this->syncStaffDepartments($user, $departmentIds);

                if ($previousDepartments !== $departmentIds) {
                    app(AuditTrailService::class)->recordFor(
                        AuditAction::DepartmentAssignmentChanged,
                        AuditOutcome::Success,
                        $user,
                        ['department_ids' => $departmentIds],
                    );
                }
            }

            if ($previousStatus !== (string) $attributes['status']) {
                app(AuditTrailService::class)->recordFor(
                    AuditAction::AccountStatusChanged,
                    AuditOutcome::Success,
                    $user,
                    [
                        'previous_status' => $previousStatus,
                        'status' => (string) $attributes['status'],
                    ],
                );
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
        $previousStatus = $user->status instanceof UserStatus ? $user->status->value : (string) $user->status;

        if ($user->status === UserStatus::PendingActivation && $status === UserStatus::Active) {
            return back()->withErrors([
                'status' => 'Pending accounts become active only through the invitation activation flow.',
            ]);
        }

        DB::transaction(function () use ($user, $status, $previousStatus): void {
            $user->update(['status' => $status]);

            app(AuditTrailService::class)->recordFor(
                AuditAction::AccountStatusChanged,
                AuditOutcome::Success,
                $user,
                [
                    'previous_status' => $previousStatus,
                    'status' => $status->value,
                ],
            );
        });

        return redirect()
            ->route('admin.users')
            ->with(
                'account_status',
                $status === UserStatus::Active
                    ? 'Account activated successfully.'
                    : 'Account deactivated successfully.',
            );
    }

    public function resendInvitation(Request $request, User $user): RedirectResponse
    {
        $this->ensureManagedAccount($request, $user);

        $invitation = AccountInvitation::query()
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->orderByDesc('id')
            ->first();

        if ($invitation === null || ! $invitation->isOpen()) {
            return back()->withErrors(['email' => 'No open invitation exists for this account.']);
        }

        /** @var User $admin */
        $admin = $request->user();

        try {
            if (! $this->invitations->resend($invitation, $admin)) {
                return back()->withErrors(['email' => 'Please wait before resending the invitation.']);
            }
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['email' => 'The invitation email could not be sent. Try again later.']);
        }

        return back()->with('account_status', 'Invitation resent.');
    }

    public function revokeInvitation(Request $request, User $user): RedirectResponse
    {
        $this->ensureManagedAccount($request, $user);

        $invitation = AccountInvitation::query()
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->orderByDesc('id')
            ->first();

        if ($invitation === null) {
            return back()->withErrors(['email' => 'No open invitation exists for this account.']);
        }

        /** @var User $admin */
        $admin = $request->user();
        $this->invitations->revoke($invitation, $admin);

        return back()->with('account_status', 'Invitation revoked.');
    }

    /**
     * Existing-account remediation: correct email first, then send a claim invitation
     * so the recipient proves inbox ownership and sets a password without converting
     * legacy Active accounts to Pending Activation automatically.
     */
    public function sendClaimInvitation(Request $request, User $user): RedirectResponse
    {
        $this->ensureManagedAccount($request, $user);

        if ($user->status === UserStatus::PendingActivation) {
            return back()->withErrors([
                'email' => 'Pending accounts use activation invitations, not claim invitations.',
            ]);
        }

        /** @var User $admin */
        $admin = $request->user();

        try {
            $this->invitations->issue($user, AccountInvitationPurpose::Claim, $admin);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['email' => 'The claim invitation could not be sent. Check the email address and try again.']);
        }

        return back()->with('account_status', 'Account claim invitation sent.');
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
            app(AuditTrailService::class)->recordSafely(
                AuditAction::AuthorizationDenied,
                AuditOutcome::Denied,
                'user',
                (int) $user->id,
                ['reason' => 'self_account'],
                $request->user() instanceof User ? $request->user() : null,
            );

            abort(403, 'You cannot change your own account from this page.');
        }

        $user->loadMissing('role');

        if (! $user->hasRole(RoleName::HospitalStaff) && ! $user->hasRole(RoleName::Doctor)) {
            abort(404);
        }
    }

    /**
     * @return list<UserStatus>
     */
    private function editableStatusesFor(User $user): array
    {
        if ($user->status === UserStatus::PendingActivation) {
            return [UserStatus::PendingActivation, UserStatus::Inactive];
        }

        return [UserStatus::Active, UserStatus::Inactive];
    }

    private function resolvedFilter(mixed $value): string
    {
        $allowed = ['all', 'staff', 'doctors', 'active', 'inactive', 'pending'];

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
