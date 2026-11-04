<?php

namespace Tests\Feature\Patient;

use App\Enums\AppointmentStatus;
use App\Enums\ClinicStatus;
use App\Enums\DayOfWeek;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorScheduleStatus;
use App\Enums\DoctorStatus;
use App\Enums\HospitalDataSource;
use App\Enums\RoleName;
use App\Enums\Sex;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\User;
use App\Services\Ai\AiSuggestedDoctorService;
use App\Services\AiHospitalContextService;
use App\Services\AppointmentBookingService;
use App\Support\AiDisclaimer;
use App\Support\BookingIntent;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AiSuggestedDoctorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-05 08:00:00', 'Asia/Manila'));

        $this->seed(RoleSeeder::class);

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

    public function test_valid_specialization_returns_matching_doctors(): void
    {
        $clinic = $this->eligibleClinic();
        $doctor = $this->doctor($clinic, 'Dr. Alpha', 'Development Specialization A');
        $this->mondaySchedule($doctor);

        $response = $this->recommendService(1, 'Development Specialization A');

        $response->assertOk()
            ->assertJsonPath('type', 'recommendation')
            ->assertJsonPath('specialization', 'Development Specialization A')
            ->assertJsonPath('doctors.0.id', $doctor->id)
            ->assertJsonPath('doctors.0.display_name', 'Dr. Alpha')
            ->assertJsonPath('doctors.0.specialization', 'Development Specialization A')
            ->assertJsonPath('doctors.0.clinic', 'Development Clinic A')
            ->assertJsonPath('doctors.0.clinic_id', $clinic->id)
            ->assertJsonPath('preview_limit', 3)
            ->assertJsonMissingPath('doctor_id');
    }

    public function test_invalid_and_inactive_specializations_are_rejected(): void
    {
        $clinic = $this->eligibleClinic();
        $this->doctor($clinic, 'Dr. Alpha', 'Development Specialization A');

        $this->fakeClaude(['type' => 'recommendation', 'service_id' => 99, 'reason' => 'Invented.', 'confidence' => null]);
        $this->postGuidance("I've had a headache since yesterday.")
            ->assertOk()
            ->assertJsonPath('type', 'uncertain')
            ->assertJsonMissingPath('doctors');

        $hidden = $this->doctor($clinic, 'Dr. Inactive Spec', 'Only Inactive Specialization', DoctorStatus::Inactive);
        $this->assertSame(DoctorStatus::Inactive, $hidden->status);

        $this->fakeClaude(['type' => 'recommendation', 'service_id' => 2, 'reason' => 'Hidden spec.', 'confidence' => null]);
        $this->postGuidance('My stomach hurts.')
            ->assertOk()
            ->assertJsonPath('type', 'uncertain')
            ->assertJsonMissingPath('doctors');
    }

    public function test_imported_mode_excludes_development_doctors_from_suggested_cards(): void
    {
        config(['hospital.data_source' => HospitalDataSource::Imported->value]);

        $importedDepartment = $this->department('Pending hospital mapping', DepartmentStatus::Active, HospitalDataSource::Imported);
        $developmentDepartment = $this->department('Development Department A', DepartmentStatus::Active);
        $importedClinic = $this->clinic($importedDepartment, 'Pediatrician', ClinicStatus::Active, HospitalDataSource::Imported);
        $ortho = $this->clinic($developmentDepartment, 'Ortho', ClinicStatus::Active);

        $imported = $this->doctor($importedClinic, 'Dr. Endaya, Hazel Anne', 'Pediatrician', DoctorStatus::Active, HospitalDataSource::Imported);
        $this->doctor($ortho, 'Dr. Robles', 'Pediatrician');

        $response = $this->recommendService(1, 'Pediatrician');
        $names = collect($response->json('doctors'))->pluck('display_name')->all();

        $response->assertOk()
            ->assertJsonPath('type', 'recommendation')
            ->assertJsonPath('doctors.0.bookable', false)
            ->assertJsonPath('doctors.0.next_available', null)
            ->assertJsonPath('doctors.0.availability', null)
            ->assertJsonPath('all_unavailable', true)
            ->assertJsonPath('message', AiSuggestedDoctorService::NO_ONLINE_SLOTS_MESSAGE);
        $this->assertSame(['Dr. Endaya, Hazel Anne'], $names);
        $this->assertNotContains('Dr. Robles', $names);
        $this->assertSame($imported->id, $response->json('doctors.0.id'));
    }

    public function test_inactive_doctor_clinic_and_department_are_excluded(): void
    {
        $department = $this->department();
        $clinic = $this->clinic($department, 'Development Clinic A');
        $otherClinic = $this->clinic($department, 'Development Clinic B');
        $inactiveClinic = $this->clinic($department, 'Development Clinic Inactive', ClinicStatus::Inactive);
        $hiddenDepartment = $this->department('Development Department Inactive', DepartmentStatus::Inactive);
        $hiddenClinic = $this->clinic($hiddenDepartment, 'Hidden Clinic');

        $visible = $this->doctor($clinic, 'Dr. Visible', 'Development Specialization A');
        $this->doctor($clinic, 'Dr. Inactive', 'Development Specialization A', DoctorStatus::Inactive);
        $this->doctor($otherClinic, 'Dr. Other Clinic', 'Development Specialization B');
        $this->doctor($inactiveClinic, 'Dr. Inactive Clinic', 'Development Specialization A');
        $this->doctor($hiddenClinic, 'Dr. Hidden Department', 'Development Specialization A');
        $this->mondaySchedule($visible);

        $names = collect($this->recommendService(1, 'Development Specialization A')->json('doctors'))
            ->pluck('display_name')
            ->all();

        $this->assertSame(['Dr. Visible'], $names);
    }

    public function test_multiple_doctors_share_a_specialization_and_preview_is_three(): void
    {
        $clinic = $this->eligibleClinic();

        foreach (['Dr. One', 'Dr. Two', 'Dr. Three', 'Dr. Four'] as $name) {
            $this->mondaySchedule($this->doctor($clinic, $name, 'Development Specialization A'));
        }

        $response = $this->recommendService(1, 'Development Specialization A');

        $response->assertOk()
            ->assertJsonPath('doctors_total', 4)
            ->assertJsonPath('preview_limit', 3)
            ->assertJsonCount(4, 'doctors');

        $js = file_get_contents(public_path('js/ai-front-desk.js'));
        $page = $this->withSession([AiDisclaimer::SESSION_KEY => true])->get(route('ai-front-desk'));

        $this->assertIsString($js);
        $this->assertStringContainsString('data-mg-view-more', $js);
        $this->assertStringContainsString('previewLimit', $js);
        $page->assertSee('Suggested Doctors');
        $page->assertSee('View More Doctors');
        $page->assertDontSee('Dr. One');
        $page->assertDontSee('Test Doctor 1');
    }

    public function test_unavailable_and_expired_slots_are_not_bookable(): void
    {
        $clinic = $this->eligibleClinic();
        $unscheduled = $this->doctor($clinic, 'Dr. None', 'Development Specialization A');
        $scheduled = $this->doctor($clinic, 'Dr. Later', 'Development Specialization A');
        $this->mondaySchedule($scheduled);

        $this->recommendService(1, 'Development Specialization A')
            ->assertJsonFragment([
                'display_name' => 'Dr. None',
                'bookable' => false,
                'availability' => null,
            ])
            ->assertJsonPath('all_unavailable', false);

        Carbon::setTestNow(Carbon::parse('2026-10-05 11:40:00', 'Asia/Manila'));

        $later = collect($this->recommendService(1, 'Development Specialization A')->json('doctors'))
            ->firstWhere('display_name', 'Dr. Later');

        $this->assertIsArray($later);
        $this->assertTrue($later['bookable']);
        $this->assertStringContainsString('Mon, Oct 12, 2026', $later['next_available']);
        $this->assertSame($unscheduled->clinic_id, $clinic->id);
    }

    public function test_seven_day_window_and_pending_appointments_block_slots(): void
    {
        $clinic = $this->eligibleClinic();
        $doctor = $this->doctor($clinic, 'Dr. Window', 'Development Specialization A');
        $schedule = $this->mondaySchedule($doctor);
        $patient = $this->patientUser();

        Appointment::query()->create([
            'patient_id' => $patient->patient->id,
            'doctor_id' => $doctor->id,
            'doctor_schedule_id' => $schedule->id,
            'appointment_date' => '2026-10-05',
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'status' => AppointmentStatus::Pending,
        ]);

        $card = $this->recommendService(1, 'Development Specialization A')->json('doctors.0');
        $last = app(AppointmentBookingService::class)->lastBookableDate();
        $next = Carbon::createFromFormat('D, M j, Y, g:i A', $card['next_available'], 'Asia/Manila');

        $this->assertSame('Dr. Window', $card['display_name']);
        $this->assertStringContainsString('9:30 AM', $card['next_available']);
        $this->assertTrue($next->lte($last->copy()->endOfDay()));
        $this->assertTrue($next->gte(Carbon::parse('2026-10-05 09:30:00', 'Asia/Manila')));
    }

    public function test_guest_and_authenticated_booking_handoff_use_server_doctors(): void
    {
        $clinic = $this->eligibleClinic();
        $doctor = $this->doctor($clinic, 'Dr. Bookable', 'Development Specialization A');
        $this->mondaySchedule($doctor);

        $payload = $this->recommendService(1, 'Development Specialization A')->json();

        $this->assertSame(route('patient.book-appointment'), $payload['book_appointment_url']);
        $this->assertFalse($payload['is_authenticated_patient']);
        $this->assertSame($doctor->id, $payload['doctors'][0]['id']);

        $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->post(route('ai-front-desk.booking-intent'), [
                'clinic_id' => $payload['doctors'][0]['clinic_id'],
                'doctor_id' => $payload['doctors'][0]['id'],
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHas(BookingIntent::SESSION_KEY, [
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
            ]);

        $patient = $this->patientUser();

        $this->actingAs($patient)
            ->withSession([AiDisclaimer::SESSION_KEY => true])
            ->post(route('ai-front-desk.booking-intent'), [
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
            ])
            ->assertRedirect(route('patient.book-appointment', [
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
            ]));
    }

    public function test_clarification_and_safety_do_not_return_doctors(): void
    {
        $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'clarification',
            'question' => 'Gaano na katagal sumasakit ang ulo mo?',
        ]);

        $this->postGuidance('Masakit ulo ko.')
            ->assertOk()
            ->assertExactJson([
                'type' => 'clarification',
                'question' => 'Gaano na katagal sumasakit ang ulo mo?',
            ]);

        $this->fakeClaude([
            'type' => 'recommendation',
            'service_id' => 1,
            'reason' => 'Should not book.',
            'confidence' => null,
        ]);

        $this->postGuidance('I have a sudden severe headache and I cannot speak.')
            ->assertOk()
            ->assertJsonPath('type', 'safety')
            ->assertJsonMissingPath('doctors')
            ->assertJsonMissingPath('book_appointment_url');
    }

    public function test_xss_characters_are_returned_as_text_and_page_does_not_hardcode_doctors(): void
    {
        $clinic = $this->eligibleClinic();
        $this->doctor($clinic, '<script>alert(1)</script>', 'Development Specialization A');

        $response = $this->recommendService(1, 'Development Specialization A');

        $response->assertOk()
            ->assertJsonPath('doctors.0.display_name', '<script>alert(1)</script>');

        $page = $this->withSession([AiDisclaimer::SESSION_KEY => true])->get(route('ai-front-desk'));
        $page->assertDontSee('<script>alert(1)</script>', false);
        $page->assertSee('Suggested Doctors');
    }

    public function test_no_invented_doctor_ids_and_empty_service_has_no_doctors(): void
    {
        $clinic = $this->eligibleClinic();
        $this->fakeClaude([
            'type' => 'recommendation',
            'clinic_id' => $clinic->id,
            'reason' => 'The stored clinic description supports this service.',
            'confidence' => null,
        ]);

        $this->postGuidance("I've had a headache since yesterday.")
            ->assertOk()
            ->assertJsonPath('type', 'recommendation')
            ->assertJsonPath('message', AiSuggestedDoctorService::NO_DOCTORS_MESSAGE)
            ->assertJsonCount(0, 'doctors');
    }

    public function test_front_desk_does_not_hardcode_hospital_doctor_names_in_client_code(): void
    {
        $js = file_get_contents(public_path('js/ai-front-desk.js'));

        $this->assertIsString($js);
        $this->assertStringNotContainsString('Test Doctor 1', $js);
        $this->assertStringNotContainsString('Dr. Juan', $js);
        $this->assertStringContainsString('textContent', $js);
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

    private function recommendService(int $serviceId, string $specialization): TestResponse
    {
        $context = app(AiHospitalContextService::class)->availableContext();
        $this->assertTrue($context->includesService($serviceId));
        $this->assertSame($specialization, $context->serviceById($serviceId)?->specialization);

        $this->fakeClaude([
            'type' => 'recommendation',
            'service_id' => $serviceId,
            'reason' => 'Based on the information you shared, a consultation with this type of specialist may be appropriate.',
            'confidence' => null,
        ]);

        return $this->postGuidance('Dalawang araw na sumasakit ang ulo ko at medyo nahihilo ako.');
    }

    private function postGuidance(string $message): TestResponse
    {
        return $this->withSession([
            AiDisclaimer::SESSION_KEY => true,
        ])->postJson(route('ai-front-desk.guidance'), [
            'message' => $message,
        ]);
    }

    private function eligibleClinic(): Clinic
    {
        return $this->clinic($this->department(), 'Development Clinic A', ClinicStatus::Active, 'Placeholder clinic for local development. Not hospital data.');
    }

    private function department(
        string $name = 'Development Department A',
        DepartmentStatus $status = DepartmentStatus::Active,
        HospitalDataSource $dataSource = HospitalDataSource::Development,
    ): Department {
        return Department::query()->create([
            'name' => $name,
            'description' => 'Placeholder department for local development. Not hospital data.',
            'status' => $status,
            'data_source' => $dataSource,
        ]);
    }

    private function clinic(
        Department $department,
        string $name,
        ClinicStatus $status = ClinicStatus::Active,
        HospitalDataSource|string|null $descriptionOrSource = 'Placeholder clinic for local development. Not hospital data.',
        HospitalDataSource $dataSource = HospitalDataSource::Development,
    ): Clinic {
        $description = 'Placeholder clinic for local development. Not hospital data.';

        if ($descriptionOrSource instanceof HospitalDataSource) {
            $dataSource = $descriptionOrSource;
        } elseif (is_string($descriptionOrSource) || $descriptionOrSource === null) {
            $description = $descriptionOrSource;
        }

        return Clinic::query()->create([
            'department_id' => $department->id,
            'name' => $name,
            'description' => $description,
            'status' => $status,
            'data_source' => $dataSource,
        ]);
    }

    private function doctor(
        Clinic $clinic,
        string $name,
        string $specialization,
        DoctorStatus $status = DoctorStatus::Active,
        HospitalDataSource $dataSource = HospitalDataSource::Development,
    ): Doctor {
        return Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'display_name' => $name,
            'specialization' => $specialization,
            'status' => $status,
            'data_source' => $dataSource,
        ]);
    }

    private function mondaySchedule(Doctor $doctor): DoctorSchedule
    {
        return DoctorSchedule::query()->create([
            'doctor_id' => $doctor->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'slot_duration' => 30,
            'status' => DoctorScheduleStatus::Active,
        ]);
    }

    private function patientUser(): User
    {
        $user = User::factory()->role(RoleName::Patient)->create([
            'email' => 'suggested-patient@example.com',
        ]);

        $user->patient()->create([
            'date_of_birth' => '1990-01-01',
            'sex' => Sex::Female,
            'contact_number' => '09170000999',
        ]);

        return $user->fresh(['patient']);
    }
}
