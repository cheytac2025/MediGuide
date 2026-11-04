<?php

namespace Tests\Unit;

use App\Support\AiConversationInterpreter;
use App\Support\AiConversationState;
use App\Support\AiRecommendationReadiness;
use App\Services\Ai\ClaudeGuidanceService;
use Tests\TestCase;

class AiRecommendationReadinessTest extends TestCase
{
    public function test_duration_alone_does_not_make_back_pain_ready(): void
    {
        $facts = AiConversationInterpreter::interpret(
            $this->state('hello! ang saket ng likod ko', ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO),
            'ilang buwan na ren e',
        );

        $this->assertSame('back', $facts->concernKind);
        $this->assertTrue($facts->hasUsableDuration());
        $this->assertFalse($facts->hasUsableLocation());
        $this->assertSame('location', $facts->missingPriority);
        $this->assertFalse($this->readiness()->isReady($facts));
        $this->assertSame('location', $this->readiness()->blockingTopic($facts));
    }

    public function test_precise_back_location_is_ready_without_a_fixed_question_count(): void
    {
        $ready = AiConversationInterpreter::interpret(
            AiConversationState::empty(),
            'Masakit ang lower back ko for two months.',
        );

        $this->assertTrue($ready->hasUsableLocation());
        $this->assertTrue($this->readiness()->isReady($ready));
        $this->assertNull($this->readiness()->blockingTopic($ready));

        $vague = AiConversationInterpreter::interpret(AiConversationState::empty(), 'May ubo ako.');
        $this->assertFalse($this->readiness()->isReady($vague));
    }

    public function test_localized_and_nonlocalized_concerns_use_different_readiness_rules(): void
    {
        $readiness = $this->readiness();

        $this->assertFalse($readiness->isReady(AiConversationInterpreter::interpret(
            $this->state('Masakit tiyan ko.', 'Saan po ito nararamdaman?'),
            'Sa kanan.',
        )));

        $this->assertTrue($readiness->isReady(AiConversationInterpreter::interpret(
            AiConversationState::empty(),
            'Masakit ang tiyan ko sa kanan sa baba for three days.',
        )));

        $this->assertTrue($readiness->isReady(AiConversationInterpreter::interpret(
            AiConversationState::empty(),
            'Masakit ang tuhod ko.',
        )));

        $this->assertTrue($readiness->isReady(AiConversationInterpreter::interpret(
            AiConversationState::empty(),
            'May rash ako sa braso.',
        )));

        $this->assertTrue($readiness->isReady(AiConversationInterpreter::interpret(
            AiConversationState::empty(),
            'Masakit ulo ko sa bandang likod since yesterday.',
        )));

        $this->assertTrue($readiness->isReady(AiConversationInterpreter::interpret(
            AiConversationState::empty(),
            'Masakit tenga ko since kahapon.',
        )));

        $this->assertTrue($readiness->isReady(AiConversationInterpreter::interpret(
            AiConversationState::empty(),
            'Masakit mata ko since kahapon.',
        )));

        $this->assertTrue($readiness->isReady(AiConversationInterpreter::interpret(
            AiConversationState::empty(),
            'I have a mild cough since yesterday.',
        )));

        $this->assertTrue($readiness->isReady(AiConversationInterpreter::interpret(
            AiConversationState::empty(),
            'May lagnat ako since kahapon.',
        )));

        $this->assertTrue($readiness->isReady(AiConversationInterpreter::interpret(
            AiConversationState::empty(),
            'Nahihilo ako since this morning.',
        )));

        $this->assertFalse($readiness->isReady(AiConversationInterpreter::interpret(
            AiConversationState::empty(),
            'May ubo ako at masakit ang lalamunan.',
        )));

        $this->assertTrue($readiness->isReady(AiConversationInterpreter::interpret(
            AiConversationState::empty(),
            'Dalawang araw na sumasakit ang ulo ko at medyo nahihilo ako.',
        )));
    }

    public function test_unknown_or_denied_location_does_not_block_forever(): void
    {
        $prior = new AiConversationState(
            turns: [
                ['role' => 'user', 'content' => 'hello! ang sakit ng likod ko'],
                ['role' => 'assistant', 'content' => ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO],
                ['role' => 'user', 'content' => 'Ilang buwan na.'],
                ['role' => 'assistant', 'content' => ClaudeGuidanceService::BACK_LOCATION_FOLLOW_UP_FILIPINO],
            ],
            openingConcern: 'hello! ang sakit ng likod ko',
            clarificationCount: 2,
            askedQuestions: [
                ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO,
                ClaudeGuidanceService::BACK_LOCATION_FOLLOW_UP_FILIPINO,
            ],
            replyLanguage: 'filipino',
        );

        $unknown = AiConversationInterpreter::interpret($prior, 'Hindi ko alam.');
        $this->assertContains('location', $unknown->unknown);
        $this->assertTrue($unknown->hasUsableDuration());
        $this->assertTrue($this->readiness()->isReady($unknown));

        $denied = AiConversationInterpreter::interpret($prior, 'Hindi naman.');
        $this->assertContains('location', $denied->denied);
        $this->assertTrue($this->readiness()->isReady($denied));
    }

    private function readiness(): AiRecommendationReadiness
    {
        return new AiRecommendationReadiness;
    }

    /**
     * @return AiConversationState
     */
    private function state(string $opening, string $question): AiConversationState
    {
        return new AiConversationState(
            turns: [
                ['role' => 'user', 'content' => $opening],
                ['role' => 'assistant', 'content' => $question],
            ],
            openingConcern: $opening,
            clarificationCount: 1,
            askedQuestions: [$question],
            replyLanguage: 'filipino',
        );
    }
}
