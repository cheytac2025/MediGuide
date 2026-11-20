<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Doctor\Concerns\InteractsWithLinkedDoctor;
use App\Http\Requests\Doctor\PublishPatientInstructionsRequest;
use App\Http\Requests\Doctor\SaveConsultationDocumentationRequest;
use App\Models\Appointment;
use App\Models\User;
use App\Services\ConsultationDocumentationService;
use App\Support\DoctorHeader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DoctorConsultationDocumentationController extends Controller
{
    use InteractsWithLinkedDoctor;

    public function __construct(private readonly ConsultationDocumentationService $documentation) {}

    public function complete(Request $request, int $appointment): RedirectResponse
    {
        $doctor = $this->linkedDoctor($request);

        if ($doctor instanceof RedirectResponse) {
            return $doctor;
        }

        /** @var User $user */
        $user = $request->user();
        $record = $this->ownedAppointment($doctor->id, $appointment);

        $this->documentation->completeConsultation($record, $doctor, $user);

        return redirect()
            ->route('doctor.appointments.show', $record)
            ->with('consultation_status', 'Consultation marked completed. You may now publish patient instructions.');
    }

    public function saveDraft(SaveConsultationDocumentationRequest $request, int $appointment): RedirectResponse
    {
        $doctor = $this->linkedDoctor($request);

        if ($doctor instanceof RedirectResponse) {
            return $doctor;
        }

        /** @var User $user */
        $user = $request->user();
        $record = $this->ownedAppointment($doctor->id, $appointment);

        $this->documentation->saveDraft($record, $doctor, $user, $request->documentation());

        return redirect()
            ->route('doctor.appointments.show', $record)
            ->with('consultation_status', 'Draft saved. Patient and staff cannot see unpublished instructions.');
    }

    public function preview(SaveConsultationDocumentationRequest $request, int $appointment): View|RedirectResponse
    {
        $doctor = $this->linkedDoctor($request);

        if ($doctor instanceof RedirectResponse) {
            return $doctor;
        }

        /** @var User $user */
        $user = $request->user();
        $record = $this->ownedAppointment($doctor->id, $appointment);
        $record->load(['doctor.clinic.department', 'patient.user']);
        $input = $request->documentation();

        return view('doctor.consultation-preview', [
            'doctor' => DoctorHeader::from($user, $doctor),
            'active' => 'appointments',
            'appointment' => $record,
            'preview' => [
                'prescription' => (string) ($input['prescription'] ?? ''),
                'reminders' => (string) ($input['reminders'] ?? ''),
                'follow_up' => (string) ($input['follow_up'] ?? ''),
            ],
        ]);
    }

    public function publish(PublishPatientInstructionsRequest $request, int $appointment): RedirectResponse
    {
        $doctor = $this->linkedDoctor($request);

        if ($doctor instanceof RedirectResponse) {
            return $doctor;
        }

        /** @var User $user */
        $user = $request->user();
        $record = $this->ownedAppointment($doctor->id, $appointment);

        $result = $this->documentation->publish($record, $doctor, $user, $request->instructions());
        $revision = $result['revision']->revision_number;

        $message = $revision > 1
            ? 'Revised instructions published. The patient was notified.'
            : 'Instructions published. The patient was notified.';

        return redirect()
            ->route('doctor.appointments.show', $record)
            ->with('consultation_status', $message);
    }

    private function ownedAppointment(int $doctorId, int $appointmentId): Appointment
    {
        return Appointment::query()
            ->where('doctor_id', $doctorId)
            ->whereKey($appointmentId)
            ->firstOrFail();
    }
}
