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

class AiSpecialistNavigationTest extends TestCase
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

    public function test_quick_action_asks_for_a_concern_instead_of_ending_the_conversation(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'uncertain',
            'message' => 'Ayos lang po. Wala pa akong sapat na impormasyon.',
        ]);

        $response = $this->postGuidance("I'm not sure which specialist I need");

        $response->assertOk()
            ->assertJsonPath('type', 'clarification')
            ->assertJsonPath('question', ClaudeGuidanceService::OPENING_CONCERN_QUESTION_ENGLISH);

        Http::assertNothingSent();

        $stored = session(AiFrontDeskConversation::SESSION_KEY);
        $this->assertIsArray($stored);
        $this->assertNull($stored['opening_concern']);
        $this->assertSame(1, $stored['clarification_count']);
        $this->assertSame(ClaudeGuidanceService::OPENING_CONCERN_QUESTION_ENGLISH, $stored['asked_questions'][0]);
    }

    public function test_equivalent_wording_and_language_preferences_ask_one_opening_question(): void
    {
        $this->eligibleClinic();

        $english = $this->service()->guide('I do not know which doctor I should see');
        $filipino = $this->service()->guide('Hindi ko alam kung anong specialist ang kailangan ko.');
        $switched = $this->service()->guide("Magtagalog ka. I'm not sure which specialist I need");

        $this->assertSame(AiGuidanceType::Clarification, $english->type);
        $this->assertSame(ClaudeGuidanceService::OPENING_CONCERN_QUESTION_ENGLISH, $english->question);
        $this->assertSame(ClaudeGuidanceService::OPENING_CONCERN_QUESTION_FILIPINO, $filipino->question);
        $this->assertSame(ClaudeGuidanceService::OPENING_CONCERN_QUESTION_FILIPINO, $switched->question);
        Http::assertNothingSent();
    }

    public function test_answering_the_opening_question_continues_normal_guidance(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'clarification',
            'question' => ClaudeGuidanceService::HEADACHE_FOLLOW_UP_ENGLISH,
        ]);

        $this->postGuidance("I'm not sure which specialist I need")
            ->assertJsonPath('type', 'clarification');

        $this->postGuidance('I have a headache')
            ->assertOk()
            ->assertJsonPath('type', 'clarification')
            ->assertJsonPath('question', ClaudeGuidanceService::HEADACHE_FOLLOW_UP_ENGLISH);

        $stored = session(AiFrontDeskConversation::SESSION_KEY);
        $this->assertSame('I have a headache', $stored['opening_concern']);
        $this->assertSame(2, $stored['clarification_count']);
        $system = Http::recorded()[0][0]->data()['system'];
        $this->assertStringContainsString('"primary_concern":"I have a headache"', $system);
        $this->assertDoesNotMatchRegularExpression('/"opening_concern":"I\'m not sure which specialist I need"/', $system);
    }

    public function test_existing_concern_is_kept_and_only_missing_information_is_requested(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'recommendation',
            'service_id' => 1,
            'reason' => 'This is a navigation suggestion based on the listed hospital specialization.',
            'confidence' => 0.4,
        ]);

        $state = new AiConversationState(
            turns: [
                ['role' => 'user', 'content' => 'Masakit ulo ko.'],
                ['role' => 'assistant', 'content' => ClaudeGuidanceService::HEADACHE_FOLLOW_UP_FILIPINO],
            ],
            openingConcern: 'Masakit ulo ko.',
            clarificationCount: 1,
            askedQuestions: [ClaudeGuidanceService::HEADACHE_FOLLOW_UP_FILIPINO],
            replyLanguage: 'filipino',
        );

        $result = $this->service()->guide("I'm not sure which specialist I need", $state);

        $this->assertSame(AiGuidanceType::Clarification, $result->type);
        $this->assertNotSame(ClaudeGuidanceService::OPENING_CONCERN_QUESTION_ENGLISH, $result->question);
        $this->assertNotSame(ClaudeGuidanceService::HEADACHE_FOLLOW_UP_FILIPINO, $result->question);
        $this->assertStringNotContainsString('sapat na impormasyon', (string) $result->question);
        Http::assertNothingSent();

        $ready = new AiConversationState(
            turns: [
                ['role' => 'user', 'content' => 'I have a headache since yesterday.'],
            ],
            openingConcern: 'I have a headache since yesterday.',
            clarificationCount: 2,
            askedQuestions: [ClaudeGuidanceService::HEADACHE_FOLLOW_UP_ENGLISH],
            replyLanguage: 'english',
        );

        $recommendation = $this->service()->guide('Which specialist should I see?', $ready);

        $this->assertSame(
            AiGuidanceType::Recommendation,
            $recommendation->type,
            (string) ($recommendation->message ?? $recommendation->question),
        );
        $this->assertSame(2, $ready->clarificationCount);
    }

    public function test_a_plain_unknown_answer_and_emergency_wording_still_stop_ordinary_navigation(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'clarification',
            'question' => 'Where is the pain?',
        ]);

        $unknown = $this->service()->guide("I'm not sure.", [
            ['role' => 'user', 'content' => 'I have a headache'],
            ['role' => 'assistant', 'content' => 'When did the headache start?'],
        ]);

        $this->assertSame(AiGuidanceType::Uncertain, $unknown->type);
        $this->assertSame(ClaudeGuidanceService::UNABLE_TO_ROUTE_MESSAGE, $unknown->message);

        $emergency = $this->service()->guide('I have a sudden severe headache and I cannot speak.');
        $this->assertSame(AiGuidanceType::Safety, $emergency->type);
        $this->assertSame(ClaudeGuidanceService::URGENT_SAFETY_MESSAGE, $emergency->message);
    }

    private function postGuidance(string $message): \Illuminate\Testing\TestResponse
    {
        return $this->withSession([
            AiDisclaimer::SESSION_KEY => true,
        ])->postJson(route('ai-front-desk.guidance'), [
            'message' => $message,
        ]);
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
        ]);

        Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'user_id' => null,
            'display_name' => 'Test Doctor 1',
            'specialization' => 'Internal Medicine',
            'status' => DoctorStatus::Active,
            'data_source' => HospitalDataSource::Development,
        ]);

        return $clinic;
    }
}
