<?php

namespace Tests\Unit;

use App\Services\Ai\AppointmentAiSummaryBuilder;
use App\Support\AiConversationState;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AppointmentAiSummaryBuilderTest extends TestCase
{
    #[Test]
    public function it_builds_a_summary_from_reported_conversation_facts_without_inventing_symptoms(): void
    {
        $state = AiConversationState::fromTurns([
            ['role' => 'user', 'content' => 'Masakit ang ulo ko.'],
            ['role' => 'assistant', 'content' => 'Kailan po nagsimula ang sakit ng ulo?'],
            ['role' => 'user', 'content' => 'Tatlong araw na.'],
            ['role' => 'assistant', 'content' => 'Gaano po kalakas ang sakit?'],
            ['role' => 'user', 'content' => 'Hindi ko alam.'],
            ['role' => 'assistant', 'content' => 'May lagnat po ba?'],
            ['role' => 'user', 'content' => 'Wala.'],
        ]);

        $payload = app(AppointmentAiSummaryBuilder::class)->fromConversation(
            $state,
            'Pediatrician',
            null,
        );

        $this->assertNotNull($payload);
        $this->assertStringContainsString('Masakit ang ulo ko', $payload['concern_summary']);
        $this->assertStringContainsString('Duration:', $payload['concern_summary']);
        $this->assertSame('Pediatrician', $payload['suggested_specialization']);
        $this->assertNotEmpty($payload['reported_details']);
        $this->assertNotEmpty($payload['original_inputs']);
        $this->assertTrue(
            str_contains($payload['concern_summary'], 'Severity: unknown')
            || collect($payload['reported_details'])->contains(
                fn (array $row): bool => ($row['label'] ?? null) === 'Severity'
                    && str_contains((string) ($row['value'] ?? ''), 'Unknown'),
            ),
        );
        $this->assertStringNotContainsString('pneumonia', mb_strtolower($payload['concern_summary']));
        $this->assertStringNotContainsString('diagnosis', mb_strtolower($payload['concern_summary']));
    }

    #[Test]
    public function it_captures_knee_pain_duration_onset_severity_and_functional_impact(): void
    {
        $state = AiConversationState::fromTurns([
            ['role' => 'user', 'content' => 'Sumasakit tuhod ko.'],
            ['role' => 'assistant', 'content' => 'Kailan po nagsimula?'],
            ['role' => 'user', 'content' => 'Isang linggo na sumasakit.'],
            ['role' => 'assistant', 'content' => 'Paano po nagsimula?'],
            ['role' => 'user', 'content' => 'Natulog lang ako tas sumaket na siya pag gising ko.'],
            ['role' => 'assistant', 'content' => 'Gaano po kalakas?'],
            ['role' => 'user', 'content' => 'Mild lang. Di naman nakakaapekto.'],
        ]);

        $payload = app(AppointmentAiSummaryBuilder::class)->fromConversation(
            $state,
            'Orthopedics',
            null,
        );

        $this->assertNotNull($payload);
        $this->assertStringContainsString('Sumasakit tuhod ko', $payload['concern_summary']);
        $this->assertStringContainsString('Isang linggo', $payload['concern_summary']);
        $this->assertStringContainsString('noticed upon waking', $payload['concern_summary']);
        $this->assertStringContainsString('Severity: mild', $payload['concern_summary']);
        $this->assertStringContainsString('no meaningful effect on movement', $payload['concern_summary']);
        $this->assertMatchesRegularExpression('/Location:.*knee/i', $payload['concern_summary']);
        $this->assertStringNotContainsString('injury', mb_strtolower($payload['concern_summary']));
        $this->assertStringNotContainsString('swelling', mb_strtolower($payload['concern_summary']));
        $this->assertStringNotContainsString('diagnosis', mb_strtolower($payload['concern_summary']));
        $this->assertSame('Orthopedics', $payload['suggested_specialization']);
    }

    #[Test]
    public function it_distinguishes_unknown_from_denied_and_keeps_short_contextual_replies(): void
    {
        $unknown = app(AppointmentAiSummaryBuilder::class)->fromConversation(
            AiConversationState::fromTurns([
                ['role' => 'user', 'content' => 'Masakit ulo ko.'],
                ['role' => 'assistant', 'content' => 'Gaano po kalakas ang sakit?'],
                ['role' => 'user', 'content' => 'Hindi ko alam.'],
            ]),
            'Internal Medicine',
            null,
        );

        $this->assertNotNull($unknown);
        $this->assertStringContainsString('Severity: unknown', $unknown['concern_summary']);
        $this->assertStringNotContainsString('Denied:', $unknown['concern_summary']);

        $denied = app(AppointmentAiSummaryBuilder::class)->fromConversation(
            AiConversationState::fromTurns([
                ['role' => 'user', 'content' => 'Masakit ulo ko.'],
                ['role' => 'assistant', 'content' => 'May lagnat po ba o iba pang sintomas?'],
                ['role' => 'user', 'content' => 'Wala naman.'],
            ]),
            'Internal Medicine',
            null,
        );

        $this->assertNotNull($denied);
        $this->assertStringContainsString('Denied:', $denied['concern_summary']);
        $this->assertStringNotContainsString('Severity: unknown', $denied['concern_summary']);
        $this->assertDoesNotMatchRegularExpression('/Denied:.*(fever|lagnat|swelling|numbness)/i', $denied['concern_summary']);
    }

    #[Test]
    public function it_supports_english_taglish_and_unfamiliar_physical_descriptions(): void
    {
        $english = app(AppointmentAiSummaryBuilder::class)->fromConversation(
            AiConversationState::fromTurns([
                ['role' => 'user', 'content' => 'I have knee pain.'],
                ['role' => 'assistant', 'content' => 'How long?'],
                ['role' => 'user', 'content' => 'About one week.'],
                ['role' => 'assistant', 'content' => 'How severe?'],
                ['role' => 'user', 'content' => 'Mild.'],
            ]),
            'Orthopedics',
            null,
        );

        $this->assertNotNull($english);
        $this->assertStringContainsString('knee pain', mb_strtolower($english['concern_summary']));
        $this->assertStringContainsString('About one week', $english['concern_summary']);
        $this->assertStringContainsString('Severity: mild', $english['concern_summary']);

        $taglish = app(AppointmentAiSummaryBuilder::class)->fromConversation(
            AiConversationState::fromTurns([
                ['role' => 'user', 'content' => 'Sumasakit yung knee ko.'],
                ['role' => 'assistant', 'content' => 'Gaano na katagal?'],
                ['role' => 'user', 'content' => 'One week na.'],
                ['role' => 'assistant', 'content' => 'Gaano kalakas?'],
                ['role' => 'user', 'content' => 'Mild lang.'],
            ]),
            'Orthopedics',
            null,
        );

        $this->assertNotNull($taglish);
        $this->assertStringContainsString('knee', mb_strtolower($taglish['concern_summary']));
        $this->assertStringContainsString('One week na', $taglish['concern_summary']);

        $unfamiliar = app(AppointmentAiSummaryBuilder::class)->fromConversation(
            AiConversationState::fromTurns([
                ['role' => 'user', 'content' => 'Parang may kuryente sa braso ko.'],
                ['role' => 'assistant', 'content' => 'Saan po eksakto?'],
                ['role' => 'user', 'content' => 'Sa kanan.'],
            ]),
            'Neurology',
            null,
        );

        $this->assertNotNull($unfamiliar);
        $this->assertStringContainsString('kuryente sa braso', mb_strtolower($unfamiliar['concern_summary']));
        $this->assertStringNotContainsString('seizure', mb_strtolower($unfamiliar['concern_summary']));
        $this->assertStringNotContainsString('diagnosis', mb_strtolower($unfamiliar['concern_summary']));
    }

    #[Test]
    public function it_returns_null_for_empty_abandoned_conversations(): void
    {
        $payload = app(AppointmentAiSummaryBuilder::class)->fromConversation(
            AiConversationState::empty(),
            'Internal Medicine',
            null,
        );

        $this->assertNull($payload);
    }
}
