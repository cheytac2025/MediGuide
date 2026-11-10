<?php

namespace App\Http\Controllers\Patient;

use App\Enums\AiGuidanceType;
use App\Enums\AiSafetyKind;
use App\Enums\ClinicStatus;
use App\Enums\DepartmentStatus;
use App\Enums\HospitalDataSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Patient\StoreAiGuidanceRequest;
use App\Models\Clinic;
use App\Services\Ai\AiSuggestedDoctorService;
use App\Services\Ai\ClaudeGuidanceService;
use App\Services\Ai\HospitalNavigationResolver;
use App\Support\AiConversationInterpreter;
use App\Support\AiFrontDeskConversation;
use App\Support\AiGuidanceResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiGuidanceController extends Controller
{
    public const string RATE_LIMIT_MESSAGE = 'Please wait a moment before sending another message, or ask hospital staff for assistance.';

    public function store(StoreAiGuidanceRequest $request, ClaudeGuidanceService $guidance): JsonResponse
    {
        $message = $request->messageText();

        if ($message === '') {
            return response()->json([
                'message' => 'Please describe your concern before sending.',
                'errors' => [
                    'message' => ['Please describe your concern before sending.'],
                ],
            ], 422);
        }

        $state = AiFrontDeskConversation::state($request->session());
        $result = $guidance->guide($message, $state);
        $language = AiConversationInterpreter::resolveReplyLanguage(
            $state,
            $message,
            (string) $state->openingConcern,
        );
        $payload = $this->present(
            $result,
            $request,
            $language === 'filipino' || $language === 'taglish',
        );

        AiFrontDeskConversation::remember(
            $request->session(),
            $message,
            $this->assistantText($payload),
            ($payload['type'] ?? null) === AiGuidanceType::Clarification->value,
        );

        return response()->json($payload);
    }

    public function clear(Request $request): JsonResponse
    {
        AiFrontDeskConversation::clear($request->session());

        return response()->json([
            'cleared' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AiGuidanceResult $result, Request $request, bool $filipino): array
    {
        $navigation = app(HospitalNavigationResolver::class);

        return match ($result->type) {
            AiGuidanceType::Recommendation => $this->presentRecommendation($result, $request, $filipino),
            AiGuidanceType::Clarification => [
                'type' => AiGuidanceType::Clarification->value,
                'question' => $result->question,
            ],
            AiGuidanceType::Uncertain => $this->presentUncertain($result, $filipino),
            AiGuidanceType::Safety => $this->withNavigation([
                'type' => AiGuidanceType::Safety->value,
                'message' => $result->message,
                'safety_kind' => ($result->safetyKind ?? AiSafetyKind::Emergency)->value,
            ], $navigation->forSafety($result->safetyKind ?? AiSafetyKind::Emergency, $filipino)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function presentUncertain(AiGuidanceResult $result, bool $filipino): array
    {
        $payload = [
            'type' => AiGuidanceType::Uncertain->value,
            'message' => $result->message,
        ];

        if ($result->message === ClaudeGuidanceService::PROCESSING_FALLBACK_MESSAGE) {
            $payload['processing_failed'] = true;

            return $payload;
        }

        return $this->withNavigation(
            $payload,
            app(HospitalNavigationResolver::class)->forUncertain($filipino),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRecommendation(AiGuidanceResult $result, Request $request, bool $filipino): array
    {
        $clinic = null;

        if ($result->clinicId !== null) {
            $clinic = Clinic::query()->with('department')->find($result->clinicId);

            if (
                ! $clinic instanceof Clinic
                || $clinic->status !== ClinicStatus::Active
                || $clinic->department->status !== DepartmentStatus::Active
            ) {
                return $this->withNavigation([
                    'type' => AiGuidanceType::Uncertain->value,
                    'message' => ClaudeGuidanceService::INSUFFICIENT_HOSPITAL_INFORMATION_MESSAGE,
                ], app(HospitalNavigationResolver::class)->forUncertain($filipino));
            }
        }

        $suggested = app(AiSuggestedDoctorService::class)->forGuidance($result);
        $payload = [
            'type' => AiGuidanceType::Recommendation->value,
            'reason' => $result->reason,
            'data_source' => HospitalDataSource::current()->value,
            'specialization' => $suggested['specialization'] ?? $result->specialization,
            'doctors' => $suggested['doctors'],
            'doctors_total' => count($suggested['doctors']),
            'message' => $suggested['message'],
            'all_unavailable' => $suggested['all_unavailable'] ?? false,
            ...app(AiSuggestedDoctorService::class)->bookingContext($request),
        ];

        if ($clinic instanceof Clinic) {
            $payload['clinic'] = [
                'id' => $clinic->id,
                'name' => $this->visitorFacingPlace($clinic->name),
                'department' => $this->visitorFacingPlace($clinic->department->name),
            ];
            $payload['doctors_url'] = route('ai-front-desk.clinics.doctors', $clinic);
        }

        if ($result->confidence !== null) {
            $payload['confidence'] = $result->confidence;
        }

        unset($payload['doctor_id']);

        $navigation = app(HospitalNavigationResolver::class)->forRecommendation($result, $filipino);
        $hasBookableDoctor = collect($suggested['doctors'])->contains(
            fn (array $doctor): bool => ($doctor['bookable'] ?? false) === true,
        );

        if (! $hasBookableDoctor) {
            $navigation['booking_permitted'] = false;
        }

        return $this->withNavigation($payload, $navigation);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $navigation
     * @return array<string, mixed>
     */
    private function withNavigation(array $payload, array $navigation): array
    {
        $payload['navigation'] = $navigation;

        return $payload;
    }

    private function visitorFacingPlace(string $label): string
    {
        $normalized = mb_strtolower(trim($label));

        if ($normalized === '' || str_contains($normalized, 'pending hospital mapping')) {
            return '';
        }

        return $label;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assistantText(array $payload): string
    {
        $text = match ($payload['type'] ?? null) {
            AiGuidanceType::Recommendation->value => $payload['reason'] ?? null,
            AiGuidanceType::Clarification->value => $payload['question'] ?? null,
            default => $payload['message'] ?? null,
        };

        return is_string($text) ? $text : '';
    }
}
