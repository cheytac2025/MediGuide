<?php

namespace App\Http\Controllers\Patient;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PatientAppointmentController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $patient = $user->patient;
        $appointments = $this->appointmentsFor($patient);
        $upcoming = $appointments
            ->filter(fn (Appointment $appointment): bool => $appointment->status->isUpcoming())
            ->sortBy(fn (Appointment $appointment): string => $this->sortKey($appointment))
            ->values();
        $history = $appointments
            ->reject(fn (Appointment $appointment): bool => $appointment->status->isUpcoming())
            ->sortByDesc(fn (Appointment $appointment): string => $this->sortKey($appointment))
            ->values();

        return view('patient.appointments', [
            'patient' => $this->patientHeader($user),
            'active' => 'appointments',
            'submittedMessage' => $this->submittedMessage(),
            'upcoming' => $upcoming,
            'history' => $history,
        ]);
    }

    public function show(Request $request, int $appointment): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('patient.appointment-show', [
            'patient' => $this->patientHeader($user),
            'active' => 'appointments',
            'appointment' => $this->ownedAppointment($user, $appointment),
        ]);
    }

    public function cancel(Request $request, int $appointment): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $record = $this->ownedAppointment($user, $appointment);

        if (! $record->status->canBeCancelledByPatient()) {
            return redirect()
                ->route('patient.appointments.show', $record)
                ->with('appointment_error', 'This appointment can no longer be cancelled.');
        }

        $record->update([
            'status' => AppointmentStatus::Cancelled,
        ]);

        return redirect()
            ->route('patient.appointments.show', $record)
            ->with('appointment_status', 'Your appointment has been cancelled.');
    }

    /**
     * @return Collection<int, Appointment>
     */
    private function appointmentsFor(?Patient $patient): Collection
    {
        if (! $patient instanceof Patient) {
            return new Collection;
        }

        return Appointment::query()
            ->where('patient_id', $patient->id)
            ->with(['doctor.clinic.department'])
            ->get();
    }

    private function ownedAppointment(User $user, int $appointmentId): Appointment
    {
        $patient = $user->patient;

        if (! $patient instanceof Patient) {
            abort(404);
        }

        return Appointment::query()
            ->with(['doctor.clinic.department'])
            ->where('patient_id', $patient->id)
            ->whereKey($appointmentId)
            ->firstOrFail();
    }

    private function sortKey(Appointment $appointment): string
    {
        return $appointment->appointment_date->format('Y-m-d').' '.$appointment->start_time;
    }

    private function submittedMessage(): ?string
    {
        $submitted = session('appointment_submitted');

        if (is_array($submitted)) {
            $message = $submitted['message'] ?? null;

            return is_string($message) ? $message : null;
        }

        return is_string($submitted) ? $submitted : null;
    }

    /**
     * @return array{name: string, role: string, initials: string, unread_notifications: int}
     */
    private function patientHeader(User $user): array
    {
        return [
            'name' => $user->name,
            'role' => 'Patient',
            'initials' => $user->initials(),
            'unread_notifications' => 0,
        ];
    }
}
