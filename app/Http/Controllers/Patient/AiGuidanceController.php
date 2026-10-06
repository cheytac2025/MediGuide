<?php

namespace App\Http\Controllers\Patient;

use App\Enums\AiGuidanceType;
use App\Enums\ClinicStatus;
use App\Enums\DepartmentStatus;
use App\Enums\HospitalDataSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Patient\StoreAiGuidanceRequest;
use App\Models\Clinic;
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
            AiFrontDeskConversation::turns($request->session()),
        );
        $payload = $this->present($result);

        AiFrontDeskConversation::remember(
            $request->session(),
            $message,
            $this->assistantText($payload),
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
    private function present(AiGuidanceResult $result): array
    {
        return match ($result->type) {
            AiGuidanceType::Recommendation => $this->presentRecommendation($result),
            AiGuidanceType::Clarification => [
                'type' => AiGuidanceType::Clarification->value,
                'question' => $result->question,
            ],
            AiGuidanceType::Uncertain => [
                'type' => AiGuidanceType::Uncertain->value,
                'message' => $result->message,
            ],
            AiGuidanceType::Safety => [
                'type' => AiGuidanceType::Safety->value,
                'message' => $result->message,
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRecommendation(AiGuidanceResult $result): array
    {
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

        $payload = [
            'type' => AiGuidanceType::Recommendation->value,
            'clinic' => [
                'id' => $clinic->id,
                'name' => $clinic->name,
                'department' => $clinic->department->name,
            ],
            'reason' => $result->reason,
            'data_source' => HospitalDataSource::current()->value,
            'doctors_url' => route('ai-front-desk.clinics.doctors', $clinic),
        ];

        if ($result->confidence !== null) {
            $payload['confidence'] = $result->confidence;
        }

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
