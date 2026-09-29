<?php

namespace App\Http\Controllers\Patient;

use App\Enums\ClinicStatus;
use App\Enums\DepartmentStatus;
use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AiFrontDeskController extends Controller
{
    /**
     * Display the AI-assisted virtual front desk (UI + mock interaction only).
     */
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $authenticatedUser = $user instanceof User ? $user : null;
        $isPatient = $authenticatedUser !== null && $authenticatedUser->hasRole(RoleName::Patient);

        $patient = null;
        $greetingName = null;

        if ($isPatient) {
            $firstName = $authenticatedUser->first_name ?: Str::before($authenticatedUser->name, ' ');
            $patient = [
                'name' => $authenticatedUser->name,
                'first_name' => $firstName,
                'role' => 'Patient',
                'initials' => $authenticatedUser->initials(),
                'unread_notifications' => 1,
            ];
            $greetingName = $firstName;
        }

        $quickPrompts = [
            'I have a headache',
            'I have stomach pain',
            'I have a fever',
            "I'm not sure which specialist I need",
        ];

        $clinic = $this->resolveMockClinic();

        $mockRecommendation = [
            'development' => true,
            'source' => 'DEVELOPMENT DATA',
            'title' => 'Mock Recommendation',
            'clinic_id' => $clinic?->id,
            'clinic' => $clinic?->name,
            'department' => $clinic?->department?->name,
            'summary' => 'Based on the information you provided, this type of concern may be handled by this department.',
            'doctors_url' => $clinic instanceof Clinic
                ? route('ai-front-desk.clinics.doctors', $clinic)
                : null,
            'booking_intent_url' => route('ai-front-desk.booking-intent'),
            'book_appointment_url' => route('patient.book-appointment'),
            'is_authenticated_patient' => $isPatient,
        ];

        $frontDeskConfig = [
            'maxLength' => 1000,
            'patientInitials' => $authenticatedUser?->initials() ?: 'Y',
            'mockRecommendation' => $mockRecommendation,
            'csrfToken' => csrf_token(),
            'processingLabel' => 'Analyzing your concern...',
            'processingSteps' => [
                'Understanding your description',
                'Identifying the concern',
                'Finding an appropriate service',
            ],
        ];

        return view('patient.ai-front-desk', [
            'layout' => $isPatient ? 'layouts.patient' : 'layouts.public',
            'patient' => $patient,
            'active' => 'front-desk',
            'greetingName' => $greetingName,
            'quickPrompts' => $quickPrompts,
            'mockRecommendation' => $mockRecommendation,
            'maxLength' => 1000,
            'frontDeskConfig' => $frontDeskConfig,
        ]);
    }

    /**
     * Resolve a single ACTIVE development clinic with an ACTIVE department.
     * No clinic/department IDs are hardcoded.
     */
    private function resolveMockClinic(): ?Clinic
    {
        return Clinic::query()
            ->with('department')
            ->where('status', ClinicStatus::Active)
            ->whereHas('department', fn ($query) => $query->where('status', DepartmentStatus::Active))
            ->orderBy('id')
            ->first();
    }
}
