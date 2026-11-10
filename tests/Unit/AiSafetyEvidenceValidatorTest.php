<?php

namespace Tests\Unit;

use App\Enums\AiSafetyEvidenceStatus;
use App\Support\AiConversationState;
use App\Support\AiSafetyEvidenceAssessment;
use App\Support\AiSafetyEvidenceValidator;
use Tests\TestCase;

class AiSafetyEvidenceValidatorTest extends TestCase
{
    public function test_current_safety_payload_supplies_no_evidence(): void
    {
        $assessment = $this->evaluate([
            'type' => 'safety',
            'message' => 'Please seek prompt in-person medical evaluation. This is not a diagnosis.',
        ], 'nagsusuka ako');

        $this->assertSame(AiSafetyEvidenceStatus::NotSupplied, $assessment->status);
        $this->assertNull($assessment->observedExistingRule);
        $this->assertFalse($assessment->assignsClinicalUrgency());
    }

    public function test_valid_filipino_english_and_taglish_quotations_do_not_assign_urgency(): void
    {
        $filipino = $this->evidence('nagsusuka ako', [
            ['quote' => 'nagsusuka ako'],
        ], 'nagsusuka ako');
        $english = $this->evidence('I have been vomiting', [
            ['quote' => 'I have been vomiting'],
        ], 'I have been vomiting');
        $taglish = $this->evidence('My ulo hurts since kahapon', [
            ['quote' => 'My ulo hurts'],
        ], 'My ulo hurts since kahapon');

        foreach ([$filipino, $english, $taglish] as $assessment) {
            $this->assertSame(AiSafetyEvidenceStatus::QuotedWithoutRule, $assessment->status);
            $this->assertNull($assessment->observedExistingRule);
            $this->assertFalse($assessment->assignsClinicalUrgency());
        }
    }

    public function test_missing_fabricated_and_partial_quotations_are_unsupported(): void
    {
        $missing = $this->evidence('nagsusuka ako', [], 'nagsusuka ako');
        $fabricated = $this->evidence('nagsusuka ako', [
            ['quote' => 'vomiting blood'],
        ], 'nagsusuka ako');
        $partial = $this->evidence('nagsusuka ako', [
            ['quote' => 'suka'],
        ], 'nagsusuka ako');

        $this->assertSame(AiSafetyEvidenceStatus::Unsupported, $missing->status);
        $this->assertSame(AiSafetyEvidenceStatus::Unsupported, $fabricated->status);
        $this->assertSame(AiSafetyEvidenceStatus::Unsupported, $partial->status);
        $this->assertFalse($fabricated->assignsClinicalUrgency());
    }

    public function test_negated_unknown_and_unanswered_question_are_not_positive_reports(): void
    {
        $inner = $this->evidence('hindi ako nagsusuka', [
            ['quote' => 'nagsusuka'],
        ], 'hindi ako nagsusuka');
        $whole = $this->evidence('hindi ako nagsusuka ng dugo', [
            ['quote' => 'hindi ako nagsusuka ng dugo'],
        ], 'hindi ako nagsusuka ng dugo');
        $unknown = $this->evidence('Hindi ko alam', [
            ['quote' => 'Hindi ko alam'],
        ], 'Hindi ko alam');
        $unknownSymptom = $this->evidence('Hindi ko alam', [
            ['quote' => 'nagsusuka ako'],
        ], 'Hindi ko alam');

        $state = new AiConversationState(
            turns: [
                ['role' => 'assistant', 'content' => 'Are you vomiting blood?'],
                ['role' => 'user', 'content' => 'masakit ulo ko'],
            ],
            openingConcern: 'masakit ulo ko',
            clarificationCount: 1,
            askedQuestions: ['Are you vomiting blood?'],
        );
        $unanswered = $this->validator()->evaluate(
            $this->payload([['quote' => 'vomiting blood']]),
            $state,
            'masakit ulo ko',
        );

        $this->assertSame(AiSafetyEvidenceStatus::Negated, $inner->status);
        $this->assertSame(AiSafetyEvidenceStatus::Negated, $whole->status);
        $this->assertSame(AiSafetyEvidenceStatus::UnknownAnswer, $unknown->status);
        $this->assertSame(AiSafetyEvidenceStatus::Unsupported, $unknownSymptom->status);
        $this->assertSame(AiSafetyEvidenceStatus::Unsupported, $unanswered->status);
        $this->assertNull($unknown->observedExistingRule);
    }

    public function test_an_affirmative_reply_does_not_import_the_assistant_question(): void
    {
        $state = new AiConversationState(
            turns: [
                ['role' => 'user', 'content' => 'masakit ulo ko'],
                ['role' => 'assistant', 'content' => 'Are you vomiting blood?'],
            ],
            openingConcern: 'masakit ulo ko',
            clarificationCount: 1,
            askedQuestions: ['Are you vomiting blood?'],
        );

        $question = $this->validator()->evaluate(
            $this->payload([['quote' => 'vomiting blood']]),
            $state,
            'Oo',
        );
        $yes = $this->validator()->evaluate(
            $this->payload([['quote' => 'Oo']]),
            $state,
            'Oo',
        );

        $this->assertSame(AiSafetyEvidenceStatus::Unsupported, $question->status);
        $this->assertSame(AiSafetyEvidenceStatus::AffirmationOnly, $yes->status);
        $this->assertFalse($yes->assignsClinicalUrgency());
    }

