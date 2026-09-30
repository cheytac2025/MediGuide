<?php

namespace App\Http\Controllers\Staff;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\User;
use App\Notifications\AppointmentConfirmedNotification;
use App\Notifications\AppointmentRejectedNotification;
use App\Services\HospitalStaffScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StaffAppointmentController extends Controller
{
    public function __construct(
        private readonly HospitalStaffScope $scope,
    ) {}

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $statusFilter = $this->resolvedStatus($request->query('status'));

        $appointments = $this->scope->appointments($user)
            ->with(['patient.user', 'doctor.clinic'])
            ->when(
                $statusFilter instanceof AppointmentStatus,
                fn (Builder $query) => $query->where('status', $statusFilter),
            );

        $this->applyOrdering($appointments, $statusFilter);

        return view('staff.appointments', [
            'staff' => $this->staffHeader($user),
            'active' => 'appointments',
            'appointments' => $appointments->get(),
            'statusFilter' => $statusFilter,
            'statusFilters' => AppointmentStatus::cases(),
            'emptyMessage' => $this->emptyMessage($statusFilter),
        ]);
    }

    public function show(Request $request, int $appointment): View
    {
        /** @var User $user */
        $user = $request->user();

        $record = $this->scope->appointment($user, $appointment);
        $record->load(['patient.user', 'doctor.clinic.department']);

        return view('staff.appointment-show', [
            'staff' => $this->staffHeader($user),
            'active' => 'appointments',
            'appointment' => $record,
        ]);
    }

    public function confirm(Request $request, int $appointment): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $record = $this->scope->appointment($user, $appointment);

        if (! $record->status->canStaffConfirm()) {
            return redirect()
                ->route('staff.appointments.show', $record)
                ->with('appointment_error', 'This appointment is no longer pending and cannot be confirmed.');
        }

        $record->update([
            'status' => AppointmentStatus::Confirmed,
        ]);

        $record->loadMissing(['doctor', 'patient.user']);
        $record->patient?->user?->notify(new AppointmentConfirmedNotification($record));

        return redirect()
            ->route('staff.appointments.show', $record)
            ->with('appointment_status', 'Appointment confirmed successfully.');
    }

    public function reject(Request $request, int $appointment): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $record = $this->scope->appointment($user, $appointment);

        if (! $record->status->canStaffReject()) {
            return redirect()
                ->route('staff.appointments.show', $record)
                ->with('appointment_error', 'This appointment is no longer pending and cannot be rejected.');
        }

        $record->update([
            'status' => AppointmentStatus::Rejected,
        ]);

        $record->loadMissing(['doctor', 'patient.user']);
        $record->patient?->user?->notify(new AppointmentRejectedNotification($record));

        return redirect()
            ->route('staff.appointments.show', $record)
            ->with('appointment_status', 'Appointment request rejected.');
    }

    private function resolvedStatus(mixed $value): ?AppointmentStatus
    {
        if (! is_string($value) || $value === '' || $value === 'all') {
            return null;
        }

        return AppointmentStatus::tryFrom($value);
    }

    /**
     * @param  Builder<Appointment>  $query
     */
    private function applyOrdering(Builder $query, ?AppointmentStatus $status): void
    {
        if ($status === null) {
            $query
                ->orderByRaw("CASE WHEN status IN ('pending', 'confirmed') THEN 0 ELSE 1 END")
                ->orderByRaw("CASE WHEN status IN ('pending', 'confirmed') THEN appointment_date END ASC")
                ->orderByRaw("CASE WHEN status IN ('pending', 'confirmed') THEN start_time END ASC")
                ->orderByRaw("CASE WHEN status NOT IN ('pending', 'confirmed') THEN appointment_date END DESC")
                ->orderByRaw("CASE WHEN status NOT IN ('pending', 'confirmed') THEN start_time END DESC");

            return;
        }

        if ($status->isUpcoming()) {
            $query->orderBy('appointment_date')->orderBy('start_time');

            return;
        }

        $query->orderByDesc('appointment_date')->orderByDesc('start_time');
    }

    private function emptyMessage(?AppointmentStatus $status): string
    {
        return match ($status) {
            AppointmentStatus::Pending => 'No pending appointments.',
            AppointmentStatus::Confirmed => 'No confirmed appointments.',
            AppointmentStatus::Completed => 'No completed appointments.',
            AppointmentStatus::Cancelled => 'No cancelled appointments.',
            AppointmentStatus::Rejected => 'No rejected appointments.',
            null => 'No appointments.',
        };
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
