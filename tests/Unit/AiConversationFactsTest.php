<?php

namespace Tests\Unit;

use App\Services\Ai\ClaudeGuidanceService;
use App\Support\AiConversationInterpreter;
use App\Support\AiConversationState;
use Tests\TestCase;

class AiConversationFactsTest extends TestCase
{
    public function test_short_answers_bind_to_the_latest_question_without_inventing_symptoms(): void
    {
        $duration = AiConversationInterpreter::interpret(
            $this->state('Masakit ulo ko.', 'Kailan po nagsimula ang sakit ng ulo?'),
            'Tatlong araw na.',
        );

        $this->assertSame('headache', $duration->concernKind);
        $this->assertNotNull($duration->duration);
        $this->assertContains('duration', $duration->answeredTopics);
        $this->assertSame('duration', $duration->lastQuestionTopic);

        $location = AiConversationInterpreter::interpret(
            $this->state('Masakit ulo ko.', 'Saang parte po ng ulo masakit — harap, gilid, o likod?'),
            'Sa bandang likod.',
        );

        $this->assertSame('back of the head', $location->bodyLocation);
        $this->assertSame('head', $location->locationAnchor);
        $this->assertSame('headache', $location->concernKind);

        $denied = AiConversationInterpreter::interpret(
            $this->state('May ubo ako.', 'May iba pa po ba kayong nararamdaman kasabay nito?'),
            'Hindi naman.',
        );

        $this->assertContains('associated', $denied->denied);
        $this->assertSame([], $denied->associated);

        $unknown = AiConversationInterpreter::interpret(
            $this->state('May ubo ako.', 'Gaano na po katagal ang ubo?'),
            'Hindi ko alam.',
        );

        $this->assertContains('duration', $unknown->unknown);
        $this->assertNull($unknown->duration);

        $yes = AiConversationInterpreter::interpret(
            $this->state('Masakit ang tuhod ko.', 'May tama po ba o naaksidente sa parte na iyon?'),
            'Oo.',
        );

        $this->assertContains('injury', $yes->answeredTopics);
        $this->assertSame([], $yes->associated);

        $frequency = AiConversationInterpreter::interpret(
            $this->state('Masakit ulo ko.', 'Gaano kadalas ito?'),
            'Minsan lang.',
        );

        $this->assertNotNull($frequency->frequency);
        $this->assertContains('frequency', $frequency->answeredTopics);

        $intermittent = AiConversationInterpreter::interpret(
            $this->state('Masakit ulo ko.', 'Biglaan po ba itong nagsimula, o unti-unti?'),
            'Bigla-bigla.',
        );

        $this->assertSame('intermittent', $intermittent->onset);
        $this->assertNotSame('sudden', $intermittent->onset);
    }

    public function test_pain_severity_is_reported_denied_unknown_or_missing_without_assuming_safety(): void
    {
        $missing = AiConversationInterpreter::interpret(
            AiConversationState::empty(),
            'Masakit ang tiyan ko sa kaliwa sa baba for three days. Sumakit na lang siya bilga.',
        );

        $this->assertSame('abdominal', $missing->concernKind);
        $this->assertSame('sudden', $missing->onset);
        $this->assertNull($missing->severity);
        $this->assertSame('missing', $missing->severityStatus());
        $this->assertSame('severity', $missing->missingPriority);
        $this->assertFalse($missing->hasUsableSeverity());
        $this->assertStringContainsString('Sudden onset is not sudden maximum-intensity pain', $missing->toArray()['note']);

        $mild = AiConversationInterpreter::interpret(AiConversationState::empty(), 'Medyo masakit ang tiyan ko.');
        $this->assertSame('mild', $mild->severity);
        $this->assertSame('reported', $mild->severityStatus());

        $severe = AiConversationInterpreter::interpret(AiConversationState::empty(), 'Sobrang sakit ng tuhod ko.');
        $this->assertSame('severe', $severe->severity);

        $variable = AiConversationInterpreter::interpret(AiConversationState::empty(), 'Minsan matindi ang sakit ng ulo ko.');
        $this->assertSame('variable', $variable->severity);

        $englishMild = AiConversationInterpreter::interpret(AiConversationState::empty(), 'I have mild pain in my lower back.');
        $this->assertSame('mild', $englishMild->severity);
        $this->assertSame('back', $englishMild->concernKind);

        $rated = AiConversationInterpreter::interpret(AiConversationState::empty(), 'Pain rated 7 out of 10 in my abdomen.');
        $this->assertSame('7/10', $rated->severity);
        $this->assertSame('abdominal', $rated->concernKind);

        $slash = AiConversationInterpreter::interpret(AiConversationState::empty(), 'My knee pain is 8/10.');
        $this->assertSame('8/10', $slash->severity);

        $unknown = AiConversationInterpreter::interpret(
            $this->state('Masakit ang tiyan ko.', ClaudeGuidanceService::PAIN_SEVERITY_FOLLOW_UP_FILIPINO),
            'Hindi ko alam.',
        );
        $this->assertSame('unknown', $unknown->severityStatus());
        $this->assertNull($unknown->severity);
        $this->assertContains('severity', $unknown->unknown);

        $denied = AiConversationInterpreter::interpret(
            $this->state('Masakit ang tiyan ko.', ClaudeGuidanceService::PAIN_SEVERITY_FOLLOW_UP_FILIPINO),
            'Hindi naman.',
        );
        $this->assertSame('denied', $denied->severityStatus());

        $gradual = AiConversationInterpreter::interpret(AiConversationState::empty(), 'Masakit ang tiyan ko, unti-unti lang.');
        $this->assertSame('gradual', $gradual->onset);
        $this->assertNull($gradual->severity);
    }

