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
use App\Support\AiConversationState;
use App\Support\AiNavigationSafety;
use App\Support\AiUrgentConcernDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Records the current software decision for vomiting complaints.
 * These tests do not approve a new clinical urgency rule.
 */
class AiVomitingEscalationTest extends TestCase
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

    public function test_isolated_vomiting_is_not_a_local_emergency_or_prompt_evaluation_rule(): void
    {
        foreach ([
            'nagsusuka ako',
            'nasusuka ako',
            'I have been vomiting',
            'nagsusuka ako since kanina',
            'I feel nauseous and nagsusuka',
            'pagod ako',
            'nahihilo ako',
            'nagtatae ako',
        ] as $text) {
            $decision = $this->safety()->classify($text, AiConversationState::empty(), $text);

            $this->assertFalse($this->urgent()->isUrgent($text), $text);
            $this->assertFalse($decision->blocksRoutineNavigation(), $text);
            $this->assertNull($decision->rule, $text);
        }
    }

    public function test_generic_model_safety_for_isolated_vomiting_is_remapped_to_prompt_evaluation(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'safety',
            'message' => 'Please seek prompt in-person medical evaluation. This is not a diagnosis.',
        ]);

        $result = $this->service()->guide('nagsusuka ako');

        $this->assertSame(AiGuidanceType::Safety, $result->type);
        $this->assertSame(AiSafetyKind::PromptEvaluation, $result->safetyKind);
        $this->assertSame(ClaudeGuidanceService::PROMPT_EVALUATION_MESSAGE_FILIPINO, $result->message);
        $this->assertStringNotContainsString('panghihina', mb_strtolower((string) $result->message));
        $this->assertStringNotContainsString('dugo', mb_strtolower((string) $result->message));
        $this->assertNull($result->clinicId);
    }

    public function test_unknown_duration_does_not_remove_model_safety_for_vomiting(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'safety',
            'message' => 'Please seek prompt in-person medical evaluation. This is not a diagnosis.',
        ]);

        $result = $this->service()->guide('nagsusuka ako. Hindi ko alam kung gaano katagal.');

        $this->assertSame(AiSafetyKind::PromptEvaluation, $result->safetyKind);
        $this->assertSame(ClaudeGuidanceService::PROMPT_EVALUATION_MESSAGE_FILIPINO, $result->message);
    }

    public function test_model_safety_that_invents_a_warning_sign_asks_a_follow_up_without_that_sign(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'safety',
            'message' => 'Dahil may panghihina ka kasabay ng pagsusuka, magpatingin agad.',
        ]);

        $result = $this->service()->guide('nagsusuka ako');

        $this->assertSame(AiGuidanceType::Clarification, $result->type);
        $this->assertNull($result->safetyKind);
        $this->assertStringNotContainsString('panghihina', mb_strtolower((string) $result->question));
        $this->assertStringNotContainsString('weakness', mb_strtolower((string) $result->question));
    }

    public function test_model_recommendation_for_isolated_vomiting_does_not_book_a_doctor_immediately(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'recommendation',
            'service_id' => 1,
            'reason' => 'This is a navigation suggestion based on the listed hospital specialization.',
            'confidence' => 0.4,
        ]);

        $result = $this->service()->guide('nagsusuka ako');

        $this->assertSame(AiGuidanceType::Clarification, $result->type);
        $this->assertSame(ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO, $result->question);
        $this->assertNull($result->clinicId);
        $this->assertNull($result->serviceId);
    }

    public function test_model_clarification_for_isolated_vomiting_is_kept(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'clarification',
            'question' => 'Gaano na po katagal kayo nagsusuka?',
        ]);

        $result = $this->service()->guide('nagsusuka ako');

        $this->assertSame(AiGuidanceType::Clarification, $result->type);
        $this->assertSame('Gaano na po katagal kayo nagsusuka?', $result->question);
    }

    public function test_vomiting_with_severe_abdominal_pain_is_local_prompt_evaluation_before_the_model(): void
    {
        $this->eligibleClinic();

        $text = 'nagsusuka ako at sobrang sakit ng tiyan';
        $decision = $this->safety()->classify($text, AiConversationState::empty(), $text);
        $result = $this->service()->guide($text);

        $this->assertSame('severe_or_worsening_abdominal_pain', $decision->rule);
        $this->assertSame(AiSafetyKind::PromptEvaluation, $result->safetyKind);
        $this->assertSame(ClaudeGuidanceService::PROMPT_EVALUATION_MESSAGE_FILIPINO, $result->message);
        Http::assertNothingSent();
    }

    public function test_vomiting_blood_and_poor_fluid_intake_are_not_current_local_warning_sign_rules(): void
    {
        foreach ([
            'nagsusuka ako ng dugo',
            'I am vomiting blood',
            'paulit-ulit akong nagsusuka at hindi ko napapanatili ang tubig',
            'I keep vomiting and I cannot keep fluids down',
        ] as $text) {
            $decision = $this->safety()->classify($text, AiConversationState::empty(), $text);

            $this->assertFalse($this->urgent()->isUrgent($text), $text);
            $this->assertFalse($decision->blocksRoutineNavigation(), $text);
        }
    }

    public function test_negated_vomiting_is_not_a_local_warning_sign(): void
    {
        foreach ([
            'hindi ako nagsusuka',
            'wala akong pagsusuka',
            'I am not vomiting',
        ] as $text) {
            $decision = $this->safety()->classify($text, AiConversationState::empty(), $text);

            $this->assertFalse($this->urgent()->isUrgent($text), $text);
            $this->assertNull($decision->rule, $text);
        }
    }

    public function test_existing_emergency_overlay_still_precedes_the_model(): void
    {
        $this->eligibleClinic();

        $result = $this->service()->guide('I have a sudden severe headache and I cannot speak.');

        $this->assertSame(AiGuidanceType::Safety, $result->type);
        $this->assertSame(AiSafetyKind::Emergency, $result->safetyKind);
        $this->assertSame(ClaudeGuidanceService::URGENT_SAFETY_MESSAGE, $result->message);
        Http::assertNothingSent();
    }

    private function safety(): AiNavigationSafety
    {
        return app(AiNavigationSafety::class);
    }

    private function urgent(): AiUrgentConcernDetector
    {
        return app(AiUrgentConcernDetector::class);
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
