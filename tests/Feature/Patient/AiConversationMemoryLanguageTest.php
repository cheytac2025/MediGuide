<?php

namespace Tests\Feature\Patient;

use App\Enums\AiGuidanceType;
use App\Enums\ClinicStatus;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorStatus;
use App\Enums\HospitalDataSource;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Services\Ai\ClaudeGuidanceService;
use App\Support\AiConversationState;
use App\Support\AiDisclaimer;
use App\Support\AiFrontDeskConversation;
use App\Support\AiVisitorReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiConversationMemoryLanguageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.key' => 'test-anthropic-key',
            'services.anthropic.model' => 'claude-sonnet-5',
            'hospital.data_source' => 'development',
        ]);

        Http::preventStrayRequests();
    }

    public function test_filipino_duration_is_not_repeated_in_english(): void
    {
        $this->eligibleClinic();
        $this->doctor();
        $this->fakeClaude([
            'type' => 'clarification',
            'question' => ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_ENGLISH,
        ]);

        $result = $this->service()->guide('tagal na mga ilang buwan na ren', $this->backState());

        $this->assertSame(AiGuidanceType::Clarification, $result->type);
        $this->assertNotSame(ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_ENGLISH, $result->question);
        $this->assertNotSame(ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO, $result->question);
        $this->assertSame(ClaudeGuidanceService::BACK_LOCATION_FOLLOW_UP_FILIPINO, $result->question);
        $this->assertLessThanOrEqual(1, substr_count((string) $result->question, '?'));
    }

    public function test_informal_filipino_spelling_keeps_duration_and_language(): void
    {
        $this->eligibleClinic();
        $this->doctor();
        $this->fakeClaude([
            'type' => 'clarification',
            'question' => ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_ENGLISH,
        ]);

        $result = $this->service()->guide('matagal na mga ilang buwan na ren', $this->backState());

        $this->assertSame(ClaudeGuidanceService::BACK_LOCATION_FOLLOW_UP_FILIPINO, $result->question);
        $this->assertTrue(AiVisitorReply::questionsAreEquivalent(
            ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO,
            ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_ENGLISH,
        ));
    }

    public function test_language_preference_persists_for_short_and_taglish_duration_answers(): void
    {
        $this->eligibleClinic();
        $this->doctor();
        $this->fakeClaude([
            'type' => 'clarification',
            'question' => ClaudeGuidanceService::PAIN_SEVERITY_FOLLOW_UP_ENGLISH,
        ]);

        $filipino = $this->service()->guide('Two months already.', $this->backState());
        $this->assertSame(ClaudeGuidanceService::BACK_LOCATION_FOLLOW_UP_FILIPINO, $filipino->question);

        $this->fakeClaude([
            'type' => 'clarification',
            'question' => ClaudeGuidanceService::ASSOCIATED_FOLLOW_UP_ENGLISH,
        ]);
        $short = $this->service()->guide('Hmm.', $this->backState(
            ClaudeGuidanceService::BACK_LOCATION_FOLLOW_UP_FILIPINO,
            'Sa baba.',
        ));
        $this->assertSame(ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO, $short->question);
    }

    public function test_explicit_tagalog_request_overrides_english_session_language(): void
    {
        $this->eligibleClinic();
        $this->doctor();
        $this->fakeClaude([
            'type' => 'clarification',
            'question' => ClaudeGuidanceService::ASSOCIATED_FOLLOW_UP_ENGLISH,
        ]);

        $tagalog = $this->service()->guide('wala naman. magtagalog ka', $this->backState(
            ClaudeGuidanceService::ASSOCIATED_FOLLOW_UP_ENGLISH,
            'Medyo lang.',
            'english',
        ));
        $this->assertSame(AiGuidanceType::Clarification, $tagalog->type);
        $this->assertSame(ClaudeGuidanceService::BACK_LOCATION_FOLLOW_UP_FILIPINO, $tagalog->question);
    }

    public function test_explicit_english_request_overrides_filipino_session_language(): void
    {
        $this->eligibleClinic();
        $this->doctor();
        $this->fakeClaude([
            'type' => 'clarification',
            'question' => ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO,
        ]);

        $english = $this->service()->guide('Please speak English. It has been two months.', new AiConversationState(
            turns: [
                ['role' => 'user', 'content' => 'hello! ang sakit ng likod ko'],
                ['role' => 'assistant', 'content' => ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO],
            ],
            openingConcern: 'hello! ang sakit ng likod ko',
            clarificationCount: 1,
            askedQuestions: [ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO],
            replyLanguage: 'filipino',
        ));
        $this->assertSame(AiGuidanceType::Clarification, $english->type);
        $this->assertSame(ClaudeGuidanceService::BACK_LOCATION_FOLLOW_UP_ENGLISH, $english->question);
    }

    public function test_new_conversation_resets_language_and_english_filipino_taglish_each_keep_their_register(): void
    {
        $this->eligibleClinic();
        $this->doctor();

        $this->fakeClaude([
            'type' => 'clarification',
            'question' => ClaudeGuidanceService::GENERAL_FOLLOW_UP_FILIPINO,
        ]);
        $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->postJson(route('ai-front-desk.guidance'), ['message' => 'hello! ang sakit ng likod ko'])
            ->assertOk()
            ->assertJsonPath('question', ClaudeGuidanceService::BACK_LOCATION_FOLLOW_UP_FILIPINO);

        $this->assertSame('filipino', AiFrontDeskConversation::state($this->app['session.store'])->replyLanguage);

        $this->postJson(route('ai-front-desk.conversation.clear'))->assertOk();
        $this->assertNull(AiFrontDeskConversation::state($this->app['session.store'])->replyLanguage);

        $this->fakeClaude([
            'type' => 'clarification',
            'question' => ClaudeGuidanceService::GENERAL_FOLLOW_UP_ENGLISH,
        ]);
        $english = $this->service()->guide('I have a cough.');
        $this->assertSame(ClaudeGuidanceService::COUGH_DURATION_ENGLISH, $english->question);

        $this->fakeClaude([
            'type' => 'clarification',
            'question' => ClaudeGuidanceService::GENERAL_FOLLOW_UP_FILIPINO,
        ]);
        $filipino = $this->service()->guide('Masaket ulo ko');
        $this->assertSame(ClaudeGuidanceService::HEADACHE_FOLLOW_UP_FILIPINO, $filipino->question);

        $this->fakeClaude([
            'type' => 'clarification',
            'question' => ClaudeGuidanceService::GENERAL_FOLLOW_UP_FILIPINO,
        ]);
        $taglish = $this->service()->guide('Nahihilo ako since this morning.');
        $this->assertSame(ClaudeGuidanceService::ONSET_FOLLOW_UP_FILIPINO, $taglish->question);
    }

    public function test_back_pain_follow_up_can_ask_location_without_inventing_symptoms(): void
    {
        $this->eligibleClinic();
        $this->doctor();
        $this->fakeClaude([
            'type' => 'clarification',
            'question' => ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_ENGLISH,
        ]);

        $result = $this->service()->guide('Ilang buwan na.', $this->backState());

        $this->assertSame(ClaudeGuidanceService::BACK_LOCATION_FOLLOW_UP_FILIPINO, $result->question);
        $this->assertStringNotContainsString('strain', mb_strtolower((string) $result->question));
        $this->assertStringNotContainsString('scoliosis', mb_strtolower((string) $result->question));
        $this->assertStringNotContainsString('diagnosis', mb_strtolower((string) $result->question));
    }

    public function test_validated_recommendation_still_requires_a_real_service_id(): void
    {
        $clinic = $this->eligibleClinic();
        $this->doctor($clinic);
        $this->fakeClaude([
            'type' => 'recommendation',
            'service_id' => 1,
            'reason' => 'Limited navigation to Internal Medicine.',
            'confidence' => null,
        ]);

        $enough = $this->service()->guide('I have a mild cough since yesterday. No fever and I can breathe normally.');
        $this->assertSame(AiGuidanceType::Recommendation, $enough->type);
        $this->assertSame(1, $enough->serviceId);
    }

    public function test_invented_service_ids_are_still_rejected(): void
    {
        $this->eligibleClinic();
        $this->doctor();
        $this->fakeClaude([
            'type' => 'recommendation',
            'service_id' => 99,
            'reason' => 'Invented Orthopedic Surgeon.',
            'confidence' => null,
        ]);

        $invalid = $this->service()->guide('I have a mild cough since yesterday.');
        $this->assertSame(AiGuidanceType::Uncertain, $invalid->type);
        $this->assertNull($invalid->serviceId);
    }

    public function test_clarification_limit_does_not_force_a_surgical_recommendation_for_incomplete_back_pain(): void
    {
        $this->eligibleClinic();
        $this->doctor();
        $this->fakeClaude([
            'type' => 'recommendation',
            'service_id' => 1,
            'reason' => 'Orthopedic Surgeon may match this hospital navigation suggestion.',
            'confidence' => null,
        ]);

        $result = $this->service()->guide('Hmm.', new AiConversationState(
            turns: [
                ['role' => 'user', 'content' => 'hello! ang sakit ng likod ko'],
                ['role' => 'assistant', 'content' => ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO],
            ],
            openingConcern: 'hello! ang sakit ng likod ko',
            clarificationCount: AiConversationState::MAX_CLARIFICATION_ATTEMPTS,
            askedQuestions: [
                ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO,
                ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_ENGLISH,
                ClaudeGuidanceService::PAIN_SEVERITY_FOLLOW_UP_ENGLISH,
                ClaudeGuidanceService::ASSOCIATED_FOLLOW_UP_ENGLISH,
            ],
            replyLanguage: 'filipino',
        ));

        $this->assertSame(AiGuidanceType::Uncertain, $result->type);
        $this->assertNull($result->clinicId);
        $this->assertNull($result->serviceId);
        $this->assertSame(ClaudeGuidanceService::UNABLE_TO_ROUTE_MESSAGE_FILIPINO, $result->message);
    }

    public function test_emergency_overlay_is_unchanged_and_does_not_wait_for_language_preference(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'recommendation',
            'service_id' => 1,
            'reason' => 'Should not book.',
            'confidence' => null,
        ]);

        $result = $this->service()->guide('I have a sudden severe headache and I cannot speak.');

        $this->assertSame(AiGuidanceType::Safety, $result->type);
        $this->assertSame(ClaudeGuidanceService::URGENT_SAFETY_MESSAGE, $result->message);
        Http::assertSentCount(0);
    }

    /**
     * @param  array<string, mixed>  $guidance
     */
    private function fakeClaude(array $guidance): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode($guidance, JSON_THROW_ON_ERROR),
                ]],
            ]),
        ]);
    }

    private function service(): ClaudeGuidanceService
    {
        return app(ClaudeGuidanceService::class);
    }

    private function backState(
        string $question = ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO,
        ?string $priorAnswer = null,
        string $language = 'filipino',
    ): AiConversationState {
        $turns = [
            ['role' => 'user', 'content' => 'hello! ang sakit ng likod ko'],
            ['role' => 'assistant', 'content' => $question],
        ];
        $asked = [$question];

        if (is_string($priorAnswer)) {
            $turns[] = ['role' => 'user', 'content' => $priorAnswer];
        }

        return new AiConversationState(
            turns: $turns,
            openingConcern: 'hello! ang sakit ng likod ko',
            clarificationCount: count($asked),
            askedQuestions: $asked,
            replyLanguage: $language,
        );
    }

    private function eligibleClinic(): Clinic
    {
        $department = Department::query()->firstOrCreate(
            ['name' => 'Development Department A'],
            [
                'description' => 'Placeholder department for local development. Not hospital data.',
                'status' => DepartmentStatus::Active,
            ],
        );

        return Clinic::query()->firstOrCreate(
            ['name' => 'Development Clinic A'],
            [
                'department_id' => $department->id,
                'description' => 'Placeholder clinic for local development. Not hospital data.',
                'status' => ClinicStatus::Active,
            ],
        );
    }

    private function doctor(?Clinic $clinic = null): Doctor
    {
        $clinic ??= $this->eligibleClinic();

        return Doctor::query()->firstOrCreate(
            [
                'clinic_id' => $clinic->id,
                'display_name' => 'Hospital Provided Doctor',
            ],
            [
                'specialization' => 'Internal Medicine',
                'status' => DoctorStatus::Active,
                'data_source' => HospitalDataSource::Development,
            ],
        );
    }
}
