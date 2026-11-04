<?php

namespace Tests\Feature\Patient;

use App\Enums\AiGuidanceType;
use App\Enums\AiSafetyKind;
use App\Enums\ClinicStatus;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Services\Ai\ClaudeGuidanceService;
use App\Support\AiConversationState;
use App\Support\AiDisclaimer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AiSafetyFirstNavigationTest extends TestCase
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

    public function test_right_lower_abdominal_pain_for_several_days_is_prompt_evaluation(): void
    {
        $this->withSurgeryDoctor();
        $this->fakeClaudeRecommendation();

        $result = $this->service()->guide('wala naman', $this->rlqState());

        $this->assertPromptEvaluation($result, ClaudeGuidanceService::PROMPT_EVALUATION_MESSAGE_FILIPINO);
        Http::assertSentCount(0);
    }

    public function test_right_lower_abdominal_pain_without_fever_is_still_prompt_evaluation(): void
    {
        $this->withSurgeryDoctor();
        $this->fakeClaudeRecommendation();

        $result = $this->service()->guide(
            'wala naman lagnat',
            $this->rlqState(),
        );

        $this->assertPromptEvaluation($result, ClaudeGuidanceService::PROMPT_EVALUATION_MESSAGE_FILIPINO);
        $this->assertNotSame(ClaudeGuidanceService::URGENT_SAFETY_MESSAGE, $result->message);
        $this->assertNotSame(ClaudeGuidanceService::URGENT_SAFETY_MESSAGE_FILIPINO, $result->message);
        $this->assertStringNotContainsString('if this may be a medical emergency', mb_strtolower((string) $result->message));
    }

    public function test_right_lower_abdominal_pain_without_vomiting_is_still_prompt_evaluation(): void
    {
        $this->withSurgeryDoctor();
        $this->fakeClaudeRecommendation();

        $result = $this->service()->guide(
            'walang pagsusuka',
            $this->rlqState(),
        );

        $this->assertPromptEvaluation($result, ClaudeGuidanceService::PROMPT_EVALUATION_MESSAGE_FILIPINO);
    }

    public function test_sudden_severe_headache_is_emergency_not_prompt_evaluation(): void
    {
        $this->eligibleClinic();
        $this->fakeClaudeRecommendation();

        $result = $this->service()->guide('I have a sudden severe headache.');

        $this->assertEmergency($result, ClaudeGuidanceService::URGENT_SAFETY_MESSAGE);
        $this->assertNotSame(ClaudeGuidanceService::PROMPT_EVALUATION_MESSAGE, $result->message);
    }

    public function test_intermittent_headache_is_not_unsupported_emergency_escalation(): void
    {
        $clinic = $this->eligibleClinic();
        $this->doctor($clinic, 'Hospital Provided Doctor', 'Internal Medicine');
        $this->fakeClaudeRecommendation();

        $result = $this->service()->guide('Sobrang sakit ng ulo bigla-bigla since last week.');

        $this->assertNotSame(AiGuidanceType::Safety, $result->type);
        $this->assertSame(AiGuidanceType::Recommendation, $result->type);
    }

    public function test_new_weakness_or_speech_difficulty_is_emergency(): void
    {
        $this->eligibleClinic();
        $this->fakeClaudeRecommendation();

        $weakness = $this->service()->guide('I have weakness on one side.');
        $speech = $this->service()->guide('I suddenly cannot speak.');

        $this->assertEmergency($weakness, ClaudeGuidanceService::URGENT_SAFETY_MESSAGE);
        $this->assertEmergency($speech, ClaudeGuidanceService::URGENT_SAFETY_MESSAGE);
    }

    public function test_chest_pain_with_concerning_symptoms_is_emergency(): void
    {
        $this->eligibleClinic();
        $this->fakeClaudeRecommendation();

        $result = $this->service()->guide('I have chest pain and I cannot breathe.');

        $this->assertEmergency($result, ClaudeGuidanceService::URGENT_SAFETY_MESSAGE);
    }

    public function test_severe_breathing_difficulty_is_emergency(): void
    {
        $this->eligibleClinic();
        $this->fakeClaudeRecommendation();

        $result = $this->service()->guide('Hindi ako makahinga');

        $this->assertEmergency($result, ClaudeGuidanceService::URGENT_SAFETY_MESSAGE_FILIPINO);
    }

    public function test_sudden_vision_loss_is_emergency(): void
    {
        $this->eligibleClinic();
        $this->fakeClaudeRecommendation();

        $result = $this->service()->guide('Bigla akong hindi makakita.');

        $this->assertEmergency($result, ClaudeGuidanceService::URGENT_SAFETY_MESSAGE_FILIPINO);
    }

    public function test_live_filipino_sudden_severe_headache_wording_is_emergency_without_calling_claude(): void
    {
        $this->eligibleClinic();
        $this->fakeClaudeRecommendation();

        $result = $this->service()->guide('Biglang sumakit nang sobrang tindi ang ulo ko.');

        $this->assertEmergency($result, ClaudeGuidanceService::URGENT_SAFETY_MESSAGE_FILIPINO);
        Http::assertSentCount(0);
    }

    public function test_live_filipino_vision_loss_wording_is_emergency_without_calling_claude(): void
    {
        $this->eligibleClinic();
        $this->fakeClaudeRecommendation();

        $result = $this->service()->guide('Bigla akong nawalan ng paningin sa isang mata.');

        $this->assertEmergency($result, ClaudeGuidanceService::URGENT_SAFETY_MESSAGE_FILIPINO);
        Http::assertSentCount(0);
    }

    public function test_serious_injury_is_prompt_evaluation(): void
    {
        $this->eligibleClinic();
        $this->fakeClaudeRecommendation();

        $result = $this->service()->guide('I think I broke my arm in a fall. It looks deformed.');

        $this->assertPromptEvaluation($result, ClaudeGuidanceService::PROMPT_EVALUATION_MESSAGE);
    }

    public function test_uncontrolled_bleeding_is_emergency(): void
    {
        $this->eligibleClinic();
        $this->fakeClaudeRecommendation();

        $result = $this->service()->guide('Severe uncontrolled bleeding');

        $this->assertEmergency($result, ClaudeGuidanceService::URGENT_SAFETY_MESSAGE);
    }

    public function test_pregnancy_related_warning_signs_are_prompt_evaluation(): void
    {
        $this->eligibleClinic();
        $this->fakeClaudeRecommendation();

        $result = $this->service()->guide('Buntis po ako at may pagdurugo.');

        $this->assertPromptEvaluation($result, ClaudeGuidanceService::PROMPT_EVALUATION_MESSAGE_FILIPINO);
        $this->assertStringNotContainsString('appendicitis', mb_strtolower((string) $result->message));
    }

    public function test_child_with_concerning_symptoms_is_prompt_evaluation(): void
    {
        $this->eligibleClinic();
        $this->fakeClaudeRecommendation();

        $result = $this->service()->guide('My 2 year old has a high fever and is very sleepy and not drinking.');

        $this->assertPromptEvaluation($result, ClaudeGuidanceService::PROMPT_EVALUATION_MESSAGE);
    }

    public function test_ordinary_mild_nonurgent_concern_can_still_recommend(): void
    {
        $clinic = $this->eligibleClinic();
        $this->doctor($clinic, 'Hospital Provided Doctor', 'Internal Medicine');
        $this->fakeClaudeRecommendation();

        $result = $this->service()->guide('I have a mild cough since yesterday.');

        $this->assertSame(AiGuidanceType::Recommendation, $result->type);
        $this->assertSame(1, $result->serviceId);
        $this->assertNull($result->safetyKind);
    }

    public function test_unknown_is_not_treated_as_denied_and_does_not_invent_symptoms(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'clarification',
            'question' => 'May lagnat ka ba?',
        ]);

        $result = $this->service()->guide('Hindi ko alam.', new AiConversationState(
            turns: [
                ['role' => 'user', 'content' => 'May ubo ako.'],
                ['role' => 'assistant', 'content' => 'Gaano na katagal?'],
            ],
            openingConcern: 'May ubo ako.',
            clarificationCount: 1,
            askedQuestions: ['Gaano na katagal?'],
        ));

        $this->assertSame(AiGuidanceType::Uncertain, $result->type);
        $this->assertStringNotContainsString('walang lagnat', mb_strtolower((string) $result->message));
        $this->assertStringNotContainsString('appendicitis', mb_strtolower((string) $result->message));
    }

    public function test_contextual_negation_does_not_create_an_emergency(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'clarification',
            'question' => ClaudeGuidanceService::HEADACHE_FOLLOW_UP_FILIPINO,
        ]);

        $result = $this->service()->guide('Masakit ulo ko. Wala akong panghihina.');

        $this->assertSame(AiGuidanceType::Clarification, $result->type);
        $this->assertNotSame(AiGuidanceType::Safety, $result->type);
    }

    public function test_taglish_and_english_warning_signs_are_recognized(): void
    {
        $this->eligibleClinic();
        $this->fakeClaudeRecommendation();

        $taglish = $this->service()->guide('Parang stroke po, I cannot speak.');
        $english = $this->service()->guide('I have a sudden severe headache and I cannot speak.');

        $this->assertEmergency($taglish, ClaudeGuidanceService::URGENT_SAFETY_MESSAGE_FILIPINO);
        $this->assertEmergency($english, ClaudeGuidanceService::URGENT_SAFETY_MESSAGE);
    }

    public function test_claude_recommendation_and_valid_service_id_cannot_bypass_recognized_safety(): void
    {
        $this->withSurgeryDoctor();
        $this->fakeClaude([
            'type' => 'recommendation',
            'service_id' => 1,
            'reason' => 'General Surgery may match this concern.',
            'confidence' => 0.9,
        ]);

        $result = $this->service()->guide(
            'Masakit ang tiyan ko sa kanan sa baba for 3 days. No fever.',
            AiConversationState::empty(),
        );

        $this->assertPromptEvaluation($result, ClaudeGuidanceService::PROMPT_EVALUATION_MESSAGE_FILIPINO);
        $this->assertNull($result->serviceId);
        Http::assertSentCount(0);
    }

    public function test_four_clarification_cap_does_not_force_booking_for_rlq_pain(): void
    {
        $this->withSurgeryDoctor();
        $this->fakeClaudeRecommendation();

        $state = new AiConversationState(
            turns: [
                ['role' => 'user', 'content' => 'masaket tiyan ko'],
                ['role' => 'assistant', 'content' => 'Gaano na po katagal?'],
            ],
            openingConcern: 'masaket tiyan ko',
            clarificationCount: AiConversationState::MAX_CLARIFICATION_ATTEMPTS,
            askedQuestions: ['Gaano na po katagal?', 'Saan po?', 'Biglaan po ba?', 'May lagnat po ba?'],
        );

        $result = $this->service()->guide('sa kanan sa baba. wala naman', $state);

        $this->assertPromptEvaluation($result, ClaudeGuidanceService::PROMPT_EVALUATION_MESSAGE_FILIPINO);
        $this->assertNull($result->serviceId);
    }

    public function test_safety_http_payload_has_no_doctors_or_booking_and_distinct_wording(): void
    {
        $this->withSurgeryDoctor();
        $this->fakeClaudeRecommendation();

        $prompt = $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->postJson(route('ai-front-desk.guidance'), [
                'message' => 'Masakit ang tiyan ko sa kanan sa baba for 3 days.',
            ]);

        $prompt->assertOk()
            ->assertJsonPath('type', AiGuidanceType::Safety->value)
            ->assertJsonPath('safety_kind', AiSafetyKind::PromptEvaluation->value)
            ->assertJsonPath('message', ClaudeGuidanceService::PROMPT_EVALUATION_MESSAGE_FILIPINO)
            ->assertJsonMissingPath('doctors')
            ->assertJsonMissingPath('doctors_url')
            ->assertJsonMissingPath('book_appointment_url')
            ->assertJsonMissingPath('clinic');

        $this->postJson(route('ai-front-desk.conversation.clear'))->assertOk();

        $emergency = $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->postJson(route('ai-front-desk.guidance'), [
                'message' => 'I have a sudden severe headache and I cannot speak.',
            ]);

        $emergency->assertOk()
            ->assertJsonPath('type', AiGuidanceType::Safety->value)
            ->assertJsonPath('safety_kind', AiSafetyKind::Emergency->value)
            ->assertJsonPath('message', ClaudeGuidanceService::URGENT_SAFETY_MESSAGE)
            ->assertJsonMissingPath('doctors');

        $this->assertNotSame($prompt->json('message'), $emergency->json('message'));
        $this->assertStringNotContainsString('appendicitis', mb_strtolower((string) $prompt->json('message')));
        $this->assertStringNotContainsString('stroke', mb_strtolower((string) $emergency->json('message')));
        $this->assertStringNotContainsString('heart attack', mb_strtolower((string) $emergency->json('message')));
    }

    public function test_unattributed_model_safety_becomes_prompt_evaluation_not_emergency(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'safety',
            'message' => 'Please seek care. This is not a diagnosis.',
        ]);

        $result = $this->service()->guide('Ubo ako since kahapon.');

        $this->assertPromptEvaluation($result, ClaudeGuidanceService::PROMPT_EVALUATION_MESSAGE_FILIPINO);
        $this->assertNotSame(ClaudeGuidanceService::URGENT_SAFETY_MESSAGE, $result->message);
        $this->assertNotSame(ClaudeGuidanceService::URGENT_SAFETY_MESSAGE_FILIPINO, $result->message);
    }

    public function test_provider_failure_on_routine_concern_stays_processing_error_and_rlq_stays_safe(): void
    {
        $this->eligibleClinic();

        Http::fake(function (): void {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $routine = $this->service()->guide('Masakit ulo ko.');
        $this->assertSame(AiGuidanceType::Uncertain, $routine->type);
        $this->assertSame(ClaudeGuidanceService::PROCESSING_FALLBACK_MESSAGE, $routine->message);
        $this->assertNull($routine->clinicId);

        $rlq = $this->service()->guide('Masakit ang tiyan ko sa kanan sa baba for 3 days.');
        $this->assertPromptEvaluation($rlq, ClaudeGuidanceService::PROMPT_EVALUATION_MESSAGE_FILIPINO);
        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_frontend_safety_ui_has_no_booking_control_and_distinguishes_kinds(): void
    {
        $js = file_get_contents(public_path('js/ai-front-desk.js'));
        $page = $this->withSession([AiDisclaimer::SESSION_KEY => true])->get(route('ai-front-desk'));

        $this->assertIsString($js);
        $this->assertStringContainsString('Prompt medical evaluation', $js);
        $this->assertStringContainsString('Urgent safety guidance', $js);
        $this->assertStringContainsString('appendSafety(payload);', $js);
        $this->assertStringNotContainsString('appendSafety(payload.message);', $js);

        $page->assertOk();
        $page->assertSee('Urgent safety guidance');
        $this->assertSame(1, preg_match(
            '/<template data-mg-tpl="safety">(?<html>.*?)<\/template>/s',
            $page->getContent(),
            $matches,
        ));
        $this->assertStringNotContainsString('data-mg-book-appointment', $matches['html']);
        $this->assertStringNotContainsString('View Available Appointments', $matches['html']);
    }

    public function test_safety_messages_are_not_written_to_logs(): void
    {
        $this->eligibleClinic();
        $logged = [];
        Log::listen(function ($event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        $this->service()->guide('Masakit ang tiyan ko sa kanan sa baba for 3 days.');

        $combined = mb_strtolower(implode("\n", $logged));
        $this->assertStringNotContainsString('masakit ang tiyan', $combined);
        $this->assertStringNotContainsString('kanan sa baba', $combined);
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

    private function fakeClaudeRecommendation(): void
    {
        $this->fakeClaude([
            'type' => 'recommendation',
            'service_id' => 1,
            'reason' => 'General Surgery may match this hospital navigation suggestion.',
            'confidence' => null,
        ]);
    }

    private function service(): ClaudeGuidanceService
    {
        return app(ClaudeGuidanceService::class);
    }

    private function rlqState(): AiConversationState
    {
        return new AiConversationState(
            turns: [
                ['role' => 'user', 'content' => 'masaket tiyan ko'],
                ['role' => 'assistant', 'content' => 'Gaano na po katagal ang sakit ng tiyan ninyo?'],
                ['role' => 'user', 'content' => 'di naman siya laging sumasakit. sumasakit na siya ng mga 3 araw na'],
                ['role' => 'assistant', 'content' => 'Saang parte po ng tiyan ninyo pinaka-nararamdaman ang sakit?'],
                ['role' => 'user', 'content' => 'sa kanan sa baba'],
                ['role' => 'assistant', 'content' => 'May kasama po ba itong sakit ng tiyan na lagnat, pagsusuka, o pagtatae?'],
            ],
            openingConcern: 'masaket tiyan ko',
            clarificationCount: 3,
            askedQuestions: [
                'Gaano na po katagal ang sakit ng tiyan ninyo?',
                'Saang parte po ng tiyan ninyo pinaka-nararamdaman ang sakit?',
                'May kasama po ba itong sakit ng tiyan na lagnat, pagsusuka, o pagtatae?',
            ],
        );
    }

    private function assertPromptEvaluation($result, string $message): void
    {
        $this->assertSame(AiGuidanceType::Safety, $result->type);
        $this->assertSame(AiSafetyKind::PromptEvaluation, $result->safetyKind);
        $this->assertSame($message, $result->message);
        $this->assertNull($result->clinicId);
        $this->assertNull($result->serviceId);
        $this->assertStringNotContainsString('appendicitis', mb_strtolower((string) $result->message));
        $this->assertStringNotContainsString('diagnosis of', mb_strtolower((string) $result->message));
    }

    private function assertEmergency($result, string $message): void
    {
        $this->assertSame(AiGuidanceType::Safety, $result->type);
        $this->assertSame(AiSafetyKind::Emergency, $result->safetyKind);
        $this->assertSame($message, $result->message);
        $this->assertNull($result->clinicId);
        $this->assertNull($result->serviceId);
    }

    private function eligibleClinic(): Clinic
    {
        return Clinic::query()->create([
            'department_id' => Department::query()->create([
                'name' => 'Development Department A',
                'description' => 'Placeholder department for local development. Not hospital data.',
                'status' => DepartmentStatus::Active,
            ])->id,
            'name' => 'Development Clinic A',
            'description' => 'Placeholder clinic for local development. Not hospital data.',
            'status' => ClinicStatus::Active,
        ]);
    }

    private function withSurgeryDoctor(): Clinic
    {
        $clinic = $this->eligibleClinic();
        $this->doctor($clinic, 'Hospital Provided Surgeon', 'General Surgery');

        return $clinic;
    }

    private function doctor(Clinic $clinic, string $displayName, string $specialization): Doctor
    {
        return Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'display_name' => $displayName,
            'specialization' => $specialization,
            'status' => DoctorStatus::Active,
        ]);
    }
}
