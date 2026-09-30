<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ClinicStatus;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorStatus;
use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('admin.dashboard', [
            'admin' => $this->adminHeader($user),
            'active' => 'dashboard',
            'activeClinicCount' => Clinic::query()
                ->where('status', ClinicStatus::Active)
                ->whereHas('department', fn ($query) => $query->where('status', DepartmentStatus::Active))
                ->count(),
            'activeDoctorCount' => Doctor::query()
                ->where('status', DoctorStatus::Active)
                ->count(),
            'hospitalStaffCount' => $this->usersWithRole(RoleName::HospitalStaff),
            'doctorAccountCount' => $this->usersWithRole(RoleName::Doctor),
            'patientAccountCount' => $this->usersWithRole(RoleName::Patient),
        ]);
    }

    private function usersWithRole(RoleName $role): int
    {
        return User::query()
            ->whereHas('role', fn ($query) => $query->where('slug', $role->value))
            ->count();
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
