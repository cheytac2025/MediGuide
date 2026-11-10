<?php

namespace Tests\Feature\Patient;

use App\Enums\AiGuidanceType;
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
use App\Support\AiDisclaimer;
use App\Support\AiSafetyEvidenceShadow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiSafetyEvidenceShadowTest extends TestCase
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

    public function test_existing_safety_responses_stay_unchanged_when_no_evidence_is_supplied(): void
    {
        $this->assertFalse(config('ai.safety_evidence_schema'));
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'safety',
            'message' => 'Please seek prompt in-person medical evaluation. This is not a diagnosis.',
        ]);

        $headache = $this->service()->guide('Masakit ulo ko.');
        $vomiting = $this->service()->guide('Nagsusuka ako.');

        $this->assertSame(AiGuidanceType::Clarification, $headache->type);
        $this->assertSame(ClaudeGuidanceService::HEADACHE_FOLLOW_UP_FILIPINO, $headache->question);
        $this->assertSame(AiGuidanceType::Safety, $vomiting->type);
        $this->assertSame(AiSafetyKind::PromptEvaluation, $vomiting->safetyKind);
        $this->assertSame(ClaudeGuidanceService::PROMPT_EVALUATION_MESSAGE_FILIPINO, $vomiting->message);
        $this->assertSame(2, $this->shadow()->evaluations());
        $this->assertSame('not_supplied', $this->shadow()->lastStatus());
    }

    public function test_validated_evidence_does_not_change_the_visitor_response_or_reach_the_browser(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude($this->safetyPayload([['quote' => 'nagsusuka ako']]));

        $result = $this->service()->guide('nagsusuka ako');
        $response = $this->withSession([
            AiDisclaimer::SESSION_KEY => true,
        ])->postJson(route('ai-front-desk.guidance'), [
            'message' => 'nagsusuka ako',
        ]);

        $this->assertSame(AiSafetyKind::PromptEvaluation, $result->safetyKind);
        $this->assertSame(ClaudeGuidanceService::PROMPT_EVALUATION_MESSAGE_FILIPINO, $result->message);
        $this->assertSame('quoted_without_rule', $this->shadow()->lastStatus());
        $response->assertOk()
            ->assertJsonPath('type', 'safety')
            ->assertJsonPath('safety_kind', 'prompt_evaluation')
            ->assertJsonMissingPath('evidence')
            ->assertJsonMissingPath('basis')
            ->assertJsonMissingPath('proposed_level');
        $this->assertStringNotContainsString('nagsusuka ako', json_encode($response->json('message'), JSON_THROW_ON_ERROR));
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_a_fabricated_emergency_quote_does_not_upgrade_the_current_response(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude($this->safetyPayload([
            ['quote' => 'I have a sudden severe headache and I cannot speak.'],
        ], 'emergency'));

        $result = $this->service()->guide('nagsusuka ako');

        $this->assertSame(AiSafetyKind::PromptEvaluation, $result->safetyKind);
        $this->assertSame('unsupported', $this->shadow()->lastStatus());
        $this->assertNotSame(ClaudeGuidanceService::URGENT_SAFETY_MESSAGE, $result->message);
    }

    public function test_existing_overlays_recommendations_and_failures_do_not_use_shadow_evidence(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'clarification',
            'question' => 'Saan po kayo kumikirot, sa kaliwa o kanan?',
        ]);

        $unfamiliar = $this->service()->guide('Parang may kumikirot sa tagiliran ko.');
        $emergency = $this->service()->guide('I have a sudden severe headache and I cannot speak.');
        $abdominal = $this->service()->guide('nagsusuka ako at sobrang sakit ng tiyan');

        $this->assertSame('Saan po kayo kumikirot, sa kaliwa o kanan?', $unfamiliar->question);
        $this->assertSame(AiSafetyKind::Emergency, $emergency->safetyKind);
        $this->assertSame(AiSafetyKind::PromptEvaluation, $abdominal->safetyKind);
        $this->assertSame(0, $this->shadow()->evaluations());
    }

    public function test_recommendation_eligibility_is_unchanged_by_the_shadow_check(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'recommendation',
            'service_id' => 1,
            'reason' => 'This is a navigation suggestion based on the listed hospital specialization.',
            'confidence' => null,
        ]);

        $recommendation = $this->service()->guide("I've had a headache since yesterday.");

        $this->assertSame(AiGuidanceType::Recommendation, $recommendation->type);
        $this->assertSame(1, $recommendation->serviceId);
        $this->assertSame(0, $this->shadow()->evaluations());
    }

    public function test_an_invalid_service_id_is_still_rejected(): void
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
        $this->assertNull($invalid->serviceId);
        $this->assertSame(0, $this->shadow()->evaluations());
    }

    public function test_provider_failure_and_missing_disclaimer_stay_unchanged(): void
    {
        $this->eligibleClinic();
        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => 'unavailable'], 500),
        ]);

        $failed = $this->service()->guide('masakit ulo ko');

        $this->assertSame(ClaudeGuidanceService::PROCESSING_FALLBACK_MESSAGE, $failed->message);
        $this->assertSame(0, $this->shadow()->evaluations());
        $this->postJson(route('ai-front-desk.guidance'), [
            'message' => 'masakit ulo ko',
        ])->assertRedirect(route('ai-disclaimer'));
    }

    public function test_the_live_provider_schema_does_not_request_evidence_fields(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'clarification',
            'question' => 'Gaano na po katagal ito?',
        ]);

        $this->service()->guide('May kakaibang pakiramdam sa katawan ko.');
        $safety = $this->safetyBranch($this->sentSchema());

        $this->assertSame(['type', 'message'], $safety['required']);
        $this->assertArrayNotHasKey('evidence', $safety['properties']);
        $this->assertSame(0, $this->shadow()->evaluations());
    }

    public function test_the_evidence_schema_can_be_built_without_changing_the_current_response(): void
    {
        config(['ai.safety_evidence_schema' => true]);
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'safety',
            'message' => 'Please seek prompt in-person medical evaluation. This is not a diagnosis.',
        ]);

        $result = $this->service()->guide('Nagsusuka ako.');
        $safety = $this->safetyBranch($this->sentSchema());

        $this->assertSame(AiSafetyKind::PromptEvaluation, $result->safetyKind);
        $this->assertContains('evidence', $safety['required']);
        $this->assertSame('not_supplied', $this->shadow()->lastStatus());
        $this->assertTrue(config('ai.safety_evidence_schema') === true);
    }

    private function service(): ClaudeGuidanceService
    {
        return app(ClaudeGuidanceService::class);
    }

    private function shadow(): AiSafetyEvidenceShadow
    {
        return app(AiSafetyEvidenceShadow::class);
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

    /**
     * @param  list<array{quote: string}>  $evidence
     * @return array<string, mixed>
     */
    private function safetyPayload(array $evidence, string $level = 'prompt_evaluation'): array
    {
        return [
            'type' => 'safety',
            'message' => 'Please seek prompt in-person medical evaluation. This is not a diagnosis.',
            'basis' => 'visitor_reported',
            'evidence' => $evidence,
            'proposed_level' => $level,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sentSchema(): array
    {
        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded);
        $last = $recorded instanceof \Illuminate\Support\Collection ? $recorded->last() : $recorded[array_key_last($recorded)];
        $schema = $last[0]->data()['output_config']['format']['schema'] ?? null;
        $this->assertIsArray($schema);

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function safetyBranch(array $schema): array
    {
        foreach ($schema['anyOf'] as $branch) {
            if (($branch['properties']['type']['enum'][0] ?? null) === 'safety') {
                return $branch;
            }
        }

        $this->fail('The safety response branch was not in the provider schema.');
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
