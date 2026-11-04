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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiConversationalIntelligenceTest extends TestCase
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

    public function test_concern_specific_follow_ups_stay_focused_and_one_question(): void
    {
        $this->eligibleClinic();
        $this->doctor();

        $cases = [
            'Masakit ulo ko.' => ['ulo', null],
            'Masakit ang tiyan ko.' => [null, ['ulo', 'headache']],
            'May ubo ako.' => ['ubo', ['ulo', 'headache']],
            'May lagnat ako.' => [null, ['ulo', 'headache', 'tiyan']],
            'Masakit ang tuhod ko.' => [null, ['ulo', 'headache']],
            'Masakit ang likod ko.' => [null, ['ulo', 'headache']],
            'May rash ako sa braso.' => [null, ['ulo', 'headache']],
            'Masakit tenga ko.' => [null, ['ulo', 'headache']],
            'Masakit mata ko.' => [null, ['ulo', 'headache']],
            'Buntis po ako at nahihilo.' => [null, ['ulo', 'headache']],
            'Nilalagnat ang anak ko.' => [null, ['ulo', 'headache']],
            'May ubo ako at masakit ang lalamunan.' => [null, ['ulo', 'headache']],
        ];

        foreach ($cases as $concern => [$required, $forbidden]) {
            $this->fakeClaude([
                'type' => 'clarification',
                'question' => ClaudeGuidanceService::GENERAL_FOLLOW_UP_FILIPINO,
            ]);

            $result = $this->service()->guide($concern);
            $question = (string) $result->question;

            $this->assertSame(AiGuidanceType::Clarification, $result->type, $concern);
            $this->assertLessThanOrEqual(1, substr_count($question, '?'), $concern);
            $this->assertFalse(str_contains(mb_strtolower($question), ' and '), $concern);
            $this->assertNotSame(ClaudeGuidanceService::GENERAL_FOLLOW_UP_FILIPINO, $question, $concern);

            if (is_string($required)) {
                $this->assertStringContainsString($required, mb_strtolower($question), $concern);
            }

            foreach ($forbidden ?? [] as $needle) {
                $this->assertStringNotContainsString($needle, mb_strtolower($question), $concern);
            }
        }
    }

    public function test_headache_location_answer_is_not_treated_as_back_pain(): void
    {
        $this->eligibleClinic();
        $this->doctor();
        $this->fakeClaude([
            'type' => 'clarification',
            'question' => 'Masakit ba ang likod ninyo, o back pain ito?',
        ]);

        $result = $this->service()->guide('Sa bandang likod.', new AiConversationState(
            turns: [
                ['role' => 'user', 'content' => 'Masakit ulo ko.'],
                ['role' => 'assistant', 'content' => ClaudeGuidanceService::HEADACHE_LOCATION_FOLLOW_UP_FILIPINO],
            ],
            openingConcern: 'Masakit ulo ko.',
            clarificationCount: 1,
            askedQuestions: [ClaudeGuidanceService::HEADACHE_LOCATION_FOLLOW_UP_FILIPINO],
        ));

        $system = Http::recorded()[0][0]->data()['system'];

        $this->assertSame(AiGuidanceType::Clarification, $result->type);
        $this->assertStringContainsString('back of the head', $system);
        $this->assertStringNotContainsString('back pain', mb_strtolower((string) $result->question));
        $this->assertNotSame(ClaudeGuidanceService::HEADACHE_LOCATION_FOLLOW_UP_FILIPINO, $result->question);
    }

    public function test_previously_answered_location_and_duration_are_not_reasked(): void
    {
        $this->eligibleClinic();
        $this->doctor();
        $this->fakeClaude([
            'type' => 'clarification',
            'question' => ClaudeGuidanceService::LOCATION_FOLLOW_UP_FILIPINO,
        ]);

        $result = $this->service()->guide('Sa kaliwa. Tatlong araw na.', new AiConversationState(
            turns: [
                ['role' => 'user', 'content' => 'Masakit ang tiyan ko.'],
                ['role' => 'assistant', 'content' => ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO],
            ],
            openingConcern: 'Masakit ang tiyan ko.',
            clarificationCount: 1,
            askedQuestions: [ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO],
        ));

        $this->assertSame(AiGuidanceType::Clarification, $result->type);
        $this->assertNotSame(ClaudeGuidanceService::LOCATION_FOLLOW_UP_FILIPINO, $result->question);
        $this->assertNotSame(ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO, $result->question);
        $this->assertStringContainsString('baba', mb_strtolower((string) $result->question));
    }

    public function test_combined_duration_and_onset_question_is_replaced_with_one_focused_question(): void
    {
        $this->eligibleClinic();
        $this->doctor();
        $this->fakeClaude([
            'type' => 'clarification',
            'question' => 'Kailan po nagsimula, at biglaan ba o unti-unti?',
        ]);

        $result = $this->service()->guide('Masakit ulo ko.');

        $this->assertSame(AiGuidanceType::Clarification, $result->type);
        $this->assertSame(ClaudeGuidanceService::HEADACHE_FOLLOW_UP_FILIPINO, $result->question);
        $this->assertLessThanOrEqual(1, substr_count((string) $result->question, '?'));
    }

    public function test_english_filipino_and_taglish_keep_matching_follow_up_language(): void
    {
        $this->eligibleClinic();
        $this->doctor();

        $this->fakeClaude([
            'type' => 'clarification',
            'question' => ClaudeGuidanceService::GENERAL_FOLLOW_UP_ENGLISH,
        ]);
        $english = $this->service()->guide('I have a mild cough.');
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

    public function test_unknown_filipino_answer_uses_filipino_uncertain_wording(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'clarification',
            'question' => 'May lagnat ka ba?',
        ]);

        $result = $this->service()->guide('Hindi ko alam.', [
            ['role' => 'user', 'content' => 'May ubo ako.'],
            ['role' => 'assistant', 'content' => 'Gaano na katagal?'],
        ]);

        $this->assertSame(AiGuidanceType::Uncertain, $result->type);
        $this->assertSame(ClaudeGuidanceService::UNABLE_TO_ROUTE_MESSAGE_FILIPINO, $result->message);
    }

    public function test_english_unknown_answer_keeps_english_uncertain_wording(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'clarification',
            'question' => 'How long has this been happening?',
        ]);

        $result = $this->service()->guide("I don't know", [
            ['role' => 'user', 'content' => 'I have a cough.'],
            ['role' => 'assistant', 'content' => 'How long have you had the cough?'],
        ]);

        $this->assertSame(AiGuidanceType::Uncertain, $result->type);
        $this->assertSame(ClaudeGuidanceService::UNABLE_TO_ROUTE_MESSAGE, $result->message);
    }

    public function test_structured_facts_are_sent_to_claude_and_ignored_if_session_is_tampered(): void
    {
        $this->eligibleClinic();
        $this->doctor();
        $this->fakeClaude([
            'type' => 'clarification',
            'question' => ClaudeGuidanceService::HEADACHE_FOLLOW_UP_FILIPINO,
        ]);

        $this->service()->guide('Masakit ulo ko.');
        $system = Http::recorded()[0][0]->data()['system'];
        $this->assertStringContainsString('"reported_facts"', $system);
        $this->assertStringContainsString('"concern_kind":"headache"', $system);
        $this->assertStringContainsString('missing_priority', $system);

        Http::fake();
        $this->fakeClaude([
            'type' => 'clarification',
            'question' => ClaudeGuidanceService::GENERAL_FOLLOW_UP_ENGLISH,
        ]);

        $tampered = AiConversationState::fromEnvelope([
            'v' => 2,
            'opening_concern' => 'May ubo ako.',
            'clarification_count' => 0,
            'asked_questions' => [],
            'turns' => [
                ['role' => 'user', 'content' => 'May ubo ako.'],
            ],
            'facts' => [
                'associated_symptoms' => ['sudden severe headache', 'cannot breathe'],
                'body_location' => 'invented',
            ],
        ]);

        $result = $this->service()->guide('May ubo ako.', $tampered);
        $system = Http::recorded()[0][0]->data()['system'];

        $this->assertNotSame(AiGuidanceType::Safety, $result->type);
        $this->assertStringContainsString('"associated_symptoms":[]', $system);
        $this->assertStringContainsString('"concern_kind":"cough"', $system);
        $this->assertStringNotContainsString('"body_location":"invented"', $system);
    }

    public function test_new_conversation_clears_structured_facts(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'clarification',
            'question' => 'Gaano na katagal?',
        ]);

        $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->postJson(route('ai-front-desk.guidance'), ['message' => 'Masakit ulo ko.'])
            ->assertOk();

        $this->postJson(route('ai-front-desk.conversation.clear'))->assertOk();

        $this->assertSame([], AiFrontDeskConversation::turns($this->app['session.store']));
        $this->assertNull(AiFrontDeskConversation::state($this->app['session.store'])->openingConcern);
    }

    public function test_four_clarification_cap_still_stops_questioning(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'clarification',
            'question' => 'May iba ka pa bang gusto idagdag?',
        ]);

        $result = $this->service()->guide('Medyo lang.', new AiConversationState(
            turns: [
                ['role' => 'user', 'content' => 'Masakit ulo ko.'],
                ['role' => 'assistant', 'content' => 'Gaano na katagal?'],
            ],
            openingConcern: 'Masakit ulo ko.',
            clarificationCount: AiConversationState::MAX_CLARIFICATION_ATTEMPTS,
            askedQuestions: ['Gaano na katagal?', 'Saan po?', 'Biglaan po ba?', 'May iba pa?'],
        ));

        $this->assertSame(AiGuidanceType::Uncertain, $result->type);
        $this->assertNull($result->clinicId);
    }

    public function test_premature_recommendation_is_deferred_until_a_routing_detail_exists(): void
    {
        $clinic = $this->eligibleClinic();
        $this->doctor($clinic);

        $this->fakeClaude([
            'type' => 'recommendation',
            'service_id' => 1,
            'reason' => 'Limited navigation to Internal Medicine.',
            'confidence' => null,
        ]);

        $vague = $this->service()->guide('May ubo ako.');
        $this->assertSame(AiGuidanceType::Clarification, $vague->type);

        $this->fakeClaude([
            'type' => 'recommendation',
            'service_id' => 1,
            'reason' => 'Limited navigation to Internal Medicine.',
            'confidence' => null,
        ]);
        $enough = $this->service()->guide('I have a mild cough since yesterday. No fever and I can breathe normally.');
        $this->assertSame(AiGuidanceType::Recommendation, $enough->type);
        $this->assertSame(1, $enough->serviceId);
        $this->assertNull($enough->clinicId);
    }

    public function test_safety_still_beats_recommendation_and_invalid_service_ids_are_rejected(): void
    {
        $this->eligibleClinic();
        $this->doctor();

        $this->fakeClaude([
            'type' => 'recommendation',
            'service_id' => 1,
            'reason' => 'Should not book.',
            'confidence' => null,
        ]);
        $emergency = $this->service()->guide('Hindi ako makahinga.');
        $this->assertSame(AiGuidanceType::Safety, $emergency->type);
        $this->assertNull($emergency->clinicId);
    }

    public function test_invalid_service_id_is_rejected_after_a_detailed_cough_concern(): void
    {
        $this->eligibleClinic();
        $this->doctor();
        $this->fakeClaude([
            'type' => 'recommendation',
            'service_id' => 99,
            'reason' => 'Invented service.',
            'confidence' => null,
        ]);

        $invalid = $this->service()->guide('I have a mild cough since yesterday.');

        $this->assertSame(AiGuidanceType::Uncertain, $invalid->type);
        $this->assertSame(ClaudeGuidanceService::INSUFFICIENT_HOSPITAL_INFORMATION_MESSAGE, $invalid->message);
        $this->assertNull($invalid->serviceId);
    }

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
