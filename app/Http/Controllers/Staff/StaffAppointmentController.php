<?php

namespace App\Http\Controllers\Staff;

use App\Enums\AppointmentMessageKind;
use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\AuditOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\ConfirmAppointmentRequest;
use App\Http\Requests\Staff\RejectAppointmentRequest;
use App\Models\Appointment;
use App\Models\User;
use App\Notifications\AppointmentConfirmedNotification;
use App\Notifications\AppointmentRejectedNotification;
use App\Services\AppointmentMessageRecorder;
use App\Services\HospitalStaffScope;
use App\Support\Audit\AuditTrailService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $dateFilter = $this->dateFilter($request->query('date'));
        $doctorFilter = $this->doctorFilter($user, $request->query('doctor'));

        $appointments = $this->scope->appointments($user)
            ->with(['patient.user', 'doctor.clinic'])
            ->when(
                $statusFilter instanceof AppointmentStatus,
                fn (Builder $query) => $query->where('status', $statusFilter),
            )
            ->when(
                $dateFilter !== null,
                fn (Builder $query) => $query->whereDate('appointment_date', $dateFilter),
            )
            ->when(
                $doctorFilter !== null,
                fn (Builder $query) => $query->where('doctor_id', $doctorFilter),
            );

        $this->applyOrdering($appointments, $statusFilter);

        return view('staff.appointments', [
            'staff' => $this->staffHeader($user),
            'active' => 'appointments',
            'appointments' => $appointments->paginate(12)->withQueryString(),
            'statusFilter' => $statusFilter,
            'statusFilters' => AppointmentStatus::cases(),
            'dateFilter' => $dateFilter,
            'doctorFilter' => $doctorFilter,
            'doctors' => $this->scope->doctors($user)->orderBy('display_name')->get(),
            'listQuery' => $this->listQuery($request),
            'emptyMessage' => ($dateFilter !== null || $doctorFilter !== null) && $statusFilter === null
                ? 'No appointments match these filters.'
                : $this->emptyMessage($statusFilter, $dateFilter !== null || $doctorFilter !== null),
        ]);
    }

    public function show(Request $request, int $appointment): View
    {
        /** @var User $user */
        $user = $request->user();

        $record = $this->scope->appointment($user, $appointment);
        $record->load(['patient.user', 'doctor.clinic.department', 'messages.actor']);

        return view('staff.appointment-show', [
            'staff' => $this->staffHeader($user),
            'active' => 'appointments',
            'appointment' => $record,
            'listQuery' => $this->listQuery($request),
        ]);
    }

    public function confirm(ConfirmAppointmentRequest $request, int $appointment): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $record = $this->scope->appointment($user, $appointment);
        $applied = false;

        DB::transaction(function () use ($record, $user, $request, &$applied): void {
            $this->scope->appointment($user, (int) $record->id);

            /** @var Appointment $locked */
            $locked = Appointment::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canStaffConfirm()) {
                return;
            }

            $locked->update([
                'status' => AppointmentStatus::Confirmed,
            ]);

            app(AppointmentMessageRecorder::class)->record(
                $locked,
                AppointmentMessageKind::ApprovalNote,
                $request->note(),
                $user,
            );

            app(AuditTrailService::class)->recordFor(
                AuditAction::AppointmentConfirmed,
                AuditOutcome::Success,
                $locked,
                [
                    'doctor_id' => (int) $locked->doctor_id,
                    'patient_id' => (int) $locked->patient_id,
                    'status' => AppointmentStatus::Confirmed->value,
                ],
                $user,
            );

            $applied = true;
        });

        if (! $applied) {
            return $this->appointmentRedirect($request, $record)
                ->with('appointment_error', 'This appointment is no longer pending and cannot be confirmed.');
        }

        $record->refresh()->loadMissing(['doctor', 'patient.user']);
        $record->patient?->user?->notify(new AppointmentConfirmedNotification($record));

        return $this->appointmentRedirect($request, $record)
            ->with('appointment_status', 'Appointment confirmed successfully.');
    }

    public function reject(RejectAppointmentRequest $request, int $appointment): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $record = $this->scope->appointment($user, $appointment);
        $applied = false;

        DB::transaction(function () use ($record, $user, $request, &$applied): void {
            $this->scope->appointment($user, (int) $record->id);

            /** @var Appointment $locked */
            $locked = Appointment::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canStaffReject()) {
                return;
            }

            $locked->update([
                'status' => AppointmentStatus::Rejected,
            ]);

            app(AppointmentMessageRecorder::class)->record(
                $locked,
                AppointmentMessageKind::RejectionReason,
                $request->reason(),
                $user,
            );

            app(AuditTrailService::class)->recordFor(
                AuditAction::AppointmentRejected,
                AuditOutcome::Success,
                $locked,
                [
                    'doctor_id' => (int) $locked->doctor_id,
                    'patient_id' => (int) $locked->patient_id,
                    'status' => AppointmentStatus::Rejected->value,
                ],
                $user,
            );

            $applied = true;
        });

        if (! $applied) {
            return $this->appointmentRedirect($request, $record)
                ->with('appointment_error', 'This appointment is no longer pending and cannot be rejected.');
        }

        $record->refresh()->loadMissing(['doctor', 'patient.user']);
        $record->patient?->user?->notify(new AppointmentRejectedNotification($record));

        return $this->appointmentRedirect($request, $record)
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

    private function emptyMessage(?AppointmentStatus $status, bool $filtered = false): string
    {
        if ($filtered) {
            return 'No appointments match these filters.';
        }

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
     * @return array<string, string>
     */
    private function appointmentRedirect(Request $request, Appointment $record): RedirectResponse
    {
        return redirect()->route(
            'staff.appointments.show',
            ['appointment' => $record] + $this->listQuery($request),
        );
    }

    private function listQuery(Request $request): array
    {
        $query = [];

        foreach (['status', 'date', 'doctor'] as $key) {
            $value = $request->input($key);

            if (! is_string($value) || $value === '') {
                $value = $request->query($key);
            }

            if (is_string($value) && $value !== '') {
                $query[$key] = $value;
            }
        }

        return $query;
    }

    private function dateFilter(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function doctorFilter(User $user, mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $id = (int) $value;

        return $this->scope->doctors($user)->whereKey($id)->exists() ? $id : null;
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
