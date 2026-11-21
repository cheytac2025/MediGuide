<?php

namespace Tests\Unit;

use App\Support\AiConversationInterpreter;
use App\Support\AiConversationState;
use App\Support\AiRecommendationReadiness;
use App\Support\AiUrgentConcernDetector;
use App\Support\AiVisitorReply;
use Tests\TestCase;

class AiSymptomUnderstandingImprovementsTest extends TestCase
{
    public function test_informal_spelling_normalizes_common_taglish_forms(): void
    {
        $this->assertStringContainsString('masakit', AiVisitorReply::normalize('Masaket ulo ko'));
        $this->assertStringContainsString('nagsusuka', AiVisitorReply::normalize('Nagsuka ako'));
        $this->assertStringContainsString('hirap huminga', AiVisitorReply::normalize('hirap hinga'));
        $this->assertStringContainsString('hindi ako', AiVisitorReply::normalize('di ako ok'));
    }

    public function test_concern_kind_covers_chest_vomiting_dental_and_urinary(): void
    {
        $this->assertSame('chest', AiConversationInterpreter::concernKind('Masakit ang dibdib ko'));
        $this->assertSame('vomiting', AiConversationInterpreter::concernKind('Nagsusuka ako'));
        $this->assertSame('dental', AiConversationInterpreter::concernKind('Masakit ang ngipin ko'));
        $this->assertSame('urinary', AiConversationInterpreter::concernKind('Masakit umihi'));
        $this->assertSame('cough', AiConversationInterpreter::concernKind('May sipon ako'));
        $this->assertSame('headache', AiConversationInterpreter::concernKind('My ulo hurts'));
    }

    public function test_ambiguous_other_and_thin_chest_are_not_ready(): void
    {
        $readiness = app(AiRecommendationReadiness::class);

        $this->assertFalse($readiness->isReady(
            AiConversationInterpreter::interpret(AiConversationState::empty(), 'I feel weird.')
        ));
        $this->assertFalse($readiness->isReady(
            AiConversationInterpreter::interpret(AiConversationState::empty(), 'Masakit ang dibdib ko.')
        ));
        $this->assertFalse($readiness->isReady(
            AiConversationInterpreter::interpret(AiConversationState::empty(), 'Nagsusuka ako.')
        ));
        $this->assertTrue($readiness->isReady(
            AiConversationInterpreter::interpret(AiConversationState::empty(), 'Mild chest discomfort since yesterday.')
        ));
    }

    public function test_additional_emergency_phrases_are_detected(): void
    {
        $detector = app(AiUrgentConcernDetector::class);

        $this->assertTrue($detector->isUrgent('Crushing chest pain with cold sweat'));
        $this->assertTrue($detector->isUrgent('Chest pain radiating to my left arm'));
        $this->assertTrue($detector->isUrgent('Biglang hirap huminga'));
        $this->assertFalse($detector->isUrgent('Masaket ulo ko'));
        $this->assertFalse($detector->isUrgent('Hindi ako nagsusuka ng dugo'));
    }
}
