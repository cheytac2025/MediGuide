<?php

namespace App\Http\Controllers\Patient;

use App\Enums\RoleName;
use App\Http\Controllers\Controller;
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

        $mockRecommendation = [
            'development' => true,
            'source' => 'DEVELOPMENT DATA',
            'title' => 'Mock Recommendation',
            'department' => 'Development Department',
            'specialist' => 'Test Specialist',
            'summary' => 'Based on the information you provided, this type of concern may be handled by this department.',
        ];

        $frontDeskConfig = [
            'maxLength' => 1000,
            'patientInitials' => $authenticatedUser?->initials() ?: 'Y',
            'mockRecommendation' => $mockRecommendation,
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
}
