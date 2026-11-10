<?php

namespace Tests\Evaluation\AiSafetyEvidence;

use App\Support\AiConversationState;
use App\Support\AiSafetyEvidenceAssessment;
use App\Support\AiSafetyEvidenceValidator;

/**
 * Scores mocked safety payloads with the application validator.
 *
 * The harness does not call Anthropic, write records, or select a
 * visitor-facing response.
 */
final class SafetyEvidenceEvaluationHarness
{
    public function __construct(
        private AiSafetyEvidenceValidator $validator,
    ) {}

    /**
     * @param  array<string, mixed>  $scenario
     */
    public function score(array $scenario): AiSafetyEvidenceAssessment
    {
        return $this->validator->evaluate(
            $scenario['payload'],
            $this->state($scenario),
            $scenario['latest'],
        );
    }

    /**
     * @param  list<AiSafetyEvidenceAssessment>  $assessments
     * @return array<string, int>
     */
    public function countsByStatus(array $assessments): array
    {
        $counts = [];

        foreach ($assessments as $assessment) {
            $key = $assessment->status->value;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    public function state(array $scenario): AiConversationState
    {
        return new AiConversationState(
            turns: $scenario['turns'],
            openingConcern: $scenario['opening'],
            clarificationCount: count($scenario['turns']) === 0 ? 0 : 1,
            askedQuestions: array_values(array_map(
                static fn (array $turn): string => $turn['content'],
                array_filter(
                    $scenario['turns'],
                    static fn (array $turn): bool => $turn['role'] === 'assistant',
                ),
            )),
            replyLanguage: $scenario['reply_language'],
        );
    }
}
