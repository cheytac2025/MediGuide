<?php

namespace App\Http\Controllers\Staff;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
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
            'pendingCount' => Appointment::query()
                ->where('status', AppointmentStatus::Pending)
                ->count(),
            'todaysCount' => Appointment::query()
                ->where('status', AppointmentStatus::Confirmed)
                ->whereDate('appointment_date', $today)
                ->count(),
            'upcomingCount' => Appointment::query()
                ->where('status', AppointmentStatus::Confirmed)
                ->whereDate('appointment_date', '>', $today)
                ->count(),
            'completedCount' => Appointment::query()
                ->where('status', AppointmentStatus::Completed)
                ->count(),
            'recentPending' => $this->recentPendingRequests(),
        ]);
    }

    /**
     * @return Collection<int, Appointment>
     */
    private function recentPendingRequests(): Collection
    {
        return Appointment::query()
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
