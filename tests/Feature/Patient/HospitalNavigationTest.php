<?php

namespace Tests\Feature\Patient;

use App\Enums\ClinicStatus;
use App\Enums\DayOfWeek;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorScheduleStatus;
use App\Enums\DoctorStatus;
use App\Enums\HospitalDataSource;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Services\Ai\ClaudeGuidanceService;
use App\Services\Ai\HospitalNavigationResolver;
use App\Support\AiDisclaimer;
use App\Support\AiGuidanceResult;
use App\Support\HospitalImport\QueenMaryClinicScheduleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HospitalNavigationTest extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_routine_recommendation_is_clinic_first_and_uses_a_real_slot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-11 08:00:00', 'Asia/Manila'));
        $clinic = $this->clinic('Development Clinic A', 'Development Department A');
        $doctor = $this->doctor($clinic, 'Dr. Listed', 'Pediatrician');
        $this->schedule($doctor);
        $before = $this->recordCounts();

        $response = $this->postGuidance('I have had a headache since yesterday.', [
            'type' => 'recommendation',
            'service_id' => 1,
            'reason' => 'Pediatrician is a listed hospital specialization that may be relevant.',
            'confidence' => null,
        ]);

        $response->assertOk()
            ->assertJsonPath('type', 'recommendation')
            ->assertJsonPath('specialization', 'Pediatrician')
            ->assertJsonPath('navigation.status', HospitalNavigationResolver::STATUS_LISTED_SERVICE)
            ->assertJsonPath('navigation.service_label', 'Development Clinic A')
            ->assertJsonPath('navigation.temporary_mapping', true)
            ->assertJsonPath('navigation.booking_permitted', true)
            ->assertJsonPath('navigation.note', HospitalNavigationResolver::LISTED_NOTE_ENGLISH)
            ->assertJsonPath('doctors.0.display_name', 'Dr. Listed')
            ->assertJsonPath('doctors.0.bookable', true);

        $this->assertNotNull($response->json('doctors.0.next_available'));
        $this->assertSame($before, $this->recordCounts());
        $this->assertNoInventedContact($response->json('navigation'));
    }

    public function test_prompt_evaluation_keeps_safety_text_and_adds_staff_navigation_without_booking(): void
    {
        $clinic = $this->verifiedClinic('General Medicine Clinic', 'Internal Medicine');
        $this->doctor($clinic, 'Dr. Verified', 'Internal Medicine', HospitalDataSource::Verified);
        $before = $this->recordCounts();

        $response = $this->postGuidance('Masakit ang tiyan ko sa kanan sa baba for 3 days.', [
            'type' => 'recommendation',
            'service_id' => 1,
            'reason' => 'This reason must not be shown.',
            'confidence' => null,
        ]);

        $response->assertOk()
            ->assertJsonPath('type', 'safety')
            ->assertJsonPath('safety_kind', 'prompt_evaluation')
            ->assertJsonPath('message', ClaudeGuidanceService::PROMPT_EVALUATION_MESSAGE_FILIPINO)
            ->assertJsonPath('navigation.status', HospitalNavigationResolver::STATUS_STAFF_ASSISTANCE)
            ->assertJsonPath('navigation.heading', HospitalNavigationResolver::WHERE_TO_GO_FILIPINO)
            ->assertJsonPath('navigation.guidance', HospitalNavigationResolver::PROMPT_GUIDANCE_FILIPINO)
            ->assertJsonPath('navigation.booking_permitted', false)
            ->assertJsonPath('navigation.service_label', null)
            ->assertJsonMissingPath('doctors')
            ->assertJsonMissingPath('clinic')
            ->assertJsonMissingPath('book_appointment_url');

        $this->assertStringNotContainsString('General Medicine Clinic', json_encode($response->json('navigation'), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('Internal Medicine', json_encode($response->json('navigation'), JSON_THROW_ON_ERROR));
        $this->assertSame(0, Http::recorded()->count());
        $this->assertSame($before, $this->recordCounts());
        $this->assertNoInventedContact($response->json('navigation'));
    }

    public function test_emergency_navigation_does_not_offer_a_clinic_or_delay_care(): void
    {
        $this->verifiedClinic('General Medicine Clinic', 'Internal Medicine');

        $english = $this->postGuidance('I have a sudden severe headache.', [
            'type' => 'recommendation',
            'service_id' => 1,
            'reason' => 'Must not be shown.',
            'confidence' => null,
        ]);

        $english->assertOk()
            ->assertJsonPath('type', 'safety')
            ->assertJsonPath('safety_kind', 'emergency')
            ->assertJsonPath('message', ClaudeGuidanceService::URGENT_SAFETY_MESSAGE)
            ->assertJsonPath('navigation.heading', HospitalNavigationResolver::WHERE_TO_GO_ENGLISH)
            ->assertJsonPath('navigation.guidance', HospitalNavigationResolver::EMERGENCY_GUIDANCE_ENGLISH)
            ->assertJsonPath('navigation.booking_permitted', false)
            ->assertJsonPath('navigation.service_label', null)
            ->assertJsonMissingPath('doctors');

        $this->assertNoInventedContact($english->json('navigation'));

        $filipino = $this->postGuidance('Biglaang sobrang sakit ng ulo.', [
            'type' => 'recommendation',
            'service_id' => 1,
            'reason' => 'Must not be shown.',
            'confidence' => null,
        ]);

        $filipino->assertJsonPath('message', ClaudeGuidanceService::URGENT_SAFETY_MESSAGE_FILIPINO)
            ->assertJsonPath('navigation.guidance', HospitalNavigationResolver::EMERGENCY_GUIDANCE_FILIPINO)
            ->assertJsonPath('navigation.booking_permitted', false)
            ->assertJsonMissingPath('doctors');
    }

    public function test_uncertain_navigation_does_not_invent_a_clinic(): void
    {
        $this->clinic('Development Clinic A', 'Development Department A');
        $this->doctor($this->latestClinic(), 'Dr. Listed', 'Pediatrician');

        $response = $this->postGuidance('Hindi ko alam.', [
            'type' => 'uncertain',
            'message' => 'This model message should be replaced when the visitor does not know.',
        ]);

        $response->assertOk()
            ->assertJsonPath('type', 'uncertain')
            ->assertJsonPath('navigation.status', HospitalNavigationResolver::STATUS_STAFF_ASSISTANCE)
            ->assertJsonPath('navigation.heading', HospitalNavigationResolver::UNCERTAIN_HEADING_FILIPINO)
            ->assertJsonPath('navigation.guidance', HospitalNavigationResolver::UNCERTAIN_GUIDANCE_FILIPINO)
            ->assertJsonPath('navigation.service_label', null)
            ->assertJsonPath('navigation.booking_permitted', false)
            ->assertJsonMissingPath('clinic')
            ->assertJsonMissingPath('doctors');

        $this->assertNoInventedContact($response->json('navigation'));
    }

    public function test_invalid_inactive_and_unverified_records_do_not_become_destinations(): void
    {
        $inactiveClinic = $this->clinic('Closed Clinic', 'Open Department', clinicStatus: ClinicStatus::Inactive);
        $inactiveDepartment = $this->clinic('Open Clinic', 'Closed Department', departmentStatus: DepartmentStatus::Inactive);
        $this->clinic('Pending Group', QueenMaryClinicScheduleCatalog::PENDING_DEPARTMENT_NAME, HospitalDataSource::Imported);
        $pending = $this->latestClinic();
        $this->doctor($pending, 'Dr. Poster', 'Pediatrician', HospitalDataSource::Imported);

        $resolver = app(HospitalNavigationResolver::class);

        foreach ([$inactiveClinic, $inactiveDepartment] as $clinic) {
            $navigation = $resolver->forRecommendation(AiGuidanceResult::fromPayload([
                'type' => 'recommendation',
                'clinic_id' => $clinic->id,
                'reason' => 'This clinic should not be offered.',
            ]), false);

            $this->assertSame(HospitalNavigationResolver::STATUS_STAFF_ASSISTANCE, $navigation['status']);
            $this->assertNull($navigation['service_label']);
            $this->assertFalse($navigation['booking_permitted']);
        }

        $missing = $resolver->forRecommendation(AiGuidanceResult::fromPayload([
            'type' => 'recommendation',
            'clinic_id' => 999999,
            'reason' => 'This clinic does not exist.',
        ]), false);

        $this->assertSame(HospitalNavigationResolver::STATUS_STAFF_ASSISTANCE, $missing['status']);
        $this->assertNull($missing['service_label']);
        $this->assertArrayNotHasKey('clinic_id', $missing);

        config(['hospital.data_source' => HospitalDataSource::Imported->value]);

        $imported = $this->postGuidance('I have had a headache since yesterday.', [
            'type' => 'recommendation',
            'service_id' => 1,
            'reason' => 'Pediatrician is only a temporary grouping.',
            'confidence' => null,
        ]);

        $imported->assertOk()
            ->assertJsonPath('navigation.status', HospitalNavigationResolver::STATUS_LISTED_SERVICE)
            ->assertJsonPath('navigation.service_label', 'Pediatrician')
            ->assertJsonPath('navigation.temporary_mapping', true)
            ->assertJsonPath('doctors.0.display_name', 'Dr. Poster')
            ->assertJsonPath('doctors.0.bookable', false)
            ->assertJsonPath('navigation.booking_permitted', false);

        $encoded = json_encode($imported->json('navigation'), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Pending hospital mapping', $encoded);
        $this->assertStringNotContainsString('Closed Clinic', $encoded);
        $this->assertNoInventedContact($imported->json('navigation'));
    }

    public function test_invalid_service_id_stays_uncertain_without_a_destination(): void
    {
        $this->clinic('Development Clinic A', 'Development Department A');
        $this->doctor($this->latestClinic(), 'Dr. Listed', 'Pediatrician');

        $response = $this->postGuidance('I have had a headache since yesterday.', [
            'type' => 'recommendation',
            'service_id' => 999999,
            'reason' => 'This service id is not in the catalog.',
            'confidence' => null,
        ]);

        $response->assertOk()
            ->assertJsonPath('type', 'uncertain')
            ->assertJsonPath('message', ClaudeGuidanceService::INSUFFICIENT_HOSPITAL_INFORMATION_MESSAGE)
            ->assertJsonPath('navigation.status', HospitalNavigationResolver::STATUS_STAFF_ASSISTANCE)
            ->assertJsonPath('navigation.heading', HospitalNavigationResolver::UNCERTAIN_HEADING_ENGLISH)
            ->assertJsonPath('navigation.service_label', null)
            ->assertJsonMissingPath('clinic')
            ->assertJsonMissingPath('doctors');
    }

    public function test_verified_service_is_labeled_without_becoming_urgent_routing(): void
    {
        $clinic = $this->verifiedClinic('General Medicine Clinic', 'Internal Medicine');
        $doctor = $this->doctor($clinic, 'Dr. Verified', 'Internal Medicine', HospitalDataSource::Verified);
        $this->schedule($doctor);

        $routine = $this->postGuidance('I have had a headache since yesterday.', [
            'type' => 'recommendation',
            'service_id' => 1,
            'reason' => 'Internal Medicine is a verified hospital service that may be relevant.',
            'confidence' => null,
        ]);

        $routine->assertOk()
            ->assertJsonPath('navigation.status', HospitalNavigationResolver::STATUS_VERIFIED_DESTINATION)
            ->assertJsonPath('navigation.service_label', 'General Medicine Clinic')
            ->assertJsonPath('navigation.temporary_mapping', false)
            ->assertJsonPath('navigation.booking_permitted', true)
            ->assertJsonPath('doctors.0.bookable', true);

        $this->assertNoInventedContact($routine->json('navigation'));

        $safety = app(HospitalNavigationResolver::class)->forSafety(
            \App\Enums\AiSafetyKind::PromptEvaluation,
            false,
        );

        $this->assertSame(HospitalNavigationResolver::STATUS_STAFF_ASSISTANCE, $safety['status']);
        $this->assertNull($safety['service_label']);
        $this->assertFalse($safety['booking_permitted']);
        $this->assertStringNotContainsString('General Medicine', (string) $safety['guidance']);
    }

    public function test_taglish_and_filipino_navigation_follow_the_visitor_language(): void
    {
        $clinic = $this->clinic('Development Clinic A', 'Development Department A');
        $this->doctor($clinic, 'Dr. Listed', 'Pediatrician');

        $filipino = $this->postGuidance('Masakit ang ulo ko nang tatlong araw na.', [
            'type' => 'recommendation',
            'service_id' => 1,
            'reason' => 'Pediatrician may be relevant.',
            'confidence' => null,
        ]);

        $filipino->assertOk()
            ->assertJsonPath('navigation.note', HospitalNavigationResolver::LISTED_NOTE_FILIPINO);

        $taglish = $this->postGuidance('My ulo hurts since yesterday.', [
            'type' => 'recommendation',
            'service_id' => 1,
            'reason' => 'Pediatrician may be relevant.',
            'confidence' => null,
        ]);

        $taglish->assertOk()
            ->assertJsonPath('navigation.note', HospitalNavigationResolver::LISTED_NOTE_FILIPINO);
    }

    public function test_navigation_copy_and_frontend_do_not_add_booking_to_safety_cards(): void
    {
        $resolver = file_get_contents(app_path('Services/Ai/HospitalNavigationResolver.php'));
        $js = file_get_contents(public_path('js/ai-front-desk.js'));
        $page = $this->withSession([AiDisclaimer::SESSION_KEY => true])->get(route('ai-front-desk'));

        $this->assertIsString($resolver);
        $this->assertStringNotContainsString('nagsusuka', mb_strtolower($resolver));
        $this->assertDoesNotMatchRegularExpression('/\b(09\d{9}|\+63)\b/', $resolver);

        $this->assertIsString($js);
        $this->assertStringContainsString('fillWhere(row, payload && typeof payload === \'object\' ? payload.navigation : null);', $js);
        $this->assertStringContainsString('navigation?.booking_permitted !== false', $js);

        $page->assertOk();
        $page->assertSee('data-mg-where', false);
        $page->assertSee('data-mg-service-heading', false);
        $page->assertDontSee('data-mg-ask-staff', false);
        $page->assertDontSee('Ask Hospital Staff', false);
        $this->assertStringContainsString('not a diagnosis', HospitalNavigationResolver::LISTED_NOTE_ENGLISH);
        $this->assertStringContainsString('hindi diagnosis', HospitalNavigationResolver::LISTED_NOTE_FILIPINO);
        $this->assertSame(1, preg_match(
            '/<template data-mg-tpl="safety">(?<html>.*?)<\/template>/s',
            $page->getContent(),
            $matches,
        ));
        $this->assertStringNotContainsString('data-mg-book-appointment', $matches['html']);
        $this->assertStringNotContainsString('View Available Appointments', $matches['html']);
    }

    /**
     * @param  array<string, mixed>  $guidance
     */
    private function postGuidance(string $message, array $guidance)
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

        return $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->postJson(route('ai-front-desk.guidance'), [
                'message' => $message,
            ]);
    }

    private function clinic(
        string $name,
        string $departmentName,
        HospitalDataSource $source = HospitalDataSource::Development,
        ClinicStatus $clinicStatus = ClinicStatus::Active,
        DepartmentStatus $departmentStatus = DepartmentStatus::Active,
    ): Clinic {
        return Clinic::query()->create([
            'department_id' => Department::query()->create([
                'name' => $departmentName,
                'description' => 'Department record for navigation tests.',
                'status' => $departmentStatus,
                'data_source' => $source,
            ])->id,
            'name' => $name,
            'description' => $source === HospitalDataSource::Imported
                ? 'Temporary grouping imported from the supplied clinic-schedule poster.'
                : 'Placeholder clinic for local development. Not hospital data.',
            'status' => $clinicStatus,
            'data_source' => $source,
        ]);
    }

    private function verifiedClinic(string $name, string $departmentName): Clinic
    {
        return Clinic::query()->create([
            'department_id' => Department::query()->create([
                'name' => $departmentName,
                'description' => 'Hospital reviewed department record.',
                'status' => DepartmentStatus::Active,
                'data_source' => HospitalDataSource::Verified,
            ])->id,
            'name' => $name,
            'description' => 'Hospital reviewed outpatient service.',
            'status' => ClinicStatus::Active,
            'data_source' => HospitalDataSource::Verified,
        ]);
    }

    private function doctor(
        Clinic $clinic,
        string $name,
        string $specialization,
        HospitalDataSource $source = HospitalDataSource::Development,
    ): Doctor {
        return Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'display_name' => $name,
            'specialization' => $specialization,
            'status' => DoctorStatus::Active,
            'data_source' => $source,
        ]);
    }

    private function schedule(Doctor $doctor): void
    {
        DoctorSchedule::query()->create([
            'doctor_id' => $doctor->id,
            'day_of_week' => DayOfWeek::Wednesday,
            'start_time' => '13:00:00',
            'end_time' => '14:00:00',
            'slot_duration' => 30,
            'status' => DoctorScheduleStatus::Active,
            'provisional_demonstration' => true,
        ]);
    }

    private function latestClinic(): Clinic
    {
        $clinic = Clinic::query()->orderByDesc('id')->first();
        $this->assertInstanceOf(Clinic::class, $clinic);

        return $clinic;
    }

    /**
     * @return array{patients: int, appointments: int}
     */
    private function recordCounts(): array
    {
        return [
            'patients' => Patient::query()->count(),
            'appointments' => Appointment::query()->count(),
        ];
    }

    private function assertNoInventedContact(mixed $navigation): void
    {
        $encoded = mb_strtolower(json_encode($navigation, JSON_THROW_ON_ERROR));

        $this->assertDoesNotMatchRegularExpression('/\b\d{3}[-.\s]?\d{3,4}\b/', $encoded);
        $this->assertStringNotContainsString('telephone', $encoded);
        $this->assertStringNotContainsString('phone', $encoded);
        $this->assertStringNotContainsString('cardona', $encoded);
        $this->assertStringNotContainsString('room ', $encoded);
    }
}