    public function test_abdominal_side_without_level_is_not_treated_as_a_new_complaint(): void
    {
        $facts = AiConversationInterpreter::interpret(
            $this->state('Masakit tiyan ko.', 'Saan po ito nararamdaman?'),
            'Sa kanan.',
        );

        $this->assertSame('abdominal', $facts->concernKind);
        $this->assertSame('right abdomen', $facts->bodyLocation);
        $this->assertSame('quadrant', $facts->missingPriority);
    }

    public function test_language_detection_distinguishes_english_filipino_and_taglish(): void
    {
        $this->assertSame('english', AiConversationInterpreter::languageOf('I have a cough.'));
        $this->assertSame('filipino', AiConversationInterpreter::languageOf('Masaket ulo ko.'));
        $this->assertSame('taglish', AiConversationInterpreter::languageOf('Nahihilo ako since this morning.'));
        $this->assertTrue(AiConversationInterpreter::prefersFilipino('Hindi ko alam.'));
        $this->assertFalse(AiConversationInterpreter::prefersFilipino("I don't know"));
        $this->assertSame('filipino', AiConversationInterpreter::languageOf('tagal na mga ilang buwan na ren'));
    }

    public function test_approximate_duration_is_remembered_and_not_treated_as_unknown(): void
    {
        $cases = [
            'Ilang buwan na.',
            'tagal na mga ilang buwan na ren',
            'Matagal na.',
            'Since last month.',
            'Two months already.',
            'Ilang linggo na.',
            'Three days ago.',
        ];

        foreach ($cases as $answer) {
            $facts = AiConversationInterpreter::interpret(
                $this->state('hello! ang sakit ng likod ko', 'Gaano na po katagal ito?'),
                $answer,
            );

            $this->assertSame('back', $facts->concernKind, $answer);
            $this->assertNotNull($facts->duration, $answer);
            $this->assertContains('duration', $facts->answeredTopics, $answer);
            $this->assertNotContains('duration', $facts->unknown, $answer);
            $this->assertTrue($facts->hasUsableDuration(), $answer);
            $this->assertNotSame('duration', $facts->missingPriority, $answer);
        }
    }

    public function test_session_language_survives_short_answers_and_honors_explicit_requests(): void
    {
        $short = AiConversationInterpreter::interpret(
            new AiConversationState(
                turns: [
                    ['role' => 'user', 'content' => 'hello! ang sakit ng likod ko'],
                    ['role' => 'assistant', 'content' => 'Gaano na po katagal ito?'],
                ],
                openingConcern: 'hello! ang sakit ng likod ko',
                clarificationCount: 1,
                askedQuestions: ['Gaano na po katagal ito?'],
                replyLanguage: 'filipino',
            ),
            'Two months already.',
        );

        $this->assertSame('filipino', $short->replyLanguage);
        $this->assertNotNull($short->duration);

        $switch = AiConversationInterpreter::interpret(
            new AiConversationState(
                turns: [
                    ['role' => 'user', 'content' => 'hello! ang sakit ng likod ko'],
                    ['role' => 'assistant', 'content' => 'May iba pa po ba kayong nararamdaman kasabay nito?'],
                ],
                openingConcern: 'hello! ang sakit ng likod ko',
                clarificationCount: 1,
                askedQuestions: ['May iba pa po ba kayong nararamdaman kasabay nito?'],
                replyLanguage: 'english',
            ),
            'wala naman. magtagalog ka',
        );

        $this->assertSame('filipino', $switch->replyLanguage);
        $this->assertContains('associated', $switch->denied);
        $this->assertSame([], $switch->associated);

        $english = AiConversationInterpreter::interpret(
            new AiConversationState(
                turns: [
                    ['role' => 'user', 'content' => 'My back hurts.'],
                    ['role' => 'assistant', 'content' => 'How long has this been happening?'],
                ],
                openingConcern: 'My back hurts.',
                clarificationCount: 1,
                askedQuestions: ['How long has this been happening?'],
                replyLanguage: 'filipino',
            ),
            'Please speak English',
        );

        $this->assertSame('english', $english->replyLanguage);
    }

    public function test_qualitative_back_discomfort_is_not_invented_as_a_diagnosis(): void
    {
        $facts = AiConversationInterpreter::interpret(
            $this->state('hello! ang sakit ng likod ko', ClaudeGuidanceService::PAIN_SEVERITY_FOLLOW_UP_FILIPINO),
            'hmm di naman siya ganon kasaket. parang mabigat lang siya or parang nangangalay',
        );

        $this->assertContains('severity', $facts->answeredTopics);
        $this->assertNotNull($facts->severity);
        $this->assertSame([], $facts->associated);
        $this->assertStringNotContainsString('strain', mb_strtolower((string) $facts->severity));
        $this->assertStringNotContainsString('diagnosis', mb_strtolower((string) $facts->severity));
    }

    public function test_named_joint_is_not_reclassified_as_an_unspecified_body_pain(): void
    {
        $facts = AiConversationInterpreter::interpret(AiConversationState::empty(), 'Masakit ang tuhod ko.');

        $this->assertSame('joint', $facts->concernKind);
        $this->assertSame('knee', $facts->bodyLocation);
        $this->assertContains('location', $facts->answeredTopics);
        $this->assertSame('duration', $facts->missingPriority);
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
        );
    }
}
