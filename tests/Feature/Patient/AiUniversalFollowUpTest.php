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
use App\Services\Ai\AiSuggestedDoctorService;
use App\Services\Ai\ClaudeGuidanceService;
use App\Support\AiConversationState;
use App\Support\AiDisclaimer;
use App\Support\HospitalImport\QueenMaryClinicScheduleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiUniversalFollowUpTest extends TestCase
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

    public function test_vague_concerns_across_categories_get_relevant_not_headache_fallbacks(): void
    {
        $clinic = $this->eligibleClinic();
        $this->doctor($clinic, 'Hospital Provided Doctor', 'Internal Medicine');

        $cases = [
            'Masakit ang tiyan ko.' => ['ulo', 'headache'],
            'May lagnat ako.' => ['ulo', 'headache', 'tiyan'],
            'Ubo ako.' => ['ulo', 'headache'],
            'May rash ako sa braso.' => ['ulo', 'headache'],
            'Masakit tuhod ko.' => ['ulo', 'headache'],
            'Masakit tenga ko.' => ['ulo', 'headache'],
            'Masakit mata ko.' => ['ulo', 'headache'],
            'Buntis po ako at nahihilo.' => ['ulo', 'headache'],
        ];

        foreach ($cases as $concern => $forbidden) {
            $this->fakeClaude([
                'type' => 'clarification',
                'question' => ClaudeGuidanceService::GENERAL_FOLLOW_UP_FILIPINO,
            ]);

            $result = $this->service()->guide($concern);

            $this->assertSame(AiGuidanceType::Clarification, $result->type, $concern);
            $question = mb_strtolower((string) $result->question);
            $this->assertNotSame(ClaudeGuidanceService::GENERAL_FOLLOW_UP_FILIPINO, $result->question, $concern);
            $this->assertFalse(str_contains($question, ' and '), $concern);
            $this->assertLessThanOrEqual(1, substr_count((string) $result->question, '?'), $concern);

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $question, $concern);
            }
        }
    }

    public function test_english_filipino_taglish_and_informal_spelling_keep_focused_questions(): void
    {
        $clinic = $this->eligibleClinic();
        $this->doctor($clinic, 'Hospital Provided Doctor', 'Internal Medicine');

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
        $this->assertStringNotContainsString('ulo', mb_strtolower((string) $taglish->question));
    }

    public function test_short_answers_and_negation_are_remembered_without_inventing_symptoms(): void
    {
        $clinic = $this->eligibleClinic();
        $this->doctor($clinic, 'Hospital Provided Doctor', 'Internal Medicine');

        $this->fakeClaude([
            'type' => 'clarification',
            'question' => ClaudeGuidanceService::LOCATION_FOLLOW_UP_FILIPINO,
        ]);

        $state = new AiConversationState(
            turns: [
                ['role' => 'user', 'content' => 'Masakit ang tiyan ko.'],
                ['role' => 'assistant', 'content' => ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO],
            ],
            openingConcern: 'Masakit ang tiyan ko.',
            clarificationCount: 1,
            askedQuestions: [ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO],
        );

        $result = $this->service()->guide('Sa kaliwa. Hindi naman.', $state);
        $system = Http::recorded()[0][0]->data()['system'];

        $this->assertSame(AiGuidanceType::Clarification, $result->type);
        $this->assertNotSame(ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO, $result->question);
        $this->assertStringContainsString('Sa kaliwa. Hindi naman.', $system);
        $this->assertStringContainsString('"opening_concern":"Masakit ang tiyan ko."', $system);
        $this->assertStringNotContainsString('weakness', mb_strtolower((string) $result->question));
        $this->assertStringNotContainsString('panghihina', mb_strtolower((string) $result->question));
    }

    public function test_unknown_answers_are_not_treated_as_denied_warning_signs(): void
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
        $this->assertStringNotContainsString('walang warning', mb_strtolower((string) $result->message));
    }

    public function test_intermittent_wording_does_not_force_emergency_and_clear_emergencies_still_do(): void
    {
        $clinic = $this->eligibleClinic();
        $this->doctor($clinic, 'Hospital Provided Doctor', 'Internal Medicine');

        $this->fakeClaude([
            'type' => 'recommendation',
            'service_id' => 1,
            'reason' => 'Should not book during a possible emergency.',
            'confidence' => null,
        ]);

        $intermittent = $this->service()->guide('Sobrang sakit ng ulo bigla-bigla since last week.');
        $this->assertNotSame(AiGuidanceType::Safety, $intermittent->type);

        $emergency = $this->service()->guide('I have a sudden severe headache and I cannot speak.');
        $this->assertSame(AiGuidanceType::Safety, $emergency->type);
        $this->assertSame(ClaudeGuidanceService::URGENT_SAFETY_MESSAGE, $emergency->message);
        $this->assertNull($emergency->clinicId);
    }

    public function test_unattributed_model_safety_is_not_suppressed(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'safety',
            'message' => 'Please seek emergency help now if this may be an emergency. This is not a diagnosis.',
        ]);

        $result = $this->service()->guide('Pakiramdam ko parang mag-collapse ako.');

        $this->assertSame(AiGuidanceType::Safety, $result->type);
        $this->assertSame(ClaudeGuidanceService::URGENT_SAFETY_MESSAGE_FILIPINO, $result->message);
        $this->assertNull($result->clinicId);
    }

    public function test_attributed_model_safety_is_not_trusted_as_a_patient_report(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'safety',
            'message' => 'Dahil mayroon kang panghihina kasabay ng ubo, magpatingin agad.',
        ]);

        $result = $this->service()->guide('Ubo ako.');

        $this->assertSame(AiGuidanceType::Clarification, $result->type);
        $this->assertStringNotContainsString('panghihina', (string) $result->question);
        $this->assertStringNotContainsString('ulo', mb_strtolower((string) $result->question));
    }

    public function test_vague_recommendation_is_deferred_but_detailed_concerns_are_not_forced_through_a_questionnaire(): void
    {
        $clinic = $this->eligibleClinic();
        $this->doctor($clinic, 'Hospital Provided Doctor', 'Internal Medicine');

        $this->fakeClaude([
            'type' => 'recommendation',
            'service_id' => 1,
            'reason' => 'Limited navigation to Internal Medicine.',
            'confidence' => null,
        ]);

        $vague = $this->service()->guide('May lagnat ako.');
        $this->assertSame(AiGuidanceType::Clarification, $vague->type);
        $this->assertNotNull($vague->question);

        $this->fakeClaude([
            'type' => 'recommendation',
            'service_id' => 1,
            'reason' => 'Limited navigation to Internal Medicine.',
            'confidence' => null,
        ]);
        $enough = $this->service()->guide('May lagnat ako since kahapon.');
        $this->assertSame(AiGuidanceType::Recommendation, $enough->type);
        $this->assertSame(1, $enough->serviceId);
    }

    public function test_four_clarification_cap_and_repeated_questions_remain_effective(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'clarification',
            'question' => 'May iba ka pa bang gusto idagdag?',
        ]);

        $capped = $this->service()->guide('Medyo lang.', new AiConversationState(
            turns: [
                ['role' => 'user', 'content' => 'Masakit ang tiyan ko.'],
                ['role' => 'assistant', 'content' => 'Gaano na katagal?'],
            ],
            openingConcern: 'Masakit ang tiyan ko.',
            clarificationCount: AiConversationState::MAX_CLARIFICATION_ATTEMPTS,
            askedQuestions: ['Gaano na katagal?', 'Saan po?', 'Biglaan po ba?', 'May iba pa?'],
        ));

        $this->assertSame(AiGuidanceType::Uncertain, $capped->type);
        $this->assertNull($capped->clinicId);

        $this->fakeClaude([
            'type' => 'clarification',
            'question' => ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO,
        ]);
        $repeated = $this->service()->guide('Sa kaliwa.', [
            ['role' => 'user', 'content' => 'Masakit ang tiyan ko.'],
            ['role' => 'assistant', 'content' => ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO],
        ]);
        $this->assertSame(AiGuidanceType::Clarification, $repeated->type);
        $this->assertNotSame(ClaudeGuidanceService::FOCUSED_GENERAL_FOLLOW_UP_FILIPINO, $repeated->question);
    }

    public function test_suggested_doctors_hide_mapping_labels_and_do_not_repeat_unavailable_copy(): void
    {
        config(['hospital.data_source' => HospitalDataSource::Imported->value]);

        $department = Department::query()->create([
            'name' => QueenMaryClinicScheduleCatalog::PENDING_DEPARTMENT_NAME,
            'description' => 'Temporary grouping.',
            'status' => DepartmentStatus::Active,
            'data_source' => HospitalDataSource::Imported,
        ]);
        $clinic = Clinic::query()->create([
            'department_id' => $department->id,
            'name' => 'Internal Medicine',
            'description' => 'Imported from the supplied clinic-schedule poster for this specialization. Mapping to an official hospital clinic requires confirmation. Not hospital-verified.',
            'status' => ClinicStatus::Active,
            'data_source' => HospitalDataSource::Imported,
        ]);
        $this->doctor($clinic, 'Dr. Endaya, Hazel Anne', 'Internal Medicine', HospitalDataSource::Imported);

        $this->fakeClaude([
            'type' => 'recommendation',
            'service_id' => 1,
            'reason' => 'This is a navigation suggestion based on the listed Internal Medicine specialization.',
            'confidence' => null,
        ]);

        $response = $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->postJson(route('ai-front-desk.guidance'), [
                'message' => 'Masakit ang tiyan ko sa kaliwa sa itaas since kahapon.',
            ]);

        $response->assertOk()
            ->assertJsonPath('type', 'recommendation')
            ->assertJsonPath('doctors.0.display_name', 'Dr. Endaya, Hazel Anne')
            ->assertJsonPath('doctors.0.specialization', 'Internal Medicine')
            ->assertJsonPath('doctors.0.clinic', null)
            ->assertJsonPath('doctors.0.department', null)
            ->assertJsonPath('doctors.0.availability', null)
            ->assertJsonPath('doctors.0.bookable', false)
            ->assertJsonPath('all_unavailable', true)
            ->assertJsonPath('message', AiSuggestedDoctorService::NO_ONLINE_SLOTS_MESSAGE)
            ->assertJsonMissingPath('doctor_id');

        $this->assertStringNotContainsString('Pending hospital mapping', json_encode($response->json('doctors')));

        $js = file_get_contents(public_path('js/ai-front-desk.js'));
        $page = $this->withSession([AiDisclaimer::SESSION_KEY => true])->get(route('ai-front-desk'));

        $this->assertIsString($js);
        $this->assertStringContainsString('allUnavailable', $js);
        $this->assertStringContainsString('pending hospital mapping', $js);
        $page->assertSee('Suggested Doctors');
        $page->assertSee('Specialists who may match this hospital navigation suggestion.');
        $page->assertDontSee('Based on your concern and answers, these doctors may be relevant to your consultation needs.');
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

    private function doctor(
        Clinic $clinic,
        string $displayName,
        string $specialization,
        HospitalDataSource $dataSource = HospitalDataSource::Development,
    ): Doctor {
        return Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'display_name' => $displayName,
            'specialization' => $specialization,
            'status' => DoctorStatus::Active,
            'data_source' => $dataSource,
        ]);
    }
}
