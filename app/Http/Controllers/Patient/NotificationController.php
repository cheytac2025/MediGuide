<?php

namespace App\Http\Controllers\Patient;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\User;
use App\Support\PatientHeader;
use App\Support\PatientNotifications;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $filter = PatientNotifications::normalizeFilter($request->query('filter'));
        $notifications = PatientNotifications::ordered($user, $filter)->get();

        return view('patient.notifications', [
            'patient' => PatientHeader::from($user),
            'active' => 'notifications',
            'notifications' => $notifications,
            'groups' => PatientNotifications::groups($notifications),
            'filter' => $filter,
            'hasNotifications' => $user->notifications()->exists(),
        ]);
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->unreadNotifications->markAsRead();

        return redirect()
            ->route('patient.notifications')
            ->with('notification_status', 'All notifications marked as read.');
    }

    public function markRead(Request $request, string $notification): RedirectResponse
    {
        $record = $this->ownedNotification($request, $notification);
        $record->markAsRead();

        return redirect()->route('patient.notifications');
    }

    public function destroy(Request $request, string $notification): RedirectResponse
    {
        $this->ownedNotification($request, $notification)->delete();

        return redirect()
            ->route('patient.notifications')
            ->with('notification_status', 'Notification removed.');
    }

    public function clear(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->notifications()->delete();

        return redirect()
            ->route('patient.notifications')
            ->with('notification_status', 'All notifications cleared.');
    }

    public function open(Request $request, string $notification): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $record = $this->ownedNotification($request, $notification);
        $record->markAsRead();

        $appointmentId = filter_var($record->data['appointment_id'] ?? null, FILTER_VALIDATE_INT);

        if ($appointmentId === false || $appointmentId < 1) {
            return redirect()
                ->route('patient.notifications')
                ->with('notification_error', 'That appointment is no longer available.');
        }

        $appointment = Appointment::query()
            ->whereKey($appointmentId)
            ->whereHas('patient', fn ($query) => $query->where('user_id', $user->id))
            ->first();

        if (! $appointment instanceof Appointment) {
            return redirect()
                ->route('patient.notifications')
                ->with('notification_error', 'That appointment is no longer available.');
        }

        return redirect()->route('patient.appointments.show', $appointment);
    }

    private function ownedNotification(Request $request, string $notificationId): DatabaseNotification
    {
        /** @var User $user */
        $user = $request->user();

        return $user->notifications()->whereKey($notificationId)->firstOrFail();
    }
}
