<?php

namespace Tests\Unit;

use App\Enums\AiSafetyKind;
use App\Support\AiConversationState;
use App\Support\AiNavigationSafety;
use App\Support\AiUrgentConcernDetector;
use Tests\TestCase;

class AiNavigationSafetyTest extends TestCase
{
    public function test_right_lower_abdominal_pain_is_prompt_evaluation_even_without_fever(): void
    {
        $safety = $this->classifier();
        $state = AiConversationState::empty();

        $decision = $safety->classify(
            "masaket tiyan ko\ndi naman siya laging sumasakit. sumasakit na siya ng mga 3 araw na\nsa kanan sa baba\nwala naman",
            $state,
            'wala naman',
        );

        $this->assertTrue($decision->isPromptEvaluation());
        $this->assertSame('right_lower_abdominal_pain', $decision->rule);
        $this->assertFalse($decision->isEmergency());
    }

    public function test_ordinary_abdominal_pain_is_not_automatically_escalated(): void
    {
        $decision = $this->classifier()->classify(
            'Masakit ang tiyan ko since kahapon.',
            AiConversationState::empty(),
            'Masakit ang tiyan ko since kahapon.',
        );

        $this->assertFalse($decision->blocksRoutineNavigation());
    }

    public function test_assistant_question_is_not_treated_as_a_patient_report(): void
    {
        $state = new AiConversationState(
            turns: [
                ['role' => 'user', 'content' => 'Masakit ang tiyan ko.'],
                ['role' => 'assistant', 'content' => 'Sa kanan sa baba ba ng tiyan ninyo?'],
            ],
            openingConcern: 'Masakit ang tiyan ko.',
            clarificationCount: 1,
            askedQuestions: ['Sa kanan sa baba ba ng tiyan ninyo?'],
        );

        $decision = $this->classifier()->classify(
            "Masakit ang tiyan ko.\nHindi",
            $state,
            'Hindi',
        );

        $this->assertFalse($decision->blocksRoutineNavigation());
    }

    public function test_short_yes_affirms_the_latest_location_question(): void
    {
        $state = new AiConversationState(
            turns: [
                ['role' => 'user', 'content' => 'Masakit ang tiyan ko.'],
                ['role' => 'assistant', 'content' => 'Sa kanan sa baba ba ng tiyan ninyo?'],
            ],
            openingConcern: 'Masakit ang tiyan ko.',
            clarificationCount: 1,
            askedQuestions: ['Sa kanan sa baba ba ng tiyan ninyo?'],
        );

        $decision = $this->classifier()->classify(
            "Masakit ang tiyan ko.\nOo",
            $state,
            'Oo',
        );

        $this->assertTrue($decision->isPromptEvaluation());
    }

    public function test_extended_isolated_chest_pain_stays_disabled_by_default(): void
    {
        $decision = $this->classifier()->classify(
            'Sumasakit dibdib ko.',
            AiConversationState::empty(),
            'Sumasakit dibdib ko.',
        );

        $this->assertFalse($decision->blocksRoutineNavigation());

        config(['ai.prompt_evaluation.extended' => true]);

        $extended = $this->classifier()->classify(
            'Sumasakit dibdib ko.',
            AiConversationState::empty(),
            'Sumasakit dibdib ko.',
        );

        $this->assertTrue($extended->isPromptEvaluation());
        $this->assertSame('isolated_chest_pain_extended', $extended->rule);
        $this->assertSame(AiSafetyKind::PromptEvaluation, $extended->kind);
    }

    public function test_prompt_evaluation_can_be_disabled(): void
    {
        config(['ai.prompt_evaluation.enabled' => false]);

        $decision = $this->classifier()->classify(
            'Masakit ang tiyan ko sa kanan sa baba for 3 days.',
            AiConversationState::empty(),
            'Masakit ang tiyan ko sa kanan sa baba for 3 days.',
        );

        $this->assertFalse($decision->blocksRoutineNavigation());
    }

    private function classifier(): AiNavigationSafety
    {
        return new AiNavigationSafety(new AiUrgentConcernDetector);
    }
}
