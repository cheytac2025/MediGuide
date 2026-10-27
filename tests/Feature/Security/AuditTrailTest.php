<?php

namespace Tests\Feature\Security;

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\AuditOutcome;
use App\Enums\ClinicStatus;
use App\Enums\DoctorStatus;
use App\Enums\RoleName;
use App\Enums\Sex;
use App\Enums\UserStatus;
use App\Exceptions\AuditTrailException;
use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\User;
use App\Services\AppointmentBookingService;
use App\Services\DoctorManagementService;
use App\Services\DoctorScheduleManagementService;
use App\Support\Audit\AuditCheckpointStore;
use App\Support\Audit\AuditTrailService;
use App\Support\Audit\AuditVerificationService;
use App\Support\Audit\MemoryAuditCheckpointStore;
use App\Support\Integrity\IntegrityStatus;
use App\Support\Integrity\RecordIntegrityService;
use Database\Seeders\DevelopmentClinicSeeder;
use Database\Seeders\DevelopmentDepartmentSeeder;
use Database\Seeders\DevelopmentDoctorScheduleSeeder;
use Database\Seeders\DevelopmentDoctorSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05 08:00:00');
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

    public function test_an_audit_event_records_the_actor_role_action_target_and_utc_time(): void
    {
        $user = $this->patientUser();
        $appointment = $this->appointmentFor($user);

        $event = AuditEvent::query()->where('action', AuditAction::AppointmentCreated->value)->firstOrFail();

        $this->assertSame(1, (int) $event->sequence);
        $this->assertSame($user->id, (int) $event->actor_user_id);
        $this->assertSame(RoleName::Patient->value, $event->actor_role);
        $this->assertSame(AuditAction::AppointmentCreated->value, $event->action);
        $this->assertSame('appointment', $event->target_type);
        $this->assertSame($appointment->id, (int) $event->target_id);
        $this->assertSame(AuditOutcome::Success->value, $event->outcome);
        $this->assertSame('2026-10-05T00:00:00.000000Z', $event->occurred_at);
        $this->assertSame($this->audit()->genesisTag(1), $event->previous_tag);
        $this->assertTrue($this->verification()->verify()['passed']);
    }

    public function test_audit_context_drops_sensitive_values(): void
    {
        $user = User::factory()->role(RoleName::ItAdministrator)->create();
        $secret = 'patient-secret-concern';

        $this->actingAs($user);
        $this->audit()->record(
            AuditAction::AuthorizationDenied,
            AuditOutcome::Denied,
            context: [
                'reason' => 'role',
                'patient_concern' => $secret,
                'contact_number' => '09171234567',
                'date_of_birth' => '1990-01-15',
                'password' => 'SecretPassword',
                'token' => 'csrf-token',
                'email' => 'patient@example.com',
            ],
        );

        $stored = (string) AuditEvent::query()->value('context');

        $this->assertStringContainsString('role', $stored);
        $this->assertStringNotContainsString($secret, $stored);
        $this->assertStringNotContainsString('09171234567', $stored);
        $this->assertStringNotContainsString('1990-01-15', $stored);
        $this->assertStringNotContainsString('SecretPassword', $stored);
        $this->assertStringNotContainsString('csrf-token', $stored);
        $this->assertStringNotContainsString('patient@example.com', $stored);
    }

    public function test_the_chain_links_sequences_and_verification_is_read_only(): void
    {
        $user = User::factory()->role(RoleName::Patient)->create();
        $this->actingAs($user);
        $this->audit()->record(AuditAction::Logout, AuditOutcome::Success);
        $this->audit()->record(AuditAction::LoginSucceeded, AuditOutcome::Success);

        $events = AuditEvent::query()->orderBy('sequence')->get();
        $this->assertSame([1, 2], $events->map(fn (AuditEvent $event): int => (int) $event->sequence)->all());
        $this->assertSame($events[0]->tag, $events[1]->previous_tag);

        $count = AuditEvent::query()->count();
        $this->artisan('mediguide:audit-verify')->assertSuccessful();
        $this->assertSame($count, AuditEvent::query()->count());
    }

    public function test_a_rolled_back_transaction_removes_its_audit_event(): void
    {
        $user = User::factory()->role(RoleName::Patient)->create();

        try {
            DB::transaction(function () use ($user): void {
                $this->audit()->record(AuditAction::Logout, AuditOutcome::Success, actor: $user);
                throw new RuntimeException('undo');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('undo', $exception->getMessage());
        }

        $this->assertSame(0, AuditEvent::query()->count());
        $this->assertSame(0, (int) DB::table('audit_chain_state')->value('last_sequence'));
    }

    public function test_a_failed_audit_write_rolls_back_the_booking(): void
    {
        config(['audit.key' => 'base64:'.base64_encode('too-short')]);
        $user = $this->patientUser();

        try {
            $this->book($user, 'Headache');
            $this->fail('A booking was kept without an audit event.');
        } catch (AuditTrailException $exception) {
            $this->assertSame(AuditTrailException::MESSAGE, $exception->getMessage());
        }

        $this->assertSame(0, Appointment::query()->count());
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_modified_content_tags_order_and_missing_events_fail_verification(): void
    {
        $user = User::factory()->role(RoleName::Patient)->create();
        $this->actingAs($user);
        $this->audit()->record(AuditAction::Logout, AuditOutcome::Success);
        $this->audit()->record(AuditAction::LoginSucceeded, AuditOutcome::Success);
        $this->audit()->record(AuditAction::LoginFailed, AuditOutcome::Failure);

        DB::table('audit_events')->where('sequence', 2)->update(['context' => '{"reason":"tampered"}']);
        $this->assertSame('mismatch', $this->verification()->verify()['category']);

        DB::table('audit_events')->where('sequence', 2)->update([
            'context' => '{}',
            'tag' => str_repeat('ab', 32),
        ]);
        $this->assertSame('mismatch', $this->verification()->verify()['category']);

        DB::table('audit_events')->where('sequence', 1)->update(['sequence' => 90]);
        DB::table('audit_events')->where('sequence', 3)->update(['sequence' => 1]);
        DB::table('audit_events')->where('sequence', 90)->update(['sequence' => 3]);
        $this->assertSame('broken_link', $this->verification()->verify()['category']);
    }

    public function test_deleting_a_middle_event_breaks_the_sequence(): void
    {
        $user = User::factory()->role(RoleName::Patient)->create();
        $this->actingAs($user);
        $this->audit()->record(AuditAction::Logout, AuditOutcome::Success);
        $this->audit()->record(AuditAction::LoginSucceeded, AuditOutcome::Success);
        $this->audit()->record(AuditAction::LoginFailed, AuditOutcome::Failure);
        DB::table('audit_events')->where('sequence', 2)->delete();

        $result = $this->verification()->verify();

        $this->assertFalse($result['passed']);
        $this->assertSame('gap', $result['category']);
        $this->assertSame(2, $result['first_sequence']);
    }

    public function test_duplicate_sequences_are_rejected_and_detected(): void
    {
        $user = User::factory()->role(RoleName::Patient)->create();
        $this->actingAs($user);
        $this->audit()->record(AuditAction::Logout, AuditOutcome::Success);
        $row = (array) DB::table('audit_events')->first();
        unset($row['id']);
        $row['sequence'] = 1;

        try {
            DB::table('audit_events')->insert($row);
            $this->fail('A duplicate audit sequence was inserted.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        Schema::table('audit_events', function ($table): void {
            $table->dropUnique(['sequence']);
        });
        DB::table('audit_events')->insert($row);
        $result = $this->verification()->verify();

        $this->assertFalse($result['passed']);
        $this->assertSame('duplicate', $result['category']);
    }

    public function test_a_protected_checkpoint_detects_tail_and_empty_chain_deletion(): void
    {
        $user = User::factory()->role(RoleName::Patient)->create();
        $this->actingAs($user);
        $this->audit()->record(AuditAction::Logout, AuditOutcome::Success);
        $this->audit()->record(AuditAction::LoginSucceeded, AuditOutcome::Success);
        $store = new MemoryAuditCheckpointStore;
        $store->put($this->audit()->checkpoint());
        $this->app->instance(AuditCheckpointStore::class, $store);

        $second = DB::table('audit_events')->where('sequence', 2)->first();
        DB::table('audit_events')->where('sequence', 2)->delete();
        DB::table('audit_chain_state')->where('id', 1)->update([
            'last_sequence' => 1,
            'last_tag' => DB::table('audit_events')->where('sequence', 1)->value('tag'),
        ]);

        $tail = $this->verification()->verify();
        $this->assertFalse($tail['passed']);
        $this->assertSame('checkpoint', $tail['category']);
        $this->assertSame(2, $tail['first_sequence']);

        DB::table('audit_events')->delete();
        DB::table('audit_chain_state')->where('id', 1)->update([
            'last_sequence' => 0,
            'last_tag' => null,
        ]);
        $empty = $this->verification()->verify();

        $this->assertFalse($empty['passed']);
        $this->assertSame('checkpoint', $empty['category']);
        $this->assertNotNull($second);
    }

    public function test_an_unknown_key_version_fails_verification(): void
    {
        $user = User::factory()->role(RoleName::Patient)->create();
        $this->actingAs($user);
        $this->audit()->record(AuditAction::Logout, AuditOutcome::Success);
        DB::table('audit_events')->where('sequence', 1)->update(['key_version' => 9]);

        $result = $this->verification()->verify();

        $this->assertFalse($result['passed']);
        $this->assertSame('unknown_key', $result['category']);
        $this->assertSame(1, $result['first_sequence']);
    }

    public function test_models_cannot_update_or_delete_audit_events_and_direct_sql_is_detected(): void
    {
        $user = User::factory()->role(RoleName::Patient)->create();
        $this->actingAs($user);
        $this->audit()->record(AuditAction::Logout, AuditOutcome::Success);
        $event = AuditEvent::query()->firstOrFail();

        try {
            $event->update(['action' => 'changed']);
            $this->fail('An audit event was updated through the model.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        try {
            AuditEvent::query()->delete();
            $this->fail('Audit events were deleted through the query builder.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        DB::table('audit_events')->where('id', $event->id)->update(['outcome' => 'denied']);

        $this->assertFalse($this->verification()->verify()['passed']);
    }

    public function test_booking_confirmation_rejection_and_cancellation_are_audited_without_the_concern(): void
    {
        $concern = 'Sensitive headache details';
        $patient = $this->patientUser();
        $appointment = $this->book($patient, $concern);
        $staff = $this->staffUser();

        $this->assertStringNotContainsString($concern, (string) DB::table('audit_events')->where('action', AuditAction::AppointmentCreated->value)->value('context'));
        $this->assertSame(IntegrityStatus::Valid, app(RecordIntegrityService::class)->statusOf($appointment));

        $this->actingAs($staff)->patch(route('staff.appointments.confirm', $appointment))->assertRedirect();
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);
        $this->assertSame(1, AuditEvent::query()->where('action', AuditAction::AppointmentConfirmed->value)->where('actor_user_id', $staff->id)->count());

        $second = $this->book($patient, $concern, '10:00');
        $this->actingAs($staff)->patch(route('staff.appointments.reject', $second))->assertRedirect();
        $this->assertSame(1, AuditEvent::query()->where('action', AuditAction::AppointmentRejected->value)->count());

        $third = $this->book($patient, $concern, '11:00');
        $this->actingAs($patient)->patch(route('patient.appointments.cancel', $third))->assertRedirect();
        $this->assertSame(1, AuditEvent::query()->where('action', AuditAction::AppointmentCancelled->value)->where('actor_user_id', $patient->id)->count());
        $this->assertTrue($this->verification()->verify()['passed']);
    }

    public function test_schedule_clinic_doctor_department_and_account_changes_are_audited(): void
    {
        $admin = User::factory()->role(RoleName::ItAdministrator)->create();
        $department = Department::query()->firstOrFail();
        $this->actingAs($admin)->post(route('admin.clinics.store'), [
            'name' => 'Audit Clinic',
            'department_id' => $department->id,
            'description' => null,
            'status' => ClinicStatus::Active->value,
        ])->assertRedirect();
        $this->assertSame(1, AuditEvent::query()->where('action', AuditAction::ClinicCreated->value)->count());

        $clinic = Clinic::query()->where('name', 'Audit Clinic')->firstOrFail();
        app(DoctorManagementService::class)->create([
            'display_name' => 'Audit Doctor',
            'clinic_id' => $clinic->id,
            'specialization' => null,
            'status' => DoctorStatus::Active->value,
        ], [
            'first_name' => 'Audit',
            'middle_name' => null,
            'last_name' => 'Doctor',
            'email' => 'audit-doctor@example.com',
            'password' => 'password',
        ]);
        $this->assertSame(1, AuditEvent::query()->where('action', AuditAction::DoctorCreated->value)->count());
        $this->assertStringNotContainsString('audit-doctor@example.com', (string) AuditEvent::query()->where('action', AuditAction::DoctorCreated->value)->value('context'));

        $doctor = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();
        app(DoctorScheduleManagementService::class)->create($doctor, [
            'day_of_week' => 'friday',
            'start_time' => '15:00',
            'end_time' => '16:00',
            'slot_duration' => 30,
            'status' => 'active',
        ]);
        $this->assertSame(1, AuditEvent::query()->where('action', AuditAction::ScheduleCreated->value)->count());

        $staff = User::factory()->role(RoleName::HospitalStaff)->create();
        $staff->hospitalStaff()->create()->departments()->sync([$department->id]);
        $this->actingAs($admin)->patch(route('admin.users.update', $staff), [
            'first_name' => $staff->first_name,
            'middle_name' => $staff->middle_name,
            'last_name' => $staff->last_name,
            'email' => $staff->email,
            'status' => UserStatus::Inactive->value,
            'departments' => Department::query()->orderBy('id')->pluck('id')->take(2)->all(),
        ])->assertRedirect();

        $this->assertSame(1, AuditEvent::query()->where('action', AuditAction::AccountStatusChanged->value)->count());
        $this->assertSame(1, AuditEvent::query()->where('action', AuditAction::DepartmentAssignmentChanged->value)->count());
        $this->assertStringNotContainsString((string) $staff->email, (string) DB::table('audit_events')->where('actor_user_id', $admin->id)->pluck('context')->implode(' '));
    }

    public function test_login_logout_and_inactive_denial_are_audited_without_secrets(): void
    {
        $user = User::factory()->role(RoleName::Patient)->create();

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect();
        $this->assertSame(1, AuditEvent::query()->where('action', AuditAction::LoginSucceeded->value)->where('actor_user_id', $user->id)->count());
        $this->assertStringNotContainsString('password', (string) AuditEvent::query()->where('action', AuditAction::LoginSucceeded->value)->value('context'));

        $this->actingAs($user)->post(route('logout'))->assertRedirect();
        $this->assertSame(1, AuditEvent::query()->where('action', AuditAction::Logout->value)->count());

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);
        $failed = AuditEvent::query()->where('action', AuditAction::LoginFailed->value)->first();
        $this->assertNotNull($failed);
        $this->assertStringNotContainsString('wrong-password', (string) $failed->context);
        $this->assertStringNotContainsString((string) $user->email, (string) $failed->context);

        $user->update(['status' => UserStatus::Inactive]);
        $this->actingAs($user)->get(route('patient.dashboard'));
        $this->assertSame(1, AuditEvent::query()->where('action', AuditAction::InactiveSessionDenied->value)->count());
    }

    public function test_integrity_failure_and_role_denial_are_audited_and_existing_protections_remain(): void
    {
        $patient = $this->patientUser();
        $appointment = $this->book($patient, 'Readable concern');
        DB::table('appointments')->where('id', $appointment->id)->update([
            'status' => AppointmentStatus::Confirmed->value,
        ]);

        $this->actingAs($patient)
            ->from(route('patient.appointments.show', $appointment))
            ->patch(route('patient.appointments.cancel', $appointment))
            ->assertRedirect();

        $integrity = AuditEvent::query()->where('action', AuditAction::IntegrityFailed->value)->first();
        $this->assertNotNull($integrity);
        $this->assertSame('appointment', $integrity->target_type);
        $this->assertSame($appointment->id, (int) $integrity->target_id);
        $this->assertStringNotContainsString('Readable concern', (string) $integrity->context);
        $this->assertSame(AppointmentStatus::Confirmed->value, DB::table('appointments')->where('id', $appointment->id)->value('status'));

        $this->actingAs($patient)->get(route('staff.appointments'))->assertForbidden();
        $this->assertSame(1, AuditEvent::query()->where('action', AuditAction::AuthorizationDenied->value)->count());
        $this->assertSame(IntegrityStatus::Invalid, app(RecordIntegrityService::class)->statusOf($appointment->fresh()));
    }

    public function test_checkpoint_command_stays_inactive_without_an_external_path(): void
    {
        $this->artisan('mediguide:audit-checkpoint')->assertSuccessful()->expectsOutputToContain('DRY RUN');
        $this->artisan('mediguide:audit-checkpoint', ['--execute' => true])->assertFailed();
    }

    private function audit(): AuditTrailService
    {
        return app(AuditTrailService::class);
    }

    private function verification(): AuditVerificationService
    {
        return app(AuditVerificationService::class);
    }

    private function patientUser(): User
    {
        $user = User::factory()->role(RoleName::Patient)->create();
        $user->patient()->create([
            'date_of_birth' => '1990-01-15',
            'sex' => Sex::Female,
            'contact_number' => '09170000001',
        ]);

        return $user->fresh(['patient']);
    }

    private function staffUser(): User
    {
        $user = User::factory()->role(RoleName::HospitalStaff)->create();
        $user->hospitalStaff()->create()->departments()->sync(
            Department::query()->whereIn('name', ['Development Department A', 'Development Department B'])->pluck('id'),
        );

        return $user;
    }

    private function appointmentFor(User $patient): Appointment
    {
        return $this->book($patient, null);
    }

    private function book(User $patient, ?string $concern, string $start = '09:00'): Appointment
    {
        $doctor = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();
        $schedule = DoctorSchedule::query()->where('doctor_id', $doctor->id)->where('start_time', '09:00:00')->firstOrFail();

        return app(AppointmentBookingService::class)->book(
            $patient->patient,
            $doctor,
            $schedule,
            '2026-10-12',
            $start,
            $concern,
        );
    }
}