    public function test_informal_spelling_and_multiple_turns_match_normalized_visitor_words(): void
    {
        $informal = $this->evidence('sumakit na lang siya bilga', [
            ['quote' => 'sumakit na lang siya bigla'],
        ], 'sumakit na lang siya bilga');

        $state = new AiConversationState(
            turns: [
                ['role' => 'user', 'content' => 'nagsusuka ako'],
                ['role' => 'assistant', 'content' => 'Gaano na po katagal ito?'],
                ['role' => 'user', 'content' => 'kahapon'],
            ],
            openingConcern: 'nagsusuka ako',
            clarificationCount: 1,
            askedQuestions: ['Gaano na po katagal ito?'],
        );
        $turns = $this->validator()->evaluate(
            $this->payload([
                ['quote' => 'nagsusuka ako'],
                ['quote' => 'kahapon'],
            ]),
            $state,
            'kahapon',
        );
        $oneMissing = $this->validator()->evaluate(
            $this->payload([
                ['quote' => 'nagsusuka ako'],
                ['quote' => 'vomiting blood'],
            ]),
            $state,
            'kahapon',
        );

        $this->assertSame(AiSafetyEvidenceStatus::QuotedWithoutRule, $informal->status);
        $this->assertSame(AiSafetyEvidenceStatus::QuotedWithoutRule, $turns->status);
        $this->assertSame(AiSafetyEvidenceStatus::Unsupported, $oneMissing->status);
        $this->assertFalse($turns->assignsClinicalUrgency());
    }

    public function test_contract_limits_and_invalid_fields_are_rejected(): void
    {
        $long = $this->evidence('nagsusuka ako', [
            ['quote' => str_repeat('a', 201)],
        ], 'nagsusuka ako');
        $extra = $this->validator()->evaluate([
            'type' => 'safety',
            'message' => 'Please seek care. This is not a diagnosis.',
            'basis' => 'visitor_reported',
            'evidence' => [['quote' => 'nagsusuka ako']],
            'proposed_level' => 'prompt_evaluation',
            'symptom' => 'vomiting',
        ], AiConversationState::empty(), 'nagsusuka ako');
        $level = $this->validator()->evaluate(
            $this->payload([['quote' => 'nagsusuka ako']], 'routine'),
            AiConversationState::empty(),
            'nagsusuka ako',
        );
        $tooMany = $this->evidence('nagsusuka ako', [
            ['quote' => 'nagsusuka'],
            ['quote' => 'ako'],
            ['quote' => 'nagsusuka ako'],
            ['quote' => 'ako'],
        ], 'nagsusuka ako');

        $this->assertSame(AiSafetyEvidenceStatus::InvalidContract, $long->status);
        $this->assertSame(AiSafetyEvidenceStatus::InvalidContract, $extra->status);
        $this->assertSame(AiSafetyEvidenceStatus::InvalidContract, $level->status);
        $this->assertSame(AiSafetyEvidenceStatus::InvalidContract, $tooMany->status);
    }

    public function test_model_inference_is_not_a_visitor_report_and_a_real_quote_is_not_urgency(): void
    {
        $inferred = $this->validator()->evaluate([
            'type' => 'safety',
            'message' => 'This may need prompt evaluation. This is not a diagnosis.',
            'basis' => 'model_inferred',
            'evidence' => [['quote' => 'nagsusuka ako']],
            'proposed_level' => 'emergency',
        ], AiConversationState::empty(), 'nagsusuka ako');

        $noRule = $this->evidence('nagsusuka ako', [
            ['quote' => 'nagsusuka ako'],
        ], 'nagsusuka ako', 'emergency');
        $withRule = $this->evidence(
            'I have a sudden severe headache and I cannot speak.',
            [['quote' => 'I have a sudden severe headache and I cannot speak.']],
            'I have a sudden severe headache and I cannot speak.',
            'prompt_evaluation',
        );

        $this->assertSame(AiSafetyEvidenceStatus::ModelInferred, $inferred->status);
        $this->assertNull($inferred->observedExistingRule);
        $this->assertSame(AiSafetyEvidenceStatus::QuotedWithoutRule, $noRule->status);
        $this->assertNull($noRule->observedExistingRule);
        $this->assertSame(AiSafetyEvidenceStatus::QuotedWithExistingRule, $withRule->status);
        $this->assertSame('emergency_warning_sign', $withRule->observedExistingRule);
        $this->assertFalse($inferred->assignsClinicalUrgency());
        $this->assertFalse($noRule->assignsClinicalUrgency());
        $this->assertFalse($withRule->assignsClinicalUrgency());
    }

    private function validator(): AiSafetyEvidenceValidator
    {
        return app(AiSafetyEvidenceValidator::class);
    }

    private function evaluate(array $payload, string $latest): AiSafetyEvidenceAssessment
    {
        return $this->validator()->evaluate($payload, AiConversationState::empty(), $latest);
    }

    /**
     * @param  list<array{quote: string}>  $evidence
     */
    private function evidence(string $latest, array $evidence, string $visitor, string $level = 'prompt_evaluation'): AiSafetyEvidenceAssessment
    {
        return $this->validator()->evaluate(
            $this->payload($evidence, $level),
            AiConversationState::empty(),
            $visitor === $latest ? $latest : $visitor,
        );
    }

    /**
     * @param  list<array{quote: string}>  $evidence
     * @return array<string, mixed>
     */
    private function payload(array $evidence, string $level = 'prompt_evaluation'): array
    {
        return [
            'type' => 'safety',
            'message' => 'Please seek care. This is not a diagnosis.',
            'basis' => 'visitor_reported',
            'evidence' => $evidence,
            'proposed_level' => $level,
        ];
    }
}
