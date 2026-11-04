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

        $result = $guidance->guide(
            $message,
            AiFrontDeskConversation::state($request->session()),
        );
        $payload = $this->present($result, $request);

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
    private function present(AiGuidanceResult $result, Request $request): array
    {
        return match ($result->type) {
            AiGuidanceType::Recommendation => $this->presentRecommendation($result, $request),
            AiGuidanceType::Clarification => [
                'type' => AiGuidanceType::Clarification->value,
                'question' => $result->question,
            ],
            AiGuidanceType::Uncertain => $this->presentUncertain($result),
            AiGuidanceType::Safety => [
                'type' => AiGuidanceType::Safety->value,
                'message' => $result->message,
                'safety_kind' => ($result->safetyKind ?? AiSafetyKind::Emergency)->value,
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function presentUncertain(AiGuidanceResult $result): array
    {
        $payload = [
            'type' => AiGuidanceType::Uncertain->value,
            'message' => $result->message,
        ];

        if ($result->message === ClaudeGuidanceService::PROCESSING_FALLBACK_MESSAGE) {
            $payload['processing_failed'] = true;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRecommendation(AiGuidanceResult $result, Request $request): array
    {
        $clinic = null;

        if ($result->clinicId !== null) {
            $clinic = Clinic::query()->with('department')->find($result->clinicId);

            if (
                ! $clinic instanceof Clinic
                || $clinic->status !== ClinicStatus::Active
                || $clinic->department->status !== DepartmentStatus::Active
            ) {
                return [
                    'type' => AiGuidanceType::Uncertain->value,
                    'message' => ClaudeGuidanceService::INSUFFICIENT_HOSPITAL_INFORMATION_MESSAGE,
                ];
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
                'name' => $clinic->name,
                'department' => $clinic->department->name,
            ];
            $payload['doctors_url'] = route('ai-front-desk.clinics.doctors', $clinic);
        }

        if ($result->confidence !== null) {
            $payload['confidence'] = $result->confidence;
        }

        unset($payload['doctor_id']);

        return $payload;
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
