<?php

namespace App\Http\Controllers\Staff;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\User;
use App\Services\HospitalStaffScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly HospitalStaffScope $scope,
    ) {}

    /**
     * Display the hospital staff dashboard.
     */
    public function __invoke(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $today = Carbon::today();

        return view('staff.dashboard', [
            'staff' => $this->staffHeader($user),
            'active' => 'dashboard',
            'pendingCount' => $this->scope->appointments($user)
                ->where('status', AppointmentStatus::Pending)
                ->count(),
            'todaysCount' => $this->scope->appointments($user)
                ->where('status', AppointmentStatus::Confirmed)
                ->whereDate('appointment_date', $today)
                ->count(),
            'upcomingCount' => $this->scope->appointments($user)
                ->where('status', AppointmentStatus::Confirmed)
                ->whereDate('appointment_date', '>', $today)
                ->count(),
            'completedCount' => $this->scope->appointments($user)
                ->where('status', AppointmentStatus::Completed)
                ->count(),
            'recentPending' => $this->recentPendingRequests($user),
        ]);
    }

    /**
     * @return Collection<int, Appointment>
     */
    private function recentPendingRequests(User $user): Collection
    {
        return $this->scope->appointments($user)
            ->where('status', AppointmentStatus::Pending)
            ->with(['patient.user', 'doctor.clinic'])
            ->latest('id')
            ->limit(5)
            ->get();
    }

    /**
     * @return array{name: string, first_name: string, role: string, initials: string, unread_notifications: int}
     */
    private function staffHeader(User $user): array
    {
        return [
            'name' => $user->name,
            'first_name' => $user->first_name ?: $user->name,
            'role' => 'Hospital Staff',
            'initials' => $user->initials(),
            'unread_notifications' => 0,
        ];
    }
}
