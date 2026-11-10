<?php

namespace Tests\Evaluation\AiSafetyEvidence;

use App\Enums\AiGuidanceType;
use App\Enums\AiSafetyEvidenceStatus;
use App\Enums\AiSafetyKind;
use App\Enums\ClinicStatus;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorStatus;
use App\Enums\HospitalDataSource;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\User;
use App\Services\Ai\ClaudeGuidanceService;
use App\Support\AiSafetyEvidenceShadow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SafetyEvidenceEvaluationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $currentPayload = [
        'type' => 'clarification',
        'question' => 'Placeholder question for the evaluation fake.',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.key' => 'test-anthropic-key',
            'services.anthropic.model' => 'claude-sonnet-5',
            'services.anthropic.version' => '2023-06-01',
            'hospital.data_source' => 'development',
            'ai.safety_evidence_schema' => false,
        ]);

        $this->eligibleClinic();
        Http::preventStrayRequests();
        Http::fake(function () {
            return Http::response([
                'id' => 'msg_evaluation',
                'type' => 'message',
                'role' => 'assistant',
                'stop_reason' => 'end_turn',
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode($this->currentPayload, JSON_THROW_ON_ERROR),
                ]],
            ]);
        });
    }

    public function test_synthetic_catalog_covers_the_required_categories_without_assigning_urgency(): void
    {
        $this->assertFalse(config('ai.safety_evidence_schema'));

        $scenarios = SyntheticConcernCatalog::scenarios();
        $categories = array_column($scenarios, 'category');

        foreach (SyntheticConcernCatalog::requiredCategories() as $category) {
            $this->assertContains($category, $categories, $category);
        }

        $harness = new SafetyEvidenceEvaluationHarness(app(\App\Support\AiSafetyEvidenceValidator::class));
        $assessments = [];

        foreach ($scenarios as $scenario) {
            $assessment = $harness->score($scenario);
            $assessments[] = $assessment;

            $this->assertSame($scenario['expected_status'], $assessment->status, $scenario['id']);
            $this->assertSame($scenario['expected_rule'], $assessment->observedExistingRule, $scenario['id']);
            $this->assertFalse($assessment->assignsClinicalUrgency(), $scenario['id']);
        }

        $counts = $harness->countsByStatus($assessments);

        $this->assertSame(34, count($scenarios));
        $this->assertSame(20, $counts[AiSafetyEvidenceStatus::QuotedWithoutRule->value]);
        $this->assertSame(2, $counts[AiSafetyEvidenceStatus::QuotedWithExistingRule->value]);
        $this->assertSame(4, $counts[AiSafetyEvidenceStatus::NotSupplied->value]);
        $this->assertSame(3, $counts[AiSafetyEvidenceStatus::Unsupported->value]);
        $this->assertSame(1, $counts[AiSafetyEvidenceStatus::Negated->value]);
        $this->assertSame(1, $counts[AiSafetyEvidenceStatus::UnknownAnswer->value]);
        $this->assertSame(1, $counts[AiSafetyEvidenceStatus::InvalidContract->value]);
        $this->assertSame(1, $counts[AiSafetyEvidenceStatus::ModelInferred->value]);
        $this->assertSame(1, $counts[AiSafetyEvidenceStatus::AffirmationOnly->value]);
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_scoring_does_not_change_the_current_visitor_response(): void
    {
        $harness = new SafetyEvidenceEvaluationHarness(app(\App\Support\AiSafetyEvidenceValidator::class));
        $service = app(ClaudeGuidanceService::class);
        $shadow = app(AiSafetyEvidenceShadow::class);

        foreach (SyntheticConcernCatalog::scenarios() as $scenario) {
            $beforeHttp = Http::recorded()->count();
            $beforeShadow = $shadow->evaluations();
            $this->currentPayload = $scenario['payload'];

            $result = $service->guide($scenario['latest'], $harness->state($scenario));
            $visitor = $scenario['visitor'];

            $this->assertSame($visitor['type'], $result->type->value, $scenario['id']);
            $this->assertSame($visitor['kind'], $result->safetyKind?->value, $scenario['id']);
            $this->assertSame($visitor['http'], Http::recorded()->count() > $beforeHttp, $scenario['id']);
            $this->assertSame($visitor['shadow'], $shadow->evaluations() - $beforeShadow, $scenario['id']);
            $this->assertFalse($harness->score($scenario)->assignsClinicalUrgency(), $scenario['id']);

            if ($visitor['type'] === AiGuidanceType::Safety->value && $visitor['kind'] === AiSafetyKind::Emergency->value) {
                $this->assertSame(ClaudeGuidanceService::URGENT_SAFETY_MESSAGE, $result->message, $scenario['id']);
            }

            if ($visitor['type'] === AiGuidanceType::Safety->value && $visitor['kind'] === AiSafetyKind::PromptEvaluation->value) {
                $expected = ($visitor['english'] ?? false)
                    ? ClaudeGuidanceService::PROMPT_EVALUATION_MESSAGE
                    : ClaudeGuidanceService::PROMPT_EVALUATION_MESSAGE_FILIPINO;
                $this->assertSame($expected, $result->message, $scenario['id']);
            }

            if (array_key_exists('question', $visitor)) {
                $this->assertSame($visitor['question'], $result->question, $scenario['id']);
            }

            if (array_key_exists('service_id', $visitor)) {
                $this->assertSame($visitor['service_id'], $result->serviceId, $scenario['id']);
            }

            if ($scenario['id'] === 'navigation-only') {
                $this->assertSame(ClaudeGuidanceService::OPENING_CONCERN_QUESTION_ENGLISH, $result->question);
            }

            if ($scenario['id'] === 'premature-recommendation') {
                $this->assertNull($result->serviceId);
            }
        }

        $this->assertFalse(config('ai.safety_evidence_schema'));
        $this->assertSame(0, Appointment::query()->count());
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
