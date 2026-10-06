<?php

namespace App\Http\Controllers\Patient;

use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Ai\ClaudeGuidanceService;
use App\Support\AiConversationContext;
use App\Support\PatientHeader;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiFrontDeskController extends Controller
{
    /**
     * Display the AI virtual front desk. Guidance is requested from the backend.
     */
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $authenticatedUser = $user instanceof User ? $user : null;
        $isPatient = $authenticatedUser !== null && $authenticatedUser->hasRole(RoleName::Patient);

        $patient = null;
        $greetingName = null;

        if ($isPatient) {
            $patient = PatientHeader::from($authenticatedUser);
            $greetingName = $patient['first_name'];
        }

        $quickPrompts = [
            'I have a headache',
            'I have stomach pain',
            'I have a fever',
            "I'm not sure which specialist I need",
        ];

        $frontDeskConfig = [
            'maxLength' => AiConversationContext::MAX_CHARACTERS,
            'patientInitials' => $authenticatedUser?->initials() ?: 'Y',
            'csrfToken' => csrf_token(),
            'guidanceUrl' => route('ai-front-desk.guidance'),
            'clearConversationUrl' => route('ai-front-desk.conversation.clear'),
            'fallbackMessage' => ClaudeGuidanceService::PROCESSING_FALLBACK_MESSAGE,
            'rateLimitMessage' => AiGuidanceController::RATE_LIMIT_MESSAGE,
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
            'maxLength' => AiConversationContext::MAX_CHARACTERS,
            'frontDeskConfig' => $frontDeskConfig,
        ]);
    }
}
