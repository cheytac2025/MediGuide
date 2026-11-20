<?php

namespace Tests\Feature\Patient;

use App\Enums\AppointmentStatus;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorScheduleStatus;
use App\Enums\DoctorStatus;
use App\Enums\RoleName;
use App\Enums\Sex;
use App\Models\Appointment;
use App\Models\AppointmentAiSummary;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\User;
use App\Services\Ai\AppointmentAiSummaryService;
use App\Services\AppointmentBookingService;
use App\Support\AiBookingContext;
use App\Support\AiDisclaimer;
use App\Support\AiFrontDeskConversation;
use App\Support\BookingIntent;
use Database\Seeders\DevelopmentClinicSeeder;
use Database\Seeders\DevelopmentDepartmentSeeder;
use Database\Seeders\DevelopmentDoctorAccountSeeder;
use Database\Seeders\DevelopmentDoctorScheduleSeeder;
use Database\Seeders\DevelopmentDoctorSeeder;
use Database\Seeders\DevelopmentHospitalStaffSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AppointmentAiSummaryTest extends TestCase
{
    use RefreshDatabase;

    private int $patientSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(DevelopmentDepartmentSeeder::class);
        $this->seed(DevelopmentClinicSeeder::class);
        $this->seed(DevelopmentDoctorSeeder::class);
        $this->seed(DevelopmentDoctorScheduleSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_ai_assisted_booking_creates_encrypted_summary_for_authorized_roles(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-10 08:00:00', 'Asia/Manila'));

        $this->seed(DevelopmentDoctorAccountSeeder::class);
        $this->seed(DevelopmentHospitalStaffSeeder::class);

        $patient = $this->patientUser();
        $clinic = $this->clinicA();
        $doctor = $this->doctor1();
        $appointment = $this->bookWithAiSummary($patient, $doctor);

        $summary = AppointmentAiSummary::query()->where('appointment_id', $appointment->id)->sole();

        $this->assertSame($doctor->specialization, $summary->suggested_specialization);
        $this->assertStringContainsString('headache', mb_strtolower($summary->concern_summary));
        $this->assertTrue(
            str_contains($summary->concern_summary, 'Mild')
            || collect($summary->reportedDetailsList())->contains(
                fn (array $row): bool => str_contains((string) ($row['value'] ?? ''), 'Mild'),
            )
            || collect($summary->originalInputList())->contains(
                fn (string $line): bool => str_contains($line, 'Mild'),
            ),
        );

        $raw = (array) DB::table('appointment_ai_summaries')->where('id', $summary->id)->first();
        $this->assertStringNotContainsString('headache', mb_strtolower((string) $raw['concern_summary']));
        $this->assertStringNotContainsString('Mild', (string) $raw['reported_details']);

        $doctorUser = User::query()->where('email', DevelopmentDoctorAccountSeeder::EMAIL)->firstOrFail();
        $this->actingAs($doctorUser)
            ->get(route('doctor.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('AI Summary')
            ->assertSee('Patient Concern Summary')
            ->assertSee('Suggested Department or Specialization')
            ->assertSee($doctor->specialization)
            ->assertSee('Not a medical diagnosis');

        $staff = User::query()->where('email', DevelopmentHospitalStaffSeeder::EMAIL)->firstOrFail();
        $this->actingAs($staff)
            ->get(route('staff.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('AI Summary')
            ->assertSee('I have had a headache since yesterday.');

        $this->actingAs($patient)
            ->get(route('patient.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('AI Summary')
            ->assertSee('Patient Concern Summary')
            ->assertDontSee('No AI summary available.');
    }

    public function test_manual_booking_without_ai_context_shows_fallback_message(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-10 08:00:00', 'Asia/Manila'));

        $patient = $this->patientUser();
        $clinic = $this->clinicA();
        $doctor = $this->doctor1();
        $slot = app(AppointmentBookingService::class)->nextAvailableSlot($doctor);
        $this->assertNotNull($slot);

        $schedule = DoctorSchedule::query()
            ->where('doctor_id', $doctor->id)
            ->where('day_of_week', strtolower(Carbon::parse($slot['date'])->format('l')))
            ->where('status', DoctorScheduleStatus::Active)
            ->firstOrFail();

        $this->actingAs($patient)
            ->post(route('patient.book-appointment.store'), [
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
                'doctor_schedule_id' => $schedule->id,
                'appointment_date' => $slot['date'],
                'start_time' => $slot['time'],
                'patient_concern' => 'Walk-in style concern without AI.',
            ])
            ->assertRedirect(route('patient.appointments'));

        $appointment = Appointment::query()->sole();
        $this->assertNull(AppointmentAiSummary::query()->where('appointment_id', $appointment->id)->first());

        $this->actingAs($patient)
            ->get(route('patient.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('No AI summary available.');
    }

    public function test_summary_survives_status_changes_and_rejects_duplicates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-10 08:00:00', 'Asia/Manila'));

        $this->seed(DevelopmentHospitalStaffSeeder::class);

        $patient = $this->patientUser();
        $doctor = $this->doctor1();
        $appointment = $this->bookWithAiSummary($patient, $doctor);

        $summary = AppointmentAiSummary::query()->where('appointment_id', $appointment->id)->sole();
        $original = $summary->concern_summary;

        app(AppointmentAiSummaryService::class)->storeOnce($appointment, [
            'concern_summary' => 'Should not overwrite.',
            'reported_details' => [['label' => 'Primary concern', 'value' => 'Should not overwrite']],
            'suggested_specialization' => 'Other',
            'original_inputs' => ['Should not overwrite'],
        ]);

        $this->assertSame($original, AppointmentAiSummary::query()->findOrFail($summary->id)->concern_summary);

        $appointment->update(['status' => AppointmentStatus::Cancelled]);
        $this->assertTrue(AppointmentAiSummary::query()->where('appointment_id', $appointment->id)->exists());

        $appointment->update([
            'status' => AppointmentStatus::Confirmed,
            'appointment_date' => Carbon::parse('2026-11-12')->toDateString(),
        ]);
        $this->assertSame(1, AppointmentAiSummary::query()->where('appointment_id', $appointment->id)->count());

        $staff = User::query()->where('email', DevelopmentHospitalStaffSeeder::EMAIL)->firstOrFail();
        $this->actingAs($staff)
            ->get(route('staff.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('AI Summary')
            ->assertDontSee('Should not overwrite.');
    }

    public function test_other_doctor_unrelated_staff_and_it_admin_cannot_view_summary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-10 08:00:00', 'Asia/Manila'));

        $this->seed(DevelopmentDoctorAccountSeeder::class);

        $patient = $this->patientUser();
        $doctor = $this->doctor1();
        $appointment = $this->bookWithAiSummary($patient, $doctor);

        $otherDoctorUser = User::factory()->role(RoleName::Doctor)->create([
            'email' => 'other.doctor.ai-summary@example.com',
        ]);
        $otherDoctorUser->doctor()->create([
            'clinic_id' => $this->clinicB()->id,
            'display_name' => 'Other Doctor',
            'specialization' => DevelopmentDoctorSeeder::SPECIALIZATION_B,
            'status' => DoctorStatus::Active,
        ]);

        $this->actingAs($otherDoctorUser)
            ->get(route('doctor.appointments.show', $appointment))
            ->assertNotFound();

        $foreignStaff = User::factory()->role(RoleName::HospitalStaff)->create([
            'email' => 'foreign.staff.ai-summary@example.com',
        ]);
        $foreignDepartment = Department::query()->create([
            'name' => 'Unrelated Department',
            'status' => DepartmentStatus::Active,
        ]);
        $foreignStaff->hospitalStaff()->create()->departments()->sync([$foreignDepartment->id]);

        $this->actingAs($foreignStaff)
            ->get(route('staff.appointments.show', $appointment))
            ->assertNotFound();

        $admin = User::factory()->role(RoleName::ItAdministrator)->create();
        $this->actingAs($admin)
            ->get(route('staff.appointments.show', $appointment))
            ->assertForbidden();
        $this->actingAs($admin)
            ->get(route('doctor.appointments.show', $appointment))
            ->assertForbidden();
        $this->actingAs($admin)
            ->get(route('patient.appointments.show', $appointment))
            ->assertForbidden();
    }

    public function test_booking_intent_marks_ai_context_and_clears_it_for_staff(): void
    {
        $clinic = $this->clinicA();
        $doctor = $this->doctor1();

        $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->post(route('ai-front-desk.booking-intent'), [
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHas(BookingIntent::SESSION_KEY)
            ->assertSessionHas(AiBookingContext::SESSION_KEY);

        $staff = User::factory()->role(RoleName::HospitalStaff)->create();
        $this->actingAs($staff)
            ->withSession([AiDisclaimer::SESSION_KEY => true])
            ->post(route('ai-front-desk.booking-intent'), [
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
            ])
            ->assertRedirect(route('ai-front-desk'))
            ->assertSessionMissing(AiBookingContext::SESSION_KEY);
    }

    public function test_stale_ai_context_for_another_doctor_does_not_attach_summary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-10 08:00:00', 'Asia/Manila'));

        $patient = $this->patientUser();
        $doctor = $this->doctor1();
        $otherDoctor = Doctor::query()->where('display_name', 'Test Doctor 2')->firstOrFail();
        $clinic = Clinic::query()->findOrFail($doctor->clinic_id);
        $slot = app(AppointmentBookingService::class)->nextAvailableSlot($doctor);
        $this->assertNotNull($slot);

        $schedule = DoctorSchedule::query()
            ->where('doctor_id', $doctor->id)
            ->where('day_of_week', strtolower(Carbon::parse($slot['date'])->format('l')))
            ->where('status', DoctorScheduleStatus::Active)
            ->firstOrFail();

        $this->actingAs($patient)
            ->withSession([
                AiDisclaimer::SESSION_KEY => true,
                AiBookingContext::SESSION_KEY => [
                    'clinic_id' => $clinic->id,
                    'doctor_id' => $otherDoctor->id,
                    'specialization' => $otherDoctor->specialization,
                ],
                AiFrontDeskConversation::SESSION_KEY => $this->conversationEnvelope([
                    ['role' => 'user', 'content' => 'Sumasakit tuhod ko.'],
                    ['role' => 'assistant', 'content' => 'Kailan po?'],
                    ['role' => 'user', 'content' => 'Isang linggo.'],
                ]),
            ])
            ->post(route('patient.book-appointment.store'), [
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
                'doctor_schedule_id' => $schedule->id,
                'appointment_date' => $slot['date'],
                'start_time' => $slot['time'],
                'patient_concern' => 'Mismatched AI context booking',
            ])
            ->assertRedirect(route('patient.appointments'));

        $appointment = Appointment::query()->latest('id')->firstOrFail();
        $this->assertNull(AppointmentAiSummary::query()->where('appointment_id', $appointment->id)->first());
    }

    public function test_patient_cannot_view_another_patients_summary_and_delete_cascades(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-10 08:00:00', 'Asia/Manila'));

        $owner = $this->patientUser();
        $intruder = $this->patientUser();
        $doctor = $this->doctor1();
        $appointment = $this->bookWithAiSummary($owner, $doctor);

        $this->assertTrue(AppointmentAiSummary::query()->where('appointment_id', $appointment->id)->exists());

        $this->actingAs($intruder)
            ->get(route('patient.appointments.show', $appointment))
            ->assertNotFound();

        $appointmentId = $appointment->id;
        $appointment->delete();

        $this->assertNull(AppointmentAiSummary::query()->where('appointment_id', $appointmentId)->first());
    }

    private function bookWithAiSummary(User $patient, Doctor $doctor): Appointment
    {
        $clinic = Clinic::query()->findOrFail($doctor->clinic_id);
        $slot = app(AppointmentBookingService::class)->nextAvailableSlot($doctor);
        $this->assertNotNull($slot);

        $schedule = DoctorSchedule::query()
            ->where('doctor_id', $doctor->id)
            ->where('day_of_week', strtolower(Carbon::parse($slot['date'])->format('l')))
            ->where('status', DoctorScheduleStatus::Active)
            ->firstOrFail();

        $this->actingAs($patient)
            ->withSession([
                AiDisclaimer::SESSION_KEY => true,
                AiBookingContext::SESSION_KEY => [
                    'clinic_id' => $clinic->id,
                    'doctor_id' => $doctor->id,
                    'specialization' => $doctor->specialization,
                ],
                AiFrontDeskConversation::SESSION_KEY => $this->conversationEnvelope([
                    ['role' => 'user', 'content' => 'I have had a headache since yesterday.'],
                    ['role' => 'assistant', 'content' => 'How strong is the pain?'],
                    ['role' => 'user', 'content' => 'Mild.'],
                ]),
            ])
            ->post(route('patient.book-appointment.store'), [
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
                'doctor_schedule_id' => $schedule->id,
                'appointment_date' => $slot['date'],
                'start_time' => $slot['time'],
                'patient_concern' => 'AI booking concern',
            ])
            ->assertRedirect(route('patient.appointments'));

        return Appointment::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $turns
     * @return array<string, mixed>
     */
    private function conversationEnvelope(array $turns): array
    {
        return [
            'v' => 2,
            'opening_concern' => $turns[0]['content'] ?? null,
            'clarification_count' => 1,
            'asked_questions' => [],
            'reply_language' => 'english',
            'turns' => $turns,
        ];
    }

    private function patientUser(): User
    {
        $this->patientSequence++;

        $user = User::factory()->create([
            'first_name' => 'Patient',
            'last_name' => 'Summary'.$this->patientSequence,
            'name' => 'Patient Summary'.$this->patientSequence,
            'email' => "ai-summary-patient{$this->patientSequence}@example.com",
        ]);

        $user->patient()->create([
            'date_of_birth' => '1990-01-01',
            'sex' => Sex::Female,
            'contact_number' => '09170000'.str_pad((string) $this->patientSequence, 3, '0', STR_PAD_LEFT),
        ]);

        return $user->fresh(['patient', 'role']);
    }

    private function clinicA(): Clinic
    {
        return Clinic::query()->where('name', 'Development Clinic A')->firstOrFail();
    }

    private function clinicB(): Clinic
    {
        return Clinic::query()->where('name', 'Development Clinic B')->firstOrFail();
    }

    private function doctor1(): Doctor
    {
        return Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();
    }
}
