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
use App\Support\AiDisclaimer;
use App\Support\AiFrontDeskConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AiRecommendationReadinessGuidanceTest extends TestCase
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

    public function test_chronic_back_pain_with_duration_and_severity_still_asks_precise_location(): void
    {
        $this->eligibleClinic();
        $this->doctor();
        $this->fakeRecommendation();

        $result = $this->service()->guide(
            'hmmm di naman siya ganon kasaket parang mabigat lang or parang ngalay',
            $this->backStateAfterDurationAndSeverity(),
        );

        $this->assertSame(AiGuidanceType::Clarification, $result->type);
        $this->assertSame(ClaudeGuidanceService::BACK_LOCATION_FOLLOW_UP_FILIPINO, $result->question);
        $this->assertNull($result->clinicId);
        $this->assertNull($result->serviceId);
        $this->assertLessThanOrEqual(1, substr_count((string) $result->question, '?'));
        $this->assertStringNotContainsString('strain', mb_strtolower((string) $result->question));
        $this->assertStringNotContainsString('scoliosis', mb_strtolower((string) $result->question));
    }

    public function test_back_pain_with_precise_location_can_recommend_a_validated_service(): void
    {
        $this->eligibleClinic();
        $this->doctor();
        $this->fakeRecommendation();

        $result = $this->service()->guide('Masakit ang lower back ko for two months. Medyo lang.');

        $this->assertSame(AiGuidanceType::Recommendation, $result->type);
        $this->assertSame(1, $result->serviceId);
        $this->assertNull($result->clinicId);
    }

    public function test_valid_service_id_does_not_bypass_readiness(): void
    {
        $this->eligibleClinic();
        $this->doctor();
        $this->fakeRecommendation();

        $result = $this->service()->guide('ilang buwan na ren e', new AiConversationState(
            turns: [
                ['role' => 'user', 'content' => 'hello! ang saket ng likod ko'],
                ['role' => 'assistant', 'content' => ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO],
            ],
            openingConcern: 'hello! ang saket ng likod ko',
            clarificationCount: 1,
            askedQuestions: [ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO],
            replyLanguage: 'filipino',
        ));

        $this->assertSame(AiGuidanceType::Clarification, $result->type);
        $this->assertSame(ClaudeGuidanceService::BACK_LOCATION_FOLLOW_UP_FILIPINO, $result->question);
        $this->assertNull($result->serviceId);
    }

    public function test_concerns_with_enough_routing_detail_are_not_forced_through_a_questionnaire(): void
    {
        $this->eligibleClinic();
        $this->doctor();

        $cases = [
            'Masakit ulo ko sa bandang likod since yesterday.' => true,
            'Masakit ang tuhod ko.' => true,
            'May rash ako sa braso.' => true,
            'Masakit tenga ko since kahapon.' => true,
            'Masakit mata ko since kahapon.' => true,
            'I have a mild cough since yesterday.' => true,
            'May lagnat ako since kahapon.' => true,
            'Nahihilo ako since this morning.' => true,
            'Masakit ang tiyan ko sa kaliwa sa itaas since yesterday.' => true,
            'Dalawang araw na sumasakit ang ulo ko at medyo nahihilo ako.' => true,
            'Masakit ang tiyan ko sa kanan sa baba for three days.' => false,
            'Masakit ang tiyan ko sa kanan.' => false,
            'May ubo ako.' => false,
            'May lagnat ako.' => false,
            'hello! ang saket ng likod ko' => false,
            'May ubo ako at masakit ang lalamunan.' => false,
        ];

        foreach ($cases as $concern => $ready) {
            $this->fakeRecommendation();
            $result = $this->service()->guide($concern);

            if ($ready) {
                $this->assertSame(AiGuidanceType::Recommendation, $result->type, $concern);
                $this->assertSame(1, $result->serviceId, $concern);
            } else {
                $this->assertNotSame(AiGuidanceType::Recommendation, $result->type, $concern);
                $this->assertNull($result->serviceId, $concern);
                if ($result->type === AiGuidanceType::Clarification) {
                    $this->assertLessThanOrEqual(1, substr_count((string) $result->question, '?'));
                    $this->assertNotSame(ClaudeGuidanceService::GENERAL_FOLLOW_UP_FILIPINO, $result->question);
                    $this->assertNotSame(ClaudeGuidanceService::GENERAL_FOLLOW_UP_ENGLISH, $result->question);
                }
            }
        }
    }

    public function test_four_clarification_cap_does_not_force_a_recommendation(): void
    {
        $this->eligibleClinic();
        $this->doctor();
        $this->fakeRecommendation();

        $result = $this->service()->guide('Hmm.', new AiConversationState(
            turns: [
                ['role' => 'user', 'content' => 'hello! ang saket ng likod ko'],
                ['role' => 'assistant', 'content' => ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO],
            ],
            openingConcern: 'hello! ang saket ng likod ko',
            clarificationCount: AiConversationState::MAX_CLARIFICATION_ATTEMPTS,
            askedQuestions: [
                ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO,
                ClaudeGuidanceService::PAIN_SEVERITY_FOLLOW_UP_FILIPINO,
                ClaudeGuidanceService::ASSOCIATED_FOLLOW_UP_FILIPINO,
                ClaudeGuidanceService::ONSET_FOLLOW_UP_FILIPINO,
            ],
            replyLanguage: 'filipino',
        ));

        $this->assertSame(AiGuidanceType::Uncertain, $result->type);
        $this->assertSame(ClaudeGuidanceService::UNABLE_TO_ROUTE_MESSAGE_FILIPINO, $result->message);
        $this->assertNull($result->serviceId);
    }

    public function test_language_persistence_and_no_repeated_duration_on_the_live_path(): void
    {
        $this->eligibleClinic();
        $this->doctor();
        $this->fakeClaude([
            'type' => 'clarification',
            'question' => ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_ENGLISH,
        ]);

        $result = $this->service()->guide('ilang buwan na ren e', new AiConversationState(
            turns: [
                ['role' => 'user', 'content' => 'hello! ang saket ng likod ko'],
                ['role' => 'assistant', 'content' => ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO],
            ],
            openingConcern: 'hello! ang saket ng likod ko',
            clarificationCount: 1,
            askedQuestions: [ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO],
            replyLanguage: 'filipino',
        ));

        $this->assertSame(ClaudeGuidanceService::BACK_LOCATION_FOLLOW_UP_FILIPINO, $result->question);
        $this->assertNotSame(ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_ENGLISH, $result->question);
        $this->assertNotSame(ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO, $result->question);
    }

    public function test_english_detailed_cough_still_recommends(): void
    {
        $this->eligibleClinic();
        $this->doctor();
        $this->fakeRecommendation();

        $enough = $this->service()->guide('I have a mild cough since yesterday. No fever and I can breathe normally.');
        $this->assertSame(AiGuidanceType::Recommendation, $enough->type);
        $this->assertSame(1, $enough->serviceId);
    }

    public function test_invalid_service_id_remains_rejected(): void
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

    public function test_safety_overlays_override_recommendation_and_are_not_delayed(): void
    {
        $this->eligibleClinic();
        $this->fakeRecommendation();

        $emergency = $this->service()->guide('I have a sudden severe headache and I cannot speak.');
        $this->assertSame(AiGuidanceType::Safety, $emergency->type);
        $this->assertSame(AiSafetyKind::Emergency, $emergency->safetyKind);
        Http::assertSentCount(0);

        $this->fakeRecommendation();
        $prompt = $this->service()->guide('Masakit ang tiyan ko sa kanan sa baba for 3 days.');
        $this->assertSame(AiGuidanceType::Safety, $prompt->type);
        $this->assertSame(AiSafetyKind::PromptEvaluation, $prompt->safetyKind);
        $this->assertNull($prompt->clinicId);
    }

    public function test_http_live_back_path_asks_location_before_catalog_doctors(): void
    {
        $doctor = $this->doctor();
        $this->fakeRecommendation();

        $this->withSession([
            AiDisclaimer::SESSION_KEY => true,
            AiFrontDeskConversation::SESSION_KEY => $this->backStateAfterDurationAndSeverity()->toEnvelope(),
        ])->postJson(route('ai-front-desk.guidance'), [
            'message' => 'hmmm di naman siya ganon kasaket parang mabigat lang or parang ngalay',
        ])
            ->assertOk()
            ->assertJsonPath('type', 'clarification')
            ->assertJsonPath('question', ClaudeGuidanceService::BACK_LOCATION_FOLLOW_UP_FILIPINO)
            ->assertJsonMissingPath('doctors');

        $this->fakeRecommendation();
        $response = $this->postJson(route('ai-front-desk.guidance'), [
            'message' => 'Sa bandang ibaba.',
        ]);

        $response->assertOk()
            ->assertJsonPath('type', 'recommendation')
            ->assertJsonPath('doctors.0.id', $doctor->id)
            ->assertJsonPath('doctors.0.display_name', $doctor->display_name)
            ->assertJsonPath('doctors.0.next_available', null)
            ->assertJsonPath('booking_intent_url', route('ai-front-desk.booking-intent'))
            ->assertJsonPath('book_appointment_url', route('patient.book-appointment'))
            ->assertJsonMissingPath('doctor_id');
        $this->assertStringNotContainsString('Dr. Invented', (string) $response->getContent());
        $this->assertStringNotContainsString('Oct 99', (string) $response->getContent());
    }

    public function test_incomplete_abdominal_quadrant_asks_one_focused_follow_up(): void
    {
        $this->eligibleClinic();
        $this->doctor();
        $this->fakeRecommendation();

        $result = $this->service()->guide('Sa kanan.', new AiConversationState(
            turns: [
                ['role' => 'user', 'content' => 'Masakit ang tiyan ko.'],
                ['role' => 'assistant', 'content' => ClaudeGuidanceService::LOCATION_FOLLOW_UP_FILIPINO],
            ],
            openingConcern: 'Masakit ang tiyan ko.',
            clarificationCount: 1,
            askedQuestions: [ClaudeGuidanceService::LOCATION_FOLLOW_UP_FILIPINO],
            replyLanguage: 'filipino',
        ));

        $this->assertSame(AiGuidanceType::Clarification, $result->type);
        $this->assertSame(ClaudeGuidanceService::ABDOMINAL_QUADRANT_FOLLOW_UP_FILIPINO, $result->question);
        $this->assertLessThanOrEqual(1, substr_count((string) $result->question, '?'));
        $this->assertNull($result->serviceId);
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

    private function fakeRecommendation(): void
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

    private function backStateAfterDurationAndSeverity(): AiConversationState
    {
        return new AiConversationState(
            turns: [
                ['role' => 'user', 'content' => 'hello! ang saket ng likod ko'],
                ['role' => 'assistant', 'content' => ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO],
                ['role' => 'user', 'content' => 'ilang buwan na ren e'],
                ['role' => 'assistant', 'content' => 'Gaano po kalakas ang sakit ng likod ninyo — mild lang ba, o medyo malala na?'],
            ],
            openingConcern: 'hello! ang saket ng likod ko',
            clarificationCount: 2,
            askedQuestions: [
                ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO,
                'Gaano po kalakas ang sakit ng likod ninyo — mild lang ba, o medyo malala na?',
            ],
            replyLanguage: 'filipino',
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

    private function postGuidance(string $message): TestResponse
    {
        return $this->withSession([
            AiDisclaimer::SESSION_KEY => true,
        ])->postJson(route('ai-front-desk.guidance'), [
            'message' => $message,
        ]);
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
