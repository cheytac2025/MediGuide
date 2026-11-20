<?php

namespace Tests\Feature\Doctor;

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorStatus;
use App\Enums\RoleName;
use App\Enums\Sex;
use App\Models\Appointment;
use App\Models\AppointmentDoctorNote;
use App\Models\AppointmentPatientInstruction;
use App\Models\AppointmentPatientInstructionRevision;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\User;
use App\Support\Audit\AuditTrailService;
use Database\Seeders\DevelopmentClinicSeeder;
use Database\Seeders\DevelopmentDepartmentSeeder;
use Database\Seeders\DevelopmentDoctorScheduleSeeder;
use Database\Seeders\DevelopmentDoctorSeeder;
use Database\Seeders\DevelopmentHospitalStaffSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConsultationDocumentationTest extends TestCase
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

        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_assigned_doctor_can_save_draft_that_stays_hidden_from_patient_and_staff(): void
    {
        $this->seed(DevelopmentHospitalStaffSeeder::class);

        $doctorUser = $this->linkedDoctorUser();
        $patient = $this->patientUser();
        $appointment = $this->place($patient, $this->testDoctorOne(), AppointmentStatus::Confirmed);

        $this->actingAs($doctorUser)
            ->post(route('doctor.appointments.consultation.save', $appointment), [
                'internal_notes' => 'Exam: mild knee tenderness. No fracture suspected.',
                'prescription' => 'Paracetamol 500mg as needed',
                'reminders' => 'Rest the knee today',
                'follow_up' => 'Return if swelling increases',
            ])
            ->assertRedirect(route('doctor.appointments.show', $appointment));

        $this->assertDatabaseCount('appointment_doctor_notes', 1);
        $this->assertDatabaseCount('appointment_patient_instructions', 1);
        $this->assertSame(0, AppointmentPatientInstructionRevision::query()->count());

        $rawNote = (array) DB::table('appointment_doctor_notes')->first();
        $this->assertStringNotContainsString('fracture', mb_strtolower((string) $rawNote['internal_notes']));

        $this->actingAs($patient->user)
            ->get(route('patient.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('No post-consultation instructions are available yet.')
            ->assertDontSee('Paracetamol')
            ->assertDontSee('fracture');

        $staff = User::query()->where('email', DevelopmentHospitalStaffSeeder::EMAIL)->firstOrFail();
        $this->actingAs($staff)
            ->get(route('staff.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('No post-consultation instructions are available yet.')
            ->assertDontSee('Paracetamol')
            ->assertDontSee('fracture');

        $this->assertSame(0, $patient->user->notifications()->count());
    }

    public function test_other_doctor_it_admin_and_staff_cannot_write_documentation(): void
    {
        $patient = $this->patientUser();
        $appointment = $this->place($patient, $this->testDoctorOne(), AppointmentStatus::Confirmed);

        $otherDoctorUser = User::factory()->role(RoleName::Doctor)->create([
            'email' => 'other-doc-notes@example.com',
        ]);
        Doctor::query()->where('display_name', 'Test Doctor 2')->firstOrFail()->update([
            'user_id' => $otherDoctorUser->id,
        ]);

        $this->actingAs($otherDoctorUser)
            ->post(route('doctor.appointments.consultation.save', $appointment), [
                'prescription' => 'Should fail',
            ])
            ->assertNotFound();

        $admin = User::factory()->role(RoleName::ItAdministrator)->create();
        $this->actingAs($admin)
            ->post(route('doctor.appointments.consultation.save', $appointment), [
                'prescription' => 'Should fail',
            ])
            ->assertForbidden();

        $staff = User::factory()->role(RoleName::HospitalStaff)->create();
        $this->actingAs($staff)
            ->post(route('doctor.appointments.consultation.publish', $appointment), [
                'prescription' => 'Should fail',
            ])
            ->assertForbidden();
    }

    public function test_publish_requires_completed_consultation_and_rejects_empty_or_cancelled(): void
    {
        $doctorUser = $this->linkedDoctorUser();
        $patient = $this->patientUser();
        $confirmed = $this->place($patient, $this->testDoctorOne(), AppointmentStatus::Confirmed);

        $this->actingAs($doctorUser)
            ->post(route('doctor.appointments.consultation.publish', $confirmed), [
                'prescription' => 'Ibuprofen 200mg',
            ])
            ->assertSessionHasErrors('appointment');

        $cancelled = $this->place($patient, $this->testDoctorOne(), AppointmentStatus::Cancelled, '2026-11-11', '09:00:00', '09:30:00');
        $this->actingAs($doctorUser)
            ->post(route('doctor.appointments.consultation.publish', $cancelled), [
                'prescription' => 'Ibuprofen 200mg',
            ])
            ->assertSessionHasErrors('appointment');

        $rejected = $this->place($patient, $this->testDoctorOne(), AppointmentStatus::Rejected, '2026-11-12', '09:00:00', '09:30:00');
        $this->actingAs($doctorUser)
            ->post(route('doctor.appointments.consultation.publish', $rejected), [
                'prescription' => 'Ibuprofen 200mg',
            ])
            ->assertSessionHasErrors('appointment');

        $this->actingAs($doctorUser)
            ->patch(route('doctor.appointments.complete', $confirmed))
            ->assertRedirect(route('doctor.appointments.show', $confirmed));

        $this->assertSame(AppointmentStatus::Completed, $confirmed->fresh()->status);

        $this->actingAs($doctorUser)
            ->post(route('doctor.appointments.consultation.publish', $confirmed), [])
            ->assertSessionHasErrors('prescription');
    }

    public function test_publish_creates_encrypted_instructions_notification_and_authorized_views(): void
    {
        $this->seed(DevelopmentHospitalStaffSeeder::class);

        $doctorUser = $this->linkedDoctorUser();
        $patient = $this->patientUser();
        $appointment = $this->place($patient, $this->testDoctorOne(), AppointmentStatus::Confirmed);

        $this->actingAs($doctorUser)
            ->patch(route('doctor.appointments.complete', $appointment))
            ->assertRedirect();

        $this->actingAs($doctorUser)
            ->post(route('doctor.appointments.consultation.save', $appointment), [
                'internal_notes' => 'Private clinical finding: effusion mild.',
                'prescription' => "Take ibuprofen 200mg\nwith food",
                'reminders' => 'Ice for 10 minutes',
                'follow_up' => 'Clinic visit in one week if needed',
            ])
            ->assertRedirect();

        $this->actingAs($doctorUser)
            ->post(route('doctor.appointments.consultation.publish', $appointment), [
                'prescription' => "Take ibuprofen 200mg\nwith food",
                'reminders' => 'Ice for 10 minutes',
                'follow_up' => 'Clinic visit in one week if needed',
            ])
            ->assertRedirect(route('doctor.appointments.show', $appointment));

        $instructions = AppointmentPatientInstruction::query()->where('appointment_id', $appointment->id)->sole();
        $this->assertTrue($instructions->isPublished());
        $this->assertSame(1, $instructions->published_revision);
        $this->assertSame(1, AppointmentPatientInstructionRevision::query()->count());

        $raw = (array) DB::table('appointment_patient_instructions')->where('id', $instructions->id)->first();
        $this->assertStringNotContainsString('ibuprofen', mb_strtolower((string) $raw['published_prescription']));
        $this->assertStringNotContainsString('effusion', mb_strtolower((string) DB::table('appointment_doctor_notes')->value('internal_notes')));

        $notifications = $patient->user->notifications()->get();
        $this->assertCount(1, $notifications);
        $payload = $notifications->first()->data;
        $this->assertSame('New Doctor\'s Instructions', $payload['title']);
        $this->assertSame($appointment->id, $payload['appointment_id']);
        $this->assertSame(1, $payload['revision']);
        $this->assertArrayNotHasKey('prescription', $payload);
        $this->assertStringNotContainsString('ibuprofen', mb_strtolower((string) ($payload['message'] ?? '')));

        $this->actingAs($patient->user)
            ->get(route('patient.notifications.open', $notifications->first()))
            ->assertRedirect(route('patient.appointments.show', $appointment));

        $this->actingAs($patient->user)
            ->get(route('patient.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('Post-Consultation Instructions')
            ->assertSee('Take ibuprofen 200mg')
            ->assertSee('Ice for 10 minutes')
            ->assertDontSee('effusion')
            ->assertDontSee('Related Actions')
            ->assertDontSee('Print Instructions')
            ->assertDontSee('Download Instructions');

        $staff = User::query()->where('email', DevelopmentHospitalStaffSeeder::EMAIL)->firstOrFail();
        $this->actingAs($staff)
            ->get(route('staff.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('Take ibuprofen 200mg')
            ->assertDontSee('effusion');

        $foreignStaff = User::factory()->role(RoleName::HospitalStaff)->create([
            'email' => 'foreign-notes-staff@example.com',
        ]);
        $foreignDepartment = Department::query()->create([
            'name' => 'Unrelated Notes Department',
            'status' => DepartmentStatus::Active,
        ]);
        $foreignStaff->hospitalStaff()->create()->departments()->sync([$foreignDepartment->id]);

        $this->actingAs($foreignStaff)
            ->get(route('staff.appointments.show', $appointment))
            ->assertNotFound();

        $admin = User::factory()->role(RoleName::ItAdministrator)->create();
        $this->actingAs($admin)
            ->get(route('patient.appointments.show', $appointment))
            ->assertForbidden();
    }

    public function test_revision_preserves_history_and_sends_one_new_notification_without_duplicates(): void
    {
        $doctorUser = $this->linkedDoctorUser();
        $patient = $this->patientUser();
        $appointment = $this->place($patient, $this->testDoctorOne(), AppointmentStatus::Confirmed);

        $this->actingAs($doctorUser)->patch(route('doctor.appointments.complete', $appointment));

        $this->actingAs($doctorUser)
            ->post(route('doctor.appointments.consultation.publish', $appointment), [
                'prescription' => 'First published dose',
                'reminders' => 'First reminder',
            ])
            ->assertRedirect();

        $this->assertCount(1, $patient->user->fresh()->notifications);

        $this->actingAs($doctorUser)
            ->post(route('doctor.appointments.consultation.publish', $appointment), [
                'prescription' => 'First published dose',
                'reminders' => 'First reminder',
            ])
            ->assertSessionHasErrors('prescription');

        $this->assertCount(1, $patient->user->fresh()->notifications);
        $this->assertSame(1, AppointmentPatientInstructionRevision::query()->count());

        $this->actingAs($doctorUser)
            ->post(route('doctor.appointments.consultation.save', $appointment), [
                'prescription' => 'Updated dose',
                'reminders' => 'Updated reminder',
                'follow_up' => 'Call clinic Friday',
            ])
            ->assertRedirect();

        $this->actingAs($patient->user)
            ->get(route('patient.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('First published dose')
            ->assertDontSee('Updated dose');

        $this->actingAs($doctorUser)
            ->post(route('doctor.appointments.consultation.publish', $appointment), [
                'prescription' => 'Updated dose',
                'reminders' => 'Updated reminder',
                'follow_up' => 'Call clinic Friday',
                'revision_reason' => 'Dose clarification',
            ])
            ->assertRedirect();

        $instructions = AppointmentPatientInstruction::query()->where('appointment_id', $appointment->id)->sole();
        $this->assertSame(2, $instructions->published_revision);
        $this->assertSame(2, AppointmentPatientInstructionRevision::query()->count());
        $this->assertSame('Dose clarification', AppointmentPatientInstructionRevision::query()->where('revision_number', 2)->value('revision_reason'));

        $notifications = $patient->user->fresh()->notifications()->orderByDesc('created_at')->get();
        $this->assertCount(2, $notifications);
        $latest = $notifications->first(
            fn ($notification): bool => (int) ($notification->data['revision'] ?? 0) === 2,
        );
        $this->assertNotNull($latest);
        $this->assertSame('patient_instructions_revised', $latest->data['event']);
        $this->assertStringContainsString('updated', mb_strtolower((string) $latest->data['message']));

        $this->actingAs($patient->user)
            ->get(route('patient.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('Updated dose')
            ->assertSee('Revision 2')
            ->assertDontSee('First published dose');

        $otherPatient = $this->patientUser('Other', 'Patient');
        $this->actingAs($otherPatient->user)
            ->get(route('patient.appointments.show', $appointment))
            ->assertNotFound();

        $this->actingAs($otherPatient->user)
            ->get(route('patient.notifications.open', $latest))
            ->assertNotFound();
    }

    public function test_preview_excludes_internal_notes_and_appointment_hmac_remains_valid(): void
    {
        $doctorUser = $this->linkedDoctorUser();
        $patient = $this->patientUser();
        $appointment = $this->place($patient, $this->testDoctorOne(), AppointmentStatus::Confirmed, concern: 'Knee pain');
        $this->assertTrue($appointment->integrityIsValid());

        $this->actingAs($doctorUser)
            ->post(route('doctor.appointments.consultation.preview', $appointment), [
                'internal_notes' => 'Secret finding',
                'prescription' => 'Preview medicine',
                'reminders' => 'Preview reminder',
            ])
            ->assertOk()
            ->assertSee('Preview medicine')
            ->assertSee('Preview reminder')
            ->assertDontSee('Secret finding');

        $this->actingAs($doctorUser)->patch(route('doctor.appointments.complete', $appointment));
        $this->assertTrue($appointment->fresh()->integrityIsValid());

        $checkpoint = app(AuditTrailService::class)->checkpoint();
        $this->assertNotEmpty($checkpoint['checkpoint_tag']);

        $this->assertDatabaseHas('audit_events', [
            'action' => AuditAction::AppointmentCompleted->value,
        ]);
    }

    public function test_inactive_doctor_cannot_publish(): void
    {
        $doctorUser = $this->linkedDoctorUser();
        $patient = $this->patientUser();
        $appointment = $this->place($patient, $this->testDoctorOne(), AppointmentStatus::Completed);

        Doctor::query()->whereKey($this->testDoctorOne()->id)->update([
            'status' => DoctorStatus::Inactive->value,
        ]);

        $this->assertSame(DoctorStatus::Inactive, $doctorUser->fresh(['doctor'])->doctor->status);

        $this->actingAs($doctorUser->fresh())
            ->from(route('doctor.appointments.show', $appointment))
            ->post(route('doctor.appointments.consultation.publish', $appointment), [
                'prescription' => 'Should fail',
            ])
            ->assertRedirect(route('doctor.appointments.show', $appointment))
            ->assertSessionHasErrors('appointment');

        $this->assertSame(0, AppointmentPatientInstructionRevision::query()->count());
    }

    private function linkedDoctorUser(): User
    {
        $user = User::factory()->role(RoleName::Doctor)->create([
            'first_name' => 'Dev',
            'last_name' => 'Doctor',
            'name' => 'Dev Doctor',
            'email' => 'consultation-doc@example.com',
        ]);

        $this->testDoctorOne()->update(['user_id' => $user->id]);

        return $user->fresh(['doctor']);
    }

    private function testDoctorOne(): Doctor
    {
        return Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();
    }

    private function scheduleFor(Doctor $doctor): DoctorSchedule
    {
        return DoctorSchedule::query()
            ->where('doctor_id', $doctor->id)
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->firstOrFail();
    }

    private function place(
        Patient $patient,
        Doctor $doctor,
        AppointmentStatus $status,
        string $date = '2026-11-10',
        string $start = '09:00:00',
        string $end = '09:30:00',
        ?string $concern = null,
    ): Appointment {
        return Appointment::query()->create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'doctor_schedule_id' => $this->scheduleFor($doctor)->id,
            'appointment_date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'patient_concern' => $concern,
            'status' => $status,
        ]);
    }

    private function patientUser(string $firstName = 'Ana', string $lastName = 'Reyes'): Patient
    {
        $this->patientSequence++;

        $user = User::factory()->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => $firstName.' '.$lastName,
            'email' => "consultation-patient-{$this->patientSequence}@example.com",
        ]);

        return $user->patient()->create([
            'date_of_birth' => '1990-01-15',
            'sex' => Sex::Female,
            'contact_number' => '0918'.str_pad((string) $this->patientSequence, 7, '0', STR_PAD_LEFT),
        ]);
    }
}
