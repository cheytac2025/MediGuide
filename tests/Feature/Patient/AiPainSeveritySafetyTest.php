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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiPainSeveritySafetyTest extends TestCase
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

    public function test_unknown_severity_with_sudden_onset_asks_one_focused_question_instead_of_prompt_evaluation(): void
    {
        $this->eligibleClinic();
        $this->doctor();
        $this->fakeClaudeSafety();

        $result = $this->service()->guide(
            'Masakit ang tiyan ko sa kaliwa sa baba for three days. Sumakit na lang siya bilga.',
        );

        $this->assertSame(AiGuidanceType::Clarification, $result->type);
        $this->assertSame(ClaudeGuidanceService::PAIN_SEVERITY_FOLLOW_UP_FILIPINO, $result->question);
        $this->assertLessThanOrEqual(1, substr_count((string) $result->question, '?'));
        $this->assertNull($result->clinicId);
        $this->assertNotSame(ClaudeGuidanceService::PROMPT_EVALUATION_MESSAGE_FILIPINO, $result->message);
        $this->assertStringNotContainsString('appendicitis', mb_strtolower((string) $result->question));
    }

    public function test_sudden_onset_mild_pain_does_not_remove_unattributed_prompt_evaluation(): void
    {
        $this->eligibleClinic();
        $this->doctor();
        $this->fakeClaudeSafety();

        $result = $this->service()->guide(
            'Masakit ang tiyan ko sa kaliwa sa baba. Sumakit na lang siya bigla. Medyo masakit.',
        );

        $this->assertSame(AiGuidanceType::Safety, $result->type);
        $this->assertSame(AiSafetyKind::PromptEvaluation, $result->safetyKind);
        $this->assertSame(ClaudeGuidanceService::PROMPT_EVALUATION_MESSAGE_FILIPINO, $result->message);
        $this->assertNull($result->clinicId);
    }

    public function test_sudden_onset_severe_abdominal_pain_stays_prompt_evaluation_without_waiting(): void
    {
        $this->eligibleClinic();
        $this->fakeClaudeRecommendation();

        $result = $this->service()->guide('Sobrang sakit ng tiyan ko sa kaliwa. Bigla lang.');

        $this->assertSame(AiGuidanceType::Safety, $result->type);
        $this->assertSame(AiSafetyKind::PromptEvaluation, $result->safetyKind);
        $this->assertSame(ClaudeGuidanceService::PROMPT_EVALUATION_MESSAGE_FILIPINO, $result->message);
        Http::assertSentCount(0);
    }

    public function test_gradual_pain_is_not_treated_as_sudden_maximum_intensity(): void
    {
        $this->eligibleClinic();
        $this->doctor();
        $this->fakeClaude([
            'type' => 'clarification',
            'question' => ClaudeGuidanceService::GENERAL_FOLLOW_UP_FILIPINO,
        ]);

        $result = $this->service()->guide('Masakit ang tiyan ko, unti-unti lang for three days sa kaliwa sa baba.');

        $this->assertSame(AiGuidanceType::Clarification, $result->type);
        $this->assertSame(ClaudeGuidanceService::PAIN_SEVERITY_FOLLOW_UP_FILIPINO, $result->question);
        $this->assertNotSame(AiGuidanceType::Safety, $result->type);
    }

    public function test_intermittent_pain_is_not_treated_as_sudden_maximum_intensity(): void
    {
        $this->eligibleClinic();
        $this->doctor();
        $this->fakeClaude([
            'type' => 'clarification',
            'question' => ClaudeGuidanceService::GENERAL_FOLLOW_UP_FILIPINO,
        ]);

        $result = $this->service()->guide('Masakit ang tiyan ko sa kaliwa sa baba for three days, bigla-bigla.');

        $this->assertSame(AiGuidanceType::Clarification, $result->type);
        $this->assertSame(ClaudeGuidanceService::PAIN_SEVERITY_FOLLOW_UP_FILIPINO, $result->question);
        $this->assertNotSame(ClaudeGuidanceService::URGENT_SAFETY_MESSAGE_FILIPINO, $result->message);
    }

    public function test_explicit_severity_rating_is_remembered_and_does_not_create_a_new_overlay_rule(): void
    {
        $this->eligibleClinic();
        $this->doctor();
        $this->fakeClaudeSafety();

        $result = $this->service()->guide('Left lower abdominal pain for three days, rated 7 out of 10.');

        $this->assertSame(AiGuidanceType::Safety, $result->type);
        $this->assertSame(AiSafetyKind::PromptEvaluation, $result->safetyKind);
        $this->assertSame(ClaudeGuidanceService::PROMPT_EVALUATION_MESSAGE, $result->message);
        Http::assertSentCount(1);

        $this->fakeClaude([
            'type' => 'clarification',
            'question' => ClaudeGuidanceService::PAIN_SEVERITY_FOLLOW_UP_ENGLISH,
        ]);

        $skipped = $this->service()->guide('Still the same pain.', new AiConversationState(
            turns: [
                ['role' => 'user', 'content' => 'Left lower abdominal pain for three days, rated 7 out of 10.'],
                ['role' => 'assistant', 'content' => ClaudeGuidanceService::PAIN_SEVERITY_FOLLOW_UP_ENGLISH],
            ],
            openingConcern: 'Left lower abdominal pain for three days, rated 7 out of 10.',
            clarificationCount: 1,
            askedQuestions: [ClaudeGuidanceService::PAIN_SEVERITY_FOLLOW_UP_ENGLISH],
        ));

        $this->assertNotSame(ClaudeGuidanceService::PAIN_SEVERITY_FOLLOW_UP_ENGLISH, $skipped->question);
    }

    public function test_informal_filipino_spelling_does_not_mean_maximum_intensity(): void
    {
        $this->eligibleClinic();
        $this->doctor();
        $this->fakeClaudeSafety();

        $result = $this->service()->guide('Masaket tiyan ko sa kaliwa sa baba for three days. Sumakit na lang siya bilga.');

        $this->assertSame(AiGuidanceType::Clarification, $result->type);
        $this->assertSame(ClaudeGuidanceService::PAIN_SEVERITY_FOLLOW_UP_FILIPINO, $result->question);
        $this->assertNull($result->safetyKind);
    }

    public function test_emergency_warning_signs_do_not_wait_for_a_severity_question(): void
    {
        $this->eligibleClinic();
        $this->fakeClaudeRecommendation();

        $result = $this->service()->guide('I have a sudden severe headache and I cannot speak.');

        $this->assertSame(AiGuidanceType::Safety, $result->type);
        $this->assertSame(AiSafetyKind::Emergency, $result->safetyKind);
        $this->assertSame(ClaudeGuidanceService::URGENT_SAFETY_MESSAGE, $result->message);
        Http::assertSentCount(0);
    }

    public function test_unattributed_cough_safety_still_becomes_prompt_evaluation(): void
    {
        $this->eligibleClinic();
        $this->fakeClaudeSafety();

        $result = $this->service()->guide('Ubo ako since kahapon.');

        $this->assertSame(AiGuidanceType::Safety, $result->type);
        $this->assertSame(AiSafetyKind::PromptEvaluation, $result->safetyKind);
        $this->assertSame(ClaudeGuidanceService::PROMPT_EVALUATION_MESSAGE_FILIPINO, $result->message);
    }

    public function test_unknown_severity_answer_does_not_remove_later_prompt_evaluation(): void
    {
        $this->eligibleClinic();
        $this->fakeClaudeSafety();

        $result = $this->service()->guide('Hindi ko alam.', new AiConversationState(
            turns: [
                ['role' => 'user', 'content' => 'Masakit ang tiyan ko sa kaliwa sa baba for three days. Sumakit na lang siya bilga.'],
                ['role' => 'assistant', 'content' => ClaudeGuidanceService::PAIN_SEVERITY_FOLLOW_UP_FILIPINO],
            ],
            openingConcern: 'Masakit ang tiyan ko sa kaliwa sa baba for three days. Sumakit na lang siya bilga.',
            clarificationCount: 1,
            askedQuestions: [ClaudeGuidanceService::PAIN_SEVERITY_FOLLOW_UP_FILIPINO],
        ));

        $this->assertSame(AiGuidanceType::Safety, $result->type);
        $this->assertSame(AiSafetyKind::PromptEvaluation, $result->safetyKind);
        $this->assertSame(ClaudeGuidanceService::PROMPT_EVALUATION_MESSAGE_FILIPINO, $result->message);
    }

    public function test_invented_safety_symptoms_are_not_repeated_in_the_follow_up(): void
    {
        $this->eligibleClinic();
        $this->doctor();
        $this->fakeClaude([
            'type' => 'safety',
            'message' => 'Dahil may panghihina kasabay ng sakit ng tiyan, magpatingin agad. This is not a diagnosis.',
        ]);

        $result = $this->service()->guide('Masakit ang tiyan ko.');

        $this->assertSame(AiGuidanceType::Clarification, $result->type);
        $question = mb_strtolower((string) $result->question);
        $this->assertStringNotContainsString('panghihina', $question);
        $this->assertStringNotContainsString('lagnat', $question);
        $this->assertStringNotContainsString('pagsusuka', $question);
        $this->assertStringNotContainsString('appendicitis', $question);
        $this->assertLessThanOrEqual(1, substr_count((string) $result->question, '?'));
    }

    public function test_headache_and_joint_pain_also_consider_missing_severity(): void
    {
        $this->eligibleClinic();
        $this->doctor();
        $this->fakeClaudeSafety();

        $headache = $this->service()->guide('Masakit ulo ko sa likod for three days. Bigla lang.');
        $this->assertSame(AiGuidanceType::Clarification, $headache->type);
        $this->assertSame(ClaudeGuidanceService::PAIN_SEVERITY_FOLLOW_UP_FILIPINO, $headache->question);

        $this->fakeClaudeSafety();
        $joint = $this->service()->guide('Hindi naman.', new AiConversationState(
            turns: [
                ['role' => 'user', 'content' => 'Masakit ang tuhod ko for three days.'],
                ['role' => 'assistant', 'content' => ClaudeGuidanceService::JOINT_INJURY_FILIPINO],
            ],
            openingConcern: 'Masakit ang tuhod ko for three days.',
            clarificationCount: 1,
            askedQuestions: [ClaudeGuidanceService::JOINT_INJURY_FILIPINO],
        ));
        $this->assertSame(AiGuidanceType::Clarification, $joint->type);
        $this->assertSame(ClaudeGuidanceService::PAIN_SEVERITY_FOLLOW_UP_FILIPINO, $joint->question);
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

    private function fakeClaudeSafety(): void
    {
        $this->fakeClaude([
            'type' => 'safety',
            'message' => 'Please seek care. This is not a diagnosis.',
        ]);
    }

    private function fakeClaudeRecommendation(): void
    {
        $this->fakeClaude([
            'type' => 'recommendation',
            'service_id' => 1,
            'reason' => 'Limited navigation to Internal Medicine.',
            'confidence' => null,
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
