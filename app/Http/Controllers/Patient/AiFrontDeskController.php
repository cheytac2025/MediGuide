<?php

namespace App\Http\Controllers\Patient;

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
        /** @var User $user */
        $user = $request->user();

        $patient = [
            'name' => $user->name,
            'first_name' => $user->first_name ?: Str::before($user->name, ' '),
            'role' => 'Patient',
            'initials' => $user->initials(),
            'unread_notifications' => 1,
        ];

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
            'patientInitials' => $patient['initials'],
            'mockRecommendation' => $mockRecommendation,
            'processingLabel' => 'Analyzing your concern...',
            'processingSteps' => [
                'Understanding your description',
                'Identifying the concern',
                'Finding an appropriate service',
            ],
        ];

        return view('patient.ai-front-desk', [
            'patient' => $patient,
            'active' => 'front-desk',
            'quickPrompts' => $quickPrompts,
            'mockRecommendation' => $mockRecommendation,
            'maxLength' => 1000,
            'frontDeskConfig' => $frontDeskConfig,
        ]);
    }
}
