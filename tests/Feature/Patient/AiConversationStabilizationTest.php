<?php

namespace Tests\Feature\Patient;

use App\Enums\AiGuidanceType;
use App\Enums\AiSafetyKind;
use App\Enums\ClinicStatus;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorStatus;
use App\Enums\HospitalDataSource;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Services\Ai\ClaudeGuidanceService;
use App\Support\AiConversationInterpreter;
use App\Support\AiConversationState;
use App\Support\AiDisclaimer;
use App\Support\AiFrontDeskConversation;
use App\Support\AiNavigationSafety;
use App\Support\AiVisitorReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Current navigation behavior for open-ended concerns.
 *
 * A model safety reply for a concern that is not a recognized pain concern
 * still becomes prompt evaluation. That urgency rule is not changed here.
 */
class AiConversationStabilizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.key' => 'test-anthropic-key',
            'services.anthropic.model' => 'claude-sonnet-5',
            'services.anthropic.version' => '2023-06-01',
            'hospital.data_source' => 'development',
        ]);

        Http::preventStrayRequests();
    }

    public function test_plain_headache_is_not_a_local_emergency_and_model_safety_asks_one_follow_up(): void
    {
        $this->eligibleClinic();
        $text = 'masakit ulo ko';
        $decision = app(AiNavigationSafety::class)->classify($text, AiConversationState::empty(), $text);

        $this->assertNull($decision->rule);
        $this->assertFalse($decision->blocksRoutineNavigation());

        $this->fakeClaude([
            'type' => 'safety',
            'message' => 'Please seek prompt in-person medical evaluation. This is not a diagnosis.',
        ]);

        $result = $this->service()->guide($text);

        $this->assertSame(AiGuidanceType::Clarification, $result->type);
        $this->assertNull($result->safetyKind);
        $this->assertSame(ClaudeGuidanceService::HEADACHE_FOLLOW_UP_FILIPINO, $result->question);
        $this->assertNotSame(ClaudeGuidanceService::PROMPT_EVALUATION_MESSAGE_FILIPINO, $result->message);
        $this->assertLessThanOrEqual(1, substr_count((string) $result->question, '?'));
    }

    public function test_plain_headache_keeps_a_focused_model_question(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'clarification',
            'question' => 'Kailan po nagsimula ang sakit ng ulo ninyo?',
        ]);

        $clarified = $this->service()->guide('Masaket ulo ko.');

        $this->assertSame(AiGuidanceType::Clarification, $clarified->type);
        $this->assertSame('Kailan po nagsimula ang sakit ng ulo ninyo?', $clarified->question);
    }

    public function test_plain_headache_does_not_recommend_a_doctor_before_a_routing_detail(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'recommendation',
            'service_id' => 1,
            'reason' => 'Limited navigation to Internal Medicine.',
            'confidence' => null,
        ]);

        $early = $this->service()->guide('masakit ulo ko');

        $this->assertSame(AiGuidanceType::Clarification, $early->type);
        $this->assertNull($early->serviceId);
        $this->assertNull($early->clinicId);
    }

    public function test_unfamiliar_wording_keeps_the_model_question_and_navigation_wording_does_not_erase_it(): void
    {
        $this->assertTrue(AiVisitorReply::statesHealthConcern('Parang may kumikirot sa tagiliran ko. Which specialist should I see?'));
        $this->assertFalse(AiVisitorReply::statesHealthConcern("I'm not sure which specialist I need"));

        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'clarification',
            'question' => 'Saan po kayo kumikirot, sa kaliwa o kanan?',
        ]);

        $result = $this->service()->guide('Parang may kumikirot sa tagiliran ko.');

        $this->assertSame(AiGuidanceType::Clarification, $result->type);
        $this->assertSame('Saan po kayo kumikirot, sa kaliwa o kanan?', $result->question);
        $this->assertStringNotContainsString('ulo', mb_strtolower((string) $result->question));
        $this->assertStringNotContainsString('panghihina', mb_strtolower((string) $result->question));

        $navigation = $this->service()->guide('Parang may kumikirot sa tagiliran ko. Which specialist should I see?');

        $this->assertSame(AiGuidanceType::Clarification, $navigation->type);
        $this->assertSame(ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO, $navigation->question);
        $this->assertNotSame(ClaudeGuidanceService::OPENING_CONCERN_QUESTION_FILIPINO, $navigation->question);
    }

    public function test_unfamiliar_non_pain_model_safety_stays_prompt_evaluation_until_hospital_review(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'safety',
            'message' => 'Please seek prompt in-person medical evaluation. This is not a diagnosis.',
        ]);

        $result = $this->service()->guide('May kakaibang pakiramdam sa katawan ko.');

        $this->assertSame(AiGuidanceType::Safety, $result->type);
        $this->assertSame(AiSafetyKind::PromptEvaluation, $result->safetyKind);
        $this->assertSame(ClaudeGuidanceService::PROMPT_EVALUATION_MESSAGE_FILIPINO, $result->message);
        $this->assertNull($result->clinicId);
    }

    public function test_short_answers_denials_and_unknowns_do_not_invent_a_new_concern(): void
    {
        $state = new AiConversationState(
            turns: [
                ['role' => 'user', 'content' => 'masakit ulo ko'],
                ['role' => 'assistant', 'content' => ClaudeGuidanceService::HEADACHE_FOLLOW_UP_FILIPINO],
            ],
            openingConcern: 'masakit ulo ko',
            clarificationCount: 1,
            askedQuestions: [ClaudeGuidanceService::HEADACHE_FOLLOW_UP_FILIPINO],
            replyLanguage: 'filipino',
        );

        $unknown = AiConversationInterpreter::interpret($state, 'Hindi ko alam');
        $denied = AiConversationInterpreter::interpret($state, 'wala');
        $duration = AiConversationInterpreter::interpret($state, 'two days');

        $this->assertSame('headache', $unknown->concernKind);
        $this->assertTrue($unknown->isUnknown(AiConversationInterpreter::TOPIC_DURATION));
        $this->assertFalse($unknown->isDenied(AiConversationInterpreter::TOPIC_DURATION));
        $this->assertSame('headache', $denied->concernKind);
        $this->assertTrue($denied->isDenied(AiConversationInterpreter::TOPIC_DURATION));
        $this->assertSame('filipino', $duration->replyLanguage);
        $this->assertTrue($duration->hasUsableDuration());
        $this->assertSame('masakit ulo ko', $duration->primaryConcern);
    }

    public function test_an_answered_duration_question_is_not_repeated_in_the_other_language(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'clarification',
            'question' => 'Where do you feel this?',
        ]);

        $state = new AiConversationState(
            turns: [
                ['role' => 'user', 'content' => 'masakit ulo ko'],
                ['role' => 'assistant', 'content' => ClaudeGuidanceService::HEADACHE_FOLLOW_UP_FILIPINO],
            ],
            openingConcern: 'masakit ulo ko',
            clarificationCount: 1,
            askedQuestions: [ClaudeGuidanceService::HEADACHE_FOLLOW_UP_FILIPINO],
            replyLanguage: 'filipino',
        );

        $result = $this->service()->guide('two days', $state);

        $this->assertSame(AiGuidanceType::Clarification, $result->type);
        $this->assertSame(ClaudeGuidanceService::HEADACHE_LOCATION_FOLLOW_UP_FILIPINO, $result->question);
        $this->assertNotSame(ClaudeGuidanceService::HEADACHE_FOLLOW_UP_FILIPINO, $result->question);
        $this->assertNotSame('Where do you feel this?', $result->question);
    }

    public function test_english_unfamiliar_wording_keeps_a_matching_model_question(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'clarification',
            'question' => 'Where do you feel this?',
        ]);

        $result = $this->service()->guide('My chest feels weird.');

        $this->assertSame(AiGuidanceType::Clarification, $result->type);
        $this->assertSame('Where do you feel this?', $result->question);
        $this->assertStringNotContainsString('headache', mb_strtolower((string) $result->question));
    }

    public function test_multiple_complaints_ask_which_one_is_primary_before_a_recommendation(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'recommendation',
            'service_id' => 1,
            'reason' => 'Limited navigation to Internal Medicine.',
            'confidence' => null,
        ]);

        $result = $this->service()->guide('May ubo ako at masakit ang ulo ko.');

        $this->assertSame(AiGuidanceType::Clarification, $result->type);
        $this->assertSame(ClaudeGuidanceService::MULTIPLE_PRIMARY_FILIPINO, $result->question);
        $this->assertNull($result->serviceId);
    }

    public function test_recognized_emergency_and_prompt_evaluation_overlays_still_precede_the_model(): void
    {
        $this->eligibleClinic();

        $emergency = $this->service()->guide('I have a sudden severe headache and I cannot speak.');
        $prompt = $this->service()->guide('nagsusuka ako at sobrang sakit ng tiyan');

        $this->assertSame(AiSafetyKind::Emergency, $emergency->safetyKind);
        $this->assertSame(ClaudeGuidanceService::URGENT_SAFETY_MESSAGE, $emergency->message);
        $this->assertSame(AiSafetyKind::PromptEvaluation, $prompt->safetyKind);
        $this->assertSame(ClaudeGuidanceService::PROMPT_EVALUATION_MESSAGE_FILIPINO, $prompt->message);
        Http::assertNothingSent();
    }

    public function test_invalid_service_is_rejected_and_a_real_doctor_comes_from_the_database(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'recommendation',
            'service_id' => 99,
            'reason' => 'Invented service.',
            'confidence' => null,
        ]);

        $invalid = $this->service()->guide("I've had a headache since yesterday.");

        $this->assertSame(AiGuidanceType::Uncertain, $invalid->type);
        $this->assertSame(ClaudeGuidanceService::INSUFFICIENT_HOSPITAL_INFORMATION_MESSAGE, $invalid->message);
        $this->assertNull($invalid->serviceId);
    }

    public function test_a_ready_recommendation_uses_the_database_doctor_and_session_memory(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'recommendation',
            'service_id' => 1,
            'reason' => 'This is a navigation suggestion based on the listed hospital specialization.',
            'confidence' => null,
        ]);

        $response = $this->withSession([
            AiDisclaimer::SESSION_KEY => true,
        ])->postJson(route('ai-front-desk.guidance'), [
            'message' => "I've had a headache since yesterday.",
        ]);

        $response->assertOk()
            ->assertJsonPath('type', 'recommendation')
            ->assertJsonPath('doctors.0.display_name', 'Test Doctor 1');

        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Dr. Invented', $encoded);
        $this->assertStringNotContainsString('test-anthropic-key', $encoded);
        $this->assertSame(0, \App\Models\User::query()->count());
        $this->assertSame(0, \App\Models\Appointment::query()->count());

        $stored = session(AiFrontDeskConversation::SESSION_KEY);
        $this->assertIsArray($stored);
        $this->assertSame("I've had a headache since yesterday.", $stored['opening_concern']);
    }

    public function test_provider_failure_is_not_treated_as_safety_and_disclaimer_is_required(): void
    {
        $this->eligibleClinic();
        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => 'unavailable'], 500),
        ]);

        $failed = $this->service()->guide('masakit ulo ko');

        $this->assertSame(AiGuidanceType::Uncertain, $failed->type);
        $this->assertSame(ClaudeGuidanceService::PROCESSING_FALLBACK_MESSAGE, $failed->message);
        $this->assertNotSame(AiSafetyKind::PromptEvaluation, $failed->safetyKind);

        $this->postJson(route('ai-front-desk.guidance'), [
            'message' => 'masakit ulo ko',
        ])->assertRedirect(route('ai-disclaimer'));
    }

    private function service(): ClaudeGuidanceService
    {
        return app(ClaudeGuidanceService::class);
    }

    /**
     * @param  array<string, mixed>  $guidance
     */
    private function fakeClaude(array $guidance): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_test',
                'type' => 'message',
                'role' => 'assistant',
                'stop_reason' => 'end_turn',
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode($guidance, JSON_THROW_ON_ERROR),
                ]],
            ]),
        ]);
    }

    private function eligibleClinic(): Clinic
    {
        $department = Department::query()->create([
            'name' => 'Development Department A',
            'description' => 'Placeholder department for local development. Not hospital data.',
            'status' => DepartmentStatus::Active,
        ]);

        $clinic = Clinic::query()->create([
            'department_id' => $department->id,
            'name' => 'Development Clinic A',
            'description' => 'Placeholder clinic for local development. Not hospital data.',
            'status' => ClinicStatus::Active,
            'data_source' => HospitalDataSource::Development,
        ]);

        Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'display_name' => 'Test Doctor 1',
            'specialization' => 'Internal Medicine',
            'status' => DoctorStatus::Active,
            'data_source' => HospitalDataSource::Development,
        ]);

        return $clinic;
    }
}
