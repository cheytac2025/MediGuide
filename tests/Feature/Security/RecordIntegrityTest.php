<?php

namespace Tests\Feature\Security;

use App\Enums\AppointmentStatus;
use App\Enums\RoleName;
use App\Enums\Sex;
use App\Exceptions\RecordIntegrityException;
use App\Models\Appointment;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\User;
use App\Services\AppointmentBookingService;
use App\Support\Integrity\CanonicalRecord;
use App\Support\Integrity\IntegrityStatus;
use App\Support\Integrity\RecordIntegrityService;
use Database\Seeders\DevelopmentClinicSeeder;
use Database\Seeders\DevelopmentDepartmentSeeder;
use Database\Seeders\DevelopmentDoctorScheduleSeeder;
use Database\Seeders\DevelopmentDoctorSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class RecordIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

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

    public function test_new_records_receive_a_valid_hmac_seal(): void
    {
        $appointment = $this->appointmentFor($this->patientUser(), 'Chest pain since last night');

        $this->assertSame(IntegrityStatus::Valid, $this->integrity()->statusOf($appointment));
        $this->assertSame(64, strlen((string) $this->seal($appointment)->hmac));
        $this->assertNotSame((string) config('app.key'), (string) config('integrity.key'));
        $this->assertNotSame(
            hash('sha256', (string) config('app.key')),
            hash('sha256', (string) config('integrity.key')),
        );
    }

    public function test_seal_comparison_uses_hash_equals_and_rejects_a_changed_digest(): void
    {
        $source = (string) file_get_contents(app_path('Support/Integrity/RecordIntegrityService.php'));
        $this->assertStringContainsString('hash_equals(', $source);

        $appointment = $this->appointmentFor($this->patientUser(), 'Digest check');
        $hmac = (string) $this->seal($appointment)->hmac;
        $this->assertTrue($this->integrity()->sealsMatch($hmac, $hmac));
        $this->assertFalse($this->integrity()->sealsMatch($hmac, substr($hmac, 0, -1).($hmac[-1] === 'a' ? 'b' : 'a')));

        DB::table('record_integrity')->where('id', $this->seal($appointment)->id)->update([
            'hmac' => str_repeat('ab', 32),
        ]);

        $this->assertSame(IntegrityStatus::Invalid, $this->integrity()->statusOf($appointment->fresh()));
    }

    public function test_canonical_payload_is_stable_and_distinguishes_null_from_empty(): void
    {
        $this->assertSame('09:00:00', CanonicalRecord::time('09:00'));
        $this->assertSame('2026-10-12', CanonicalRecord::date('2026-10-12 00:00:00'));

        $withNull = $this->appointmentFor($this->patientUser(), null);
        $withEmpty = $this->appointmentFor($this->patientUser(), '', '10:00:00');

        $this->assertSame(IntegrityStatus::Valid, $this->integrity()->statusOf($withNull));
        $this->assertSame(IntegrityStatus::Valid, $this->integrity()->statusOf($withEmpty));
        $this->assertNotSame($this->seal($withNull)->hmac, $this->seal($withEmpty)->hmac);

        DB::table('appointments')->where('id', $withNull->id)->update(['patient_concern' => '']);

        $this->assertSame(IntegrityStatus::Invalid, $this->integrity()->statusOf($withNull));
    }

    public function test_status_date_time_and_relationship_tampering_is_detected(): void
    {
        $appointment = $this->appointmentFor($this->patientUser(), 'Relationship check', status: AppointmentStatus::Confirmed);
        $otherDoctor = Doctor::query()->where('display_name', 'Test Doctor 2')->firstOrFail();
        $otherPatient = $this->patientUser();

        foreach ([
            'status' => ['11:00:00', AppointmentStatus::Cancelled->value],
            'appointment_date' => ['12:00:00', '2026-10-19'],
            'start_time' => ['13:00:00', '11:00:00'],
            'end_time' => ['14:00:00', '11:30:00'],
            'doctor_id' => ['15:00:00', $otherDoctor->id],
            'patient_id' => ['16:00:00', $otherPatient->patient->id],
        ] as $column => [$start, $value]) {
            $record = $this->appointmentFor($this->patientUser(), 'Field '.$column, $start);
            DB::table('appointments')->where('id', $record->id)->update([$column => $value]);

            $this->assertSame(IntegrityStatus::Invalid, $this->integrity()->statusFor('appointment', $record->id), $column);
        }

        $this->assertSame(IntegrityStatus::Valid, $this->integrity()->statusOf($appointment));
    }

    public function test_encrypted_concern_and_profile_ciphertext_tampering_is_detected(): void
    {
        $user = $this->patientUser();
        $appointment = $this->appointmentFor($user, 'Original concern');
        DB::table('appointments')->where('id', $appointment->id)->update([
            'patient_concern' => Crypt::encryptString('Swapped concern'),
        ]);

        $this->assertSame(IntegrityStatus::Invalid, $this->integrity()->statusOf($appointment));

        $this->actingAs($user)
            ->get(route('patient.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('Unverified')
            ->assertDontSee('Swapped concern')
            ->assertDontSee('Original concern');

        DB::table('patients')->where('id', $user->patient->id)->update([
            'contact_number' => Crypt::encryptString('09170000999'),
            'date_of_birth' => Crypt::encryptString('1980-03-01'),
        ]);

        $this->assertSame(IntegrityStatus::Invalid, $this->integrity()->statusOf($user->patient));

        $this->actingAs($user)
            ->get(route('patient.profile'))
            ->assertOk()
            ->assertSee('Unavailable')
            ->assertDontSee('09170000999')
            ->assertDontSee('March 1, 1980');
    }

    public function test_swapping_ciphertext_or_seals_between_records_is_detected(): void
    {
        $first = $this->appointmentFor($this->patientUser(), 'First concern', '09:00:00');
        $second = $this->appointmentFor($this->patientUser(), 'Second concern', '10:00:00');
        $firstConcern = DB::table('appointments')->where('id', $first->id)->value('patient_concern');
        $secondConcern = DB::table('appointments')->where('id', $second->id)->value('patient_concern');

        DB::table('appointments')->where('id', $first->id)->update(['patient_concern' => $secondConcern]);
        DB::table('appointments')->where('id', $second->id)->update(['patient_concern' => $firstConcern]);

        $this->assertSame(IntegrityStatus::Invalid, $this->integrity()->statusOf($first));
        $this->assertSame(IntegrityStatus::Invalid, $this->integrity()->statusOf($second));

        $third = $this->appointmentFor($this->patientUser(), 'Third concern', '11:00:00');
        $fourth = $this->appointmentFor($this->patientUser(), 'Fourth concern', '12:00:00');
        $thirdHmac = (string) $this->seal($third)->hmac;
        $fourthHmac = (string) $this->seal($fourth)->hmac;
        DB::table('record_integrity')->where('subject_id', $third->id)->where('subject_type', 'appointment')->update(['hmac' => $fourthHmac]);
        DB::table('record_integrity')->where('subject_id', $fourth->id)->where('subject_type', 'appointment')->update(['hmac' => $thirdHmac]);

        $this->assertSame(IntegrityStatus::Invalid, $this->integrity()->statusOf($third));
        $this->assertSame(IntegrityStatus::Invalid, $this->integrity()->statusOf($fourth));
    }

    public function test_missing_metadata_stays_unsigned_and_is_not_repaired_by_verification(): void
    {
        $appointment = $this->appointmentFor($this->patientUser(), 'Legacy row');
        $this->forgetEnrollment($appointment);

        $this->assertSame(IntegrityStatus::Unsigned, $this->integrity()->statusOf($appointment));

        $this->artisan('mediguide:integrity-verify')->assertSuccessful();
        $this->assertSame(IntegrityStatus::Unsigned, $this->integrity()->statusOf($appointment));

        $this->actingAs($appointment->patient->user)
            ->get(route('patient.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('Legacy row');
    }

    public function test_a_deleted_seal_does_not_make_a_protected_record_trusted_again(): void
    {
        $user = $this->patientUser();
        $appointment = $this->appointmentFor($user, 'Removed seal', status: AppointmentStatus::Confirmed);
        DB::table('appointments')->where('id', $appointment->id)->update([
            'status' => AppointmentStatus::Cancelled->value,
        ]);
        DB::table('record_integrity')->where('subject_type', 'appointment')->where('subject_id', $appointment->id)->delete();

        $this->assertSame(IntegrityStatus::Invalid, $this->integrity()->statusOf($appointment->fresh()));
        $this->assertSame('missing_seal', $this->integrity()->failureCategory('appointment', $appointment->id));

        try {
            $appointment->fresh()->update(['status' => AppointmentStatus::Pending]);
            $this->fail('A deleted seal was trusted.');
        } catch (RecordIntegrityException) {
            $this->assertTrue(true);
        }

        $this->assertNull(DB::table('record_integrity')->where('subject_type', 'appointment')->where('subject_id', $appointment->id)->first());
        $this->artisan('mediguide:integrity-enroll', ['--execute' => true, '--type' => 'appointment'])->assertFailed();
        $this->artisan('mediguide:integrity-rotate', ['--execute' => true, '--type' => 'appointment'])->assertFailed();
        $this->artisan('mediguide:integrity-recover', ['--type' => 'appointment'])->assertSuccessful();
        $this->assertNull(DB::table('record_integrity')->where('subject_type', 'appointment')->where('subject_id', $appointment->id)->first());

        DB::table('appointments')->where('id', $appointment->id)->update([
            'status' => AppointmentStatus::Confirmed->value,
        ]);
        $this->artisan('mediguide:integrity-recover', [
            '--execute' => true,
            '--type' => 'appointment',
        ])->assertSuccessful();
        $this->assertSame(IntegrityStatus::Valid, $this->integrity()->statusOf($appointment->fresh()));

        DB::table('record_integrity')->where('subject_type', 'appointment')->where('subject_id', $appointment->id)->update([
            'hmac' => str_repeat('ab', 32),
        ]);
        $this->artisan('mediguide:integrity-recover', [
            '--execute' => true,
            '--type' => 'appointment',
        ])->assertFailed();
        $this->assertSame(str_repeat('ab', 32), DB::table('record_integrity')->where('subject_type', 'appointment')->where('subject_id', $appointment->id)->value('hmac'));

        $doctor = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();
        $schedule = DoctorSchedule::query()->where('doctor_id', $doctor->id)->where('start_time', '09:00:00')->firstOrFail();
        DB::table('record_integrity')->where('subject_type', 'appointment')->where('subject_id', $appointment->id)->delete();
        $slots = app(AppointmentBookingService::class)->getAvailableSlots($doctor, $schedule, '2026-10-12');

        $this->assertNotContains('09:00', $slots);
        $this->actingAs($user)
            ->get(route('patient.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('Unverified')
            ->assertDontSee('Removed seal');
    }

    public function test_an_invalid_record_cannot_be_changed_or_resealed(): void
    {
        $appointment = $this->appointmentFor($this->patientUser(), 'Do not repair');
        $hmac = (string) $this->seal($appointment)->hmac;
        DB::table('appointments')->where('id', $appointment->id)->update(['status' => AppointmentStatus::Confirmed->value]);

        try {
            $appointment->fresh()->update(['status' => AppointmentStatus::Cancelled]);
            $this->fail('An invalid record was updated.');
        } catch (RecordIntegrityException $exception) {
            $this->assertSame(RecordIntegrityException::MESSAGE, $exception->getMessage());
        }

        $this->assertSame(AppointmentStatus::Confirmed->value, DB::table('appointments')->where('id', $appointment->id)->value('status'));
        $this->assertSame($hmac, $this->seal($appointment)->hmac);

        $this->artisan('mediguide:integrity-enroll', ['--execute' => true])->assertFailed();
        $this->assertSame($hmac, $this->seal($appointment)->hmac);
    }

    public function test_bulk_and_concurrent_updates_do_not_refresh_an_invalid_seal(): void
    {
        $bulk = $this->appointmentFor($this->patientUser(), 'Bulk target', '13:00:00');
        DB::table('appointments')->where('id', $bulk->id)->update(['status' => AppointmentStatus::Confirmed->value]);
        $this->assertSame(IntegrityStatus::Invalid, $this->integrity()->statusOf($bulk));

        $loaded = Appointment::query()->findOrFail($bulk->id);
        DB::table('appointments')->where('id', $loaded->id)->update(['status' => AppointmentStatus::Rejected->value]);

        try {
            $loaded->update(['patient_concern' => 'Written after tampering']);
            $this->fail('A concurrent tamper was overwritten.');
        } catch (RecordIntegrityException) {
            $this->assertTrue(true);
        }

        $this->assertSame(
            AppointmentStatus::Rejected->value,
            DB::table('appointments')->where('id', $loaded->id)->value('status'),
        );
        $this->assertStringNotContainsString(
            'Written after tampering',
            (string) DB::table('appointments')->where('id', $loaded->id)->value('patient_concern'),
        );
    }

    public function test_legitimate_confirmation_rejection_and_cancellation_refresh_the_seal(): void
    {
        $staff = $this->staffUser(['Development Department A']);
        $patient = $this->patientUser();
        $confirmed = $this->appointmentFor($patient, 'Confirm me', '09:00:00');
        $rejected = $this->appointmentFor($this->patientUser(), 'Reject me', '10:00:00');
        $cancelled = $this->appointmentFor($this->patientUser(), 'Cancel me', '11:00:00', AppointmentStatus::Confirmed);

        $this->actingAs($staff)
            ->patch(route('staff.appointments.confirm', $confirmed))
            ->assertRedirect();
        $this->actingAs($staff)
            ->patch(route('staff.appointments.reject', $rejected))
            ->assertRedirect();
        $this->actingAs($cancelled->patient->user)
            ->patch(route('patient.appointments.cancel', $cancelled))
            ->assertRedirect();

        $this->assertSame(AppointmentStatus::Confirmed, $confirmed->fresh()->status);
        $this->assertSame(AppointmentStatus::Rejected, $rejected->fresh()->status);
        $this->assertSame(AppointmentStatus::Cancelled, $cancelled->fresh()->status);
        $this->assertSame(IntegrityStatus::Valid, $this->integrity()->statusOf($confirmed));
        $this->assertSame(IntegrityStatus::Valid, $this->integrity()->statusOf($rejected));
        $this->assertSame(IntegrityStatus::Valid, $this->integrity()->statusOf($cancelled));
    }

    public function test_registration_seals_the_patient_and_a_rolled_back_booking_leaves_no_seal(): void
    {
        $this->post(route('register.store'), [
            'first_name' => 'Integrity',
            'last_name' => 'Patient',
            'date_of_birth' => '1992-04-04',
            'sex' => Sex::Female->value,
            'contact_number' => '09175550000',
            'email' => 'integrity.patient@example.com',
            'password' => 'MediGuide@2026',
            'password_confirmation' => 'MediGuide@2026',
        ])->assertRedirect(route('login'));

        $user = User::query()->where('email', 'integrity.patient@example.com')->firstOrFail();
        $this->assertSame(IntegrityStatus::Valid, $this->integrity()->statusOf($user->patient));

        $seals = DB::table('record_integrity')->count();

        try {
            DB::transaction(function () use ($user): void {
                $this->appointmentFor($user, 'Rolled back');
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }

        $this->assertSame(0, Appointment::query()->where('patient_id', $user->patient->id)->count());
        $this->assertSame($seals, DB::table('record_integrity')->count());
    }

    public function test_enrollment_is_a_dry_run_until_executed_and_can_resume(): void
    {
        $records = [
            $this->appointmentFor($this->patientUser(), 'One', '09:00:00'),
            $this->appointmentFor($this->patientUser(), 'Two', '10:00:00'),
            $this->appointmentFor($this->patientUser(), 'Three', '11:00:00'),
        ];

        foreach ($records as $record) {
            $this->forgetEnrollment($record);
        }

        $this->artisan('mediguide:integrity-enroll', ['--type' => 'appointment'])->assertSuccessful();
        $this->assertSame(0, DB::table('record_integrity')->where('subject_type', 'appointment')->count());

        $this->artisan('mediguide:integrity-enroll', [
            '--execute' => true,
            '--type' => 'appointment',
            '--limit' => 1,
        ])->assertSuccessful();
        $this->assertSame(1, DB::table('record_integrity')->where('subject_type', 'appointment')->count());

        $this->artisan('mediguide:integrity-enroll', [
            '--execute' => true,
            '--type' => 'appointment',
        ])->assertSuccessful();
        $this->assertSame(3, DB::table('record_integrity')->where('subject_type', 'appointment')->count());

        $snapshot = DB::table('record_integrity')->orderBy('id')->pluck('hmac', 'id')->all();
        $this->artisan('mediguide:integrity-enroll', [
            '--execute' => true,
            '--type' => 'appointment',
        ])->assertSuccessful();
        $this->assertSame($snapshot, DB::table('record_integrity')->orderBy('id')->pluck('hmac', 'id')->all());

        foreach ($records as $record) {
            $this->assertSame(IntegrityStatus::Valid, $this->integrity()->statusOf($record));
        }
    }

    public function test_verification_command_fails_on_invalid_records_without_printing_the_concern(): void
    {
        $appointment = $this->appointmentFor($this->patientUser(), 'Hidden concern text');
        DB::table('appointments')->where('id', $appointment->id)->update(['status' => AppointmentStatus::Cancelled->value]);

        $this->artisan('mediguide:integrity-verify', ['--type' => 'appointment'])
            ->assertFailed()
            ->expectsOutputToContain('appointment')
            ->doesntExpectOutputToContain('Hidden concern text');
    }

    public function test_integrity_failures_are_logged_without_patient_values_or_the_key(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        $appointment = $this->appointmentFor($this->patientUser(), 'Logged concern marker');
        DB::table('appointments')->where('id', $appointment->id)->update(['status' => AppointmentStatus::Cancelled->value]);

        try {
            $appointment->fresh()->update(['status' => AppointmentStatus::Pending]);
        } catch (RecordIntegrityException) {
            // Expected.
        }

        $log = implode("\n", $logged);

        $this->assertStringContainsString('Record integrity check failed.', $log);
        $this->assertStringContainsString('"category":"mismatch"', $log);
        $this->assertStringContainsString((string) $appointment->id, $log);
        $this->assertFalse(str_contains($log, 'Logged concern marker'));
        $this->assertFalse(str_contains($log, (string) config('integrity.key')));
        $this->assertFalse(str_contains($log, (string) config('app.key')));
    }

    public function test_another_patient_still_cannot_open_a_tampered_appointment(): void
    {
        $owner = $this->patientUser();
        $appointment = $this->appointmentFor($owner, 'Private concern');
        DB::table('appointments')->where('id', $appointment->id)->update(['status' => AppointmentStatus::Confirmed->value]);

        $this->actingAs($this->patientUser())
            ->get(route('patient.appointments.show', $appointment))
            ->assertNotFound();
    }

    public function test_a_tampered_appointment_still_blocks_its_slot(): void
    {
        $appointment = $this->appointmentFor($this->patientUser(), 'Occupied slot');
        DB::table('appointments')->where('id', $appointment->id)->update([
            'status' => AppointmentStatus::Cancelled->value,
        ]);

        $doctor = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();
        $schedule = DoctorSchedule::query()
            ->where('doctor_id', $doctor->id)
            ->where('start_time', '09:00:00')
            ->firstOrFail();
        $slots = app(AppointmentBookingService::class)->getAvailableSlots($doctor, $schedule, '2026-10-12');

        $this->assertNotContains('09:00', $slots);

        DB::table('record_integrity')->where('subject_type', 'appointment')->where('subject_id', $appointment->id)->delete();
        $stillProtected = app(AppointmentBookingService::class)->getAvailableSlots($doctor, $schedule, '2026-10-12');

        $this->assertNotContains('09:00', $stillProtected);

        $this->forgetEnrollment($appointment);
        $open = app(AppointmentBookingService::class)->getAvailableSlots($doctor, $schedule, '2026-10-12');

        $this->assertContains('09:00', $open);

        config(['integrity.strict' => true]);
        $strict = app(AppointmentBookingService::class)->getAvailableSlots($doctor, $schedule, '2026-10-12');

        $this->assertNotContains('09:00', $strict);
    }

    public function test_record_and_seal_updates_roll_back_together(): void
    {
        $appointment = $this->appointmentFor($this->patientUser(), 'Atomic appointment');
        $patient = $this->patientUser()->patient;
        $appointmentHmac = (string) $this->seal($appointment)->hmac;
        $patientHmac = (string) $this->patientSeal($patient)->hmac;
        $contact = (string) DB::table('patients')->where('id', $patient->id)->value('contact_number');

        DB::beginTransaction();
        $appointment->update(['status' => AppointmentStatus::Confirmed]);
        $patient->update(['contact_number' => '09170000001']);
        $this->assertSame(AppointmentStatus::Confirmed->value, DB::table('appointments')->where('id', $appointment->id)->value('status'));
        DB::rollBack();

        $this->assertSame(AppointmentStatus::Pending->value, DB::table('appointments')->where('id', $appointment->id)->value('status'));
        $this->assertSame($contact, DB::table('patients')->where('id', $patient->id)->value('contact_number'));
        $this->assertSame($appointmentHmac, (string) $this->seal($appointment)->hmac);
        $this->assertSame($patientHmac, (string) $this->patientSeal($patient)->hmac);
        $this->assertSame(IntegrityStatus::Valid, $this->integrity()->statusOf($appointment->fresh()));
        $this->assertSame(IntegrityStatus::Valid, $this->integrity()->statusOf($patient->fresh()));
    }

    public function test_seal_storage_failure_rolls_back_the_appointment_and_the_patient(): void
    {
        $appointment = $this->appointmentFor($this->patientUser(), 'Storage appointment');
        $patient = $this->patientUser()->patient;
        $appointmentHmac = (string) $this->seal($appointment)->hmac;
        $contact = (string) DB::table('patients')->where('id', $patient->id)->value('contact_number');
        $this->app->instance(RecordIntegrityService::class, new class extends RecordIntegrityService
        {
            public function writeSeal(string $type, int $id): void
            {
                throw new RuntimeException('seal storage failed');
            }
        });

        try {
            $appointment->fresh()->update(['status' => AppointmentStatus::Confirmed]);
            $this->fail('The appointment update was kept after the seal write failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('seal storage failed', $exception->getMessage());
        }

        $this->app->instance(RecordIntegrityService::class, new class extends RecordIntegrityService
        {
            public function writeSeal(string $type, int $id): void
            {
                throw new RuntimeException('seal storage failed');
            }
        });

        try {
            $patient->fresh()->update(['contact_number' => '09998887777']);
            $this->fail('The patient update was kept after the seal write failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('seal storage failed', $exception->getMessage());
        }

        $this->assertSame(AppointmentStatus::Pending->value, DB::table('appointments')->where('id', $appointment->id)->value('status'));
        $this->assertSame($contact, DB::table('patients')->where('id', $patient->id)->value('contact_number'));
        $this->assertSame($appointmentHmac, DB::table('record_integrity')->where('subject_type', 'appointment')->where('subject_id', $appointment->id)->value('hmac'));
        $this->assertSame(IntegrityStatus::Valid, app(RecordIntegrityService::class)->statusOf($patient->fresh()));
    }

    public function test_seal_generation_failure_rolls_back_the_appointment(): void
    {
        $appointment = $this->appointmentFor($this->patientUser(), 'Generation appointment');
        $key = (string) config('integrity.key');
        $this->app->instance(RecordIntegrityService::class, new class($key) extends RecordIntegrityService
        {
            public function __construct(private string $original) {}

            public function writeSeal(string $type, int $id): void
            {
                config(['integrity.key' => 'base64:'.base64_encode(random_bytes(8))]);

                try {
                    parent::writeSeal($type, $id);
                } catch (RuntimeException $exception) {
                    $this->assertGenerationFailure($exception);
                }
            }

            private function assertGenerationFailure(RuntimeException $exception): void
            {
                if (str_contains($exception->getMessage(), $this->original)) {
                    throw new RuntimeException('seal generation failed');
                }

                throw $exception;
            }
        });

        try {
            $appointment->fresh()->update(['status' => AppointmentStatus::Confirmed]);
            $this->fail('The appointment update was kept after seal generation failed.');
        } catch (RuntimeException $exception) {
            $this->assertFalse(str_contains($exception->getMessage(), $key));
        }

        config(['integrity.key' => $key]);

        $this->assertSame(AppointmentStatus::Pending->value, DB::table('appointments')->where('id', $appointment->id)->value('status'));
        $this->assertSame(IntegrityStatus::Valid, $this->integrity()->statusOf($appointment->fresh()));
    }

    public function test_a_stale_model_cannot_overwrite_a_newer_seal(): void
    {
        $appointment = $this->appointmentFor($this->patientUser(), 'Stale appointment', '14:00:00');
        $patient = $this->patientUser()->patient;
        $firstAppointment = Appointment::query()->findOrFail($appointment->id);
        $secondAppointment = Appointment::query()->findOrFail($appointment->id);
        $firstPatient = Patient::query()->findOrFail($patient->id);
        $secondPatient = Patient::query()->findOrFail($patient->id);

        $firstAppointment->update(['status' => AppointmentStatus::Confirmed]);
        $firstPatient->update(['contact_number' => '09170000003']);

        try {
            $secondAppointment->update(['status' => AppointmentStatus::Rejected]);
            $this->fail('A stale appointment update replaced a newer seal.');
        } catch (RecordIntegrityException) {
            $this->assertTrue(true);
        }

        try {
            $secondPatient->update(['sex' => Sex::Male]);
            $this->fail('A stale patient update replaced a newer seal.');
        } catch (RecordIntegrityException) {
            $this->assertTrue(true);
        }

        $this->assertSame(AppointmentStatus::Confirmed->value, DB::table('appointments')->where('id', $appointment->id)->value('status'));
        $this->assertSame('09170000003', DB::table('patients')->where('id', $patient->id)->value('contact_number'));
        $this->assertSame(IntegrityStatus::Valid, $this->integrity()->statusOf($appointment->fresh()));
        $this->assertSame(IntegrityStatus::Valid, $this->integrity()->statusOf($patient->fresh()));
    }

    public function test_eloquent_bulk_updates_are_rejected_and_unsigned_rows_stay_unsigned(): void
    {
        $appointment = $this->appointmentFor($this->patientUser(), 'Bulk guard');

        try {
            Appointment::query()->whereKey($appointment->id)->update(['status' => AppointmentStatus::Confirmed->value]);
            $this->fail('A bulk update bypassed the integrity transaction.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('saved as models', $exception->getMessage());
        }

        $this->assertSame(AppointmentStatus::Pending->value, DB::table('appointments')->where('id', $appointment->id)->value('status'));
        $this->assertSame(IntegrityStatus::Valid, $this->integrity()->statusOf($appointment->fresh()));

        try {
            $appointment->saveQuietly();
            $this->fail('A quiet save bypassed the integrity observer.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $this->forgetEnrollment($appointment);
        $appointment->fresh()->update(['status' => AppointmentStatus::Confirmed]);

        $this->assertNull(DB::table('record_integrity')->where('subject_type', 'appointment')->where('subject_id', $appointment->id)->first());
        $this->assertNull(DB::table('integrity_subjects')->where('subject_type', 'appointment')->where('subject_id', $appointment->id)->first());
        $this->assertSame(IntegrityStatus::Unsigned, $this->integrity()->statusOf($appointment->fresh()));
    }

    public function test_strict_mode_rejects_unsigned_records_without_hiding_valid_ones(): void
    {
        $user = $this->patientUser();
        $appointment = $this->appointmentFor($user, 'Strict concern');
        config(['integrity.strict' => true]);

        $this->actingAs($user)
            ->get(route('patient.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('Strict concern')
            ->assertDontSee('Unverified');

        DB::table('record_integrity')->where('subject_type', 'appointment')->where('subject_id', $appointment->id)->delete();

        try {
            $appointment->fresh()->update(['status' => AppointmentStatus::Cancelled]);
            $this->fail('Strict mode accepted an unsigned record.');
        } catch (RecordIntegrityException) {
            $this->assertTrue(true);
        }

        $this->assertSame(AppointmentStatus::Pending->value, DB::table('appointments')->where('id', $appointment->id)->value('status'));
        $this->actingAs($user)
            ->get(route('patient.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('Unverified')
            ->assertDontSee('Strict concern');
    }

    public function test_tampered_schedule_bindings_keep_the_original_slot_occupied(): void
    {
        $appointment = $this->appointmentFor($this->patientUser(), 'Moved slot', status: AppointmentStatus::Confirmed);
        $doctor = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();
        $otherDoctor = Doctor::query()->where('display_name', 'Test Doctor 2')->firstOrFail();
        $schedule = DoctorSchedule::query()->where('doctor_id', $doctor->id)->where('start_time', '09:00:00')->firstOrFail();
        $booking = app(AppointmentBookingService::class);

        DB::table('appointments')->where('id', $appointment->id)->update([
            'appointment_date' => '2026-10-05',
            'doctor_id' => $otherDoctor->id,
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => AppointmentStatus::Cancelled->value,
        ]);

        $this->assertSame(IntegrityStatus::Invalid, $this->integrity()->statusOf($appointment->fresh()));
        $this->assertNotContains('09:00', $booking->getAvailableSlots($doctor, $schedule, '2026-10-12'));

        DB::table('appointments')->where('id', $appointment->id)->delete();

        $this->assertNotContains('09:00', $booking->getAvailableSlots($doctor, $schedule, '2026-10-12'));
    }

    public function test_enrollment_stays_in_the_caller_transaction_and_a_partial_run_can_resume(): void
    {
        $records = [
            $this->appointmentFor($this->patientUser(), 'Enroll one', '09:00:00'),
            $this->appointmentFor($this->patientUser(), 'Enroll two', '10:00:00'),
            $this->appointmentFor($this->patientUser(), 'Enroll three', '11:00:00'),
        ];

        foreach ($records as $record) {
            $this->forgetEnrollment($record);
        }

        try {
            DB::transaction(function () use ($records): void {
                DB::table('appointments')->where('id', $records[0]->id)->update(['status' => AppointmentStatus::Confirmed->value]);
                $this->assertSame('sealed', $this->integrity()->enrollOne('appointment', $records[0]->id));
                throw new RuntimeException('undo enrollment');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('undo enrollment', $exception->getMessage());
        }

        $this->assertSame(AppointmentStatus::Pending->value, DB::table('appointments')->where('id', $records[0]->id)->value('status'));
        $this->assertNull(DB::table('record_integrity')->where('subject_type', 'appointment')->where('subject_id', $records[0]->id)->first());

        DB::transaction(function () use ($records): void {
            DB::table('appointments')->where('id', $records[0]->id)->update(['status' => AppointmentStatus::Confirmed->value]);
            $this->integrity()->enrollOne('appointment', $records[0]->id);
        });
        $this->assertSame(IntegrityStatus::Valid, $this->integrity()->statusOf($records[0]->fresh()));

        $stopId = $records[1]->id;
        $this->app->instance(RecordIntegrityService::class, new class($stopId) extends RecordIntegrityService
        {
            public function __construct(private int $stopId) {}

            public function enrollOne(string $type, int $id): string
            {
                if ($id === $this->stopId) {
                    throw new RuntimeException('enrollment stopped');
                }

                return parent::enrollOne($type, $id);
            }
        });

        $this->artisan('mediguide:integrity-enroll', [
            '--execute' => true,
            '--type' => 'appointment',
        ])->assertFailed()->doesntExpectOutputToContain('enrollment stopped');

        $this->assertNotNull(DB::table('record_integrity')->where('subject_type', 'appointment')->where('subject_id', $records[0]->id)->first());
        $this->assertNull(DB::table('record_integrity')->where('subject_type', 'appointment')->where('subject_id', $records[1]->id)->first());
        $this->assertNotNull(DB::table('record_integrity')->where('subject_type', 'appointment')->where('subject_id', $records[2]->id)->first());
    }

    public function test_previous_keys_verify_and_rotation_does_not_repair_invalid_seals(): void
    {
        $appointment = $this->appointmentFor($this->patientUser(), 'Rotate me', '15:00:00');
        $previous = (string) config('integrity.key');
        $current = 'base64:'.base64_encode(random_bytes(32));
        config([
            'integrity.key' => $current,
            'integrity.key_version' => 2,
            'integrity.previous_keys' => '1:'.$previous,
        ]);

        $this->assertSame(IntegrityStatus::Valid, $this->integrity()->statusOf($appointment));

        $invalid = $this->appointmentFor($this->patientUser(), 'Do not rotate', '16:00:00');
        DB::table('appointments')->where('id', $invalid->id)->update(['status' => AppointmentStatus::Cancelled->value]);
        DB::table('record_integrity')->where('subject_type', 'appointment')->where('subject_id', $appointment->id)->update(['key_version' => 9]);

        $this->assertSame(IntegrityStatus::Invalid, $this->integrity()->statusOf($appointment->fresh()));
        $this->artisan('mediguide:integrity-verify', ['--type' => 'appointment'])
            ->assertFailed()
            ->expectsOutputToContain('unknown_key')
            ->doesntExpectOutputToContain('Do not rotate');

        DB::table('record_integrity')->where('subject_type', 'appointment')->where('subject_id', $appointment->id)->update(['key_version' => 1]);
        $invalidHmac = (string) DB::table('record_integrity')->where('subject_type', 'appointment')->where('subject_id', $invalid->id)->value('hmac');

        $this->artisan('mediguide:integrity-rotate', ['--type' => 'appointment'])->assertFailed();
        $this->assertSame(1, (int) DB::table('record_integrity')->where('subject_type', 'appointment')->where('subject_id', $appointment->id)->value('key_version'));

        $this->artisan('mediguide:integrity-rotate', [
            '--execute' => true,
            '--type' => 'appointment',
        ])->assertFailed();

        $this->assertSame(2, (int) DB::table('record_integrity')->where('subject_type', 'appointment')->where('subject_id', $appointment->id)->value('key_version'));
        $this->assertSame(IntegrityStatus::Valid, $this->integrity()->statusOf($appointment->fresh()));
        $this->assertSame($invalidHmac, DB::table('record_integrity')->where('subject_type', 'appointment')->where('subject_id', $invalid->id)->value('hmac'));
        $this->assertSame(IntegrityStatus::Invalid, $this->integrity()->statusOf($invalid->fresh()));
    }

    public function test_strict_verification_fails_while_unsigned_records_remain(): void
    {
        $appointment = $this->appointmentFor($this->patientUser(), 'Verify me');

        $this->artisan('mediguide:integrity-verify', [
            '--strict' => true,
            '--type' => 'appointment',
        ])->assertSuccessful();

        $this->forgetEnrollment($appointment);

        $this->artisan('mediguide:integrity-verify', ['--type' => 'appointment'])->assertSuccessful();
        $this->artisan('mediguide:integrity-verify', [
            '--strict' => true,
            '--type' => 'appointment',
        ])->assertFailed()->expectsOutputToContain('unsigned');
    }

    private function integrity(): RecordIntegrityService
    {
        return app(RecordIntegrityService::class);
    }

    private function forgetEnrollment(Appointment $appointment): void
    {
        DB::table('record_integrity')->where('subject_type', 'appointment')->where('subject_id', $appointment->id)->delete();
        DB::table('integrity_subjects')->where('subject_type', 'appointment')->where('subject_id', $appointment->id)->delete();
    }

    private function seal(Appointment $appointment): object
    {
        return DB::table('record_integrity')
            ->where('subject_type', 'appointment')
            ->where('subject_id', $appointment->id)
            ->firstOrFail();
    }

    private function patientSeal(Patient $patient): object
    {
        return DB::table('record_integrity')
            ->where('subject_type', 'patient')
            ->where('subject_id', $patient->id)
            ->firstOrFail();
    }

    private function patientUser(): User
    {
        $this->sequence++;

        $user = User::factory()->role(RoleName::Patient)->create([
            'email' => "integrity-patient-{$this->sequence}@example.com",
        ]);
        $user->patient()->create([
            'date_of_birth' => '1990-01-15',
            'sex' => Sex::Female,
            'contact_number' => '0917'.str_pad((string) $this->sequence, 7, '0', STR_PAD_LEFT),
        ]);

        return $user->fresh(['patient']);
    }

    /**
     * @param  list<string>  $departmentNames
     */
    private function staffUser(array $departmentNames): User
    {
        $this->sequence++;
        $user = User::factory()->role(RoleName::HospitalStaff)->create([
            'email' => "integrity-staff-{$this->sequence}@example.com",
        ]);
        $user->hospitalStaff()->create()->departments()->sync(
            Department::query()->whereIn('name', $departmentNames)->pluck('id'),
        );

        return $user->fresh();
    }

    private function appointmentFor(
        User $patient,
        ?string $concern,
        string $start = '09:00:00',
        AppointmentStatus $status = AppointmentStatus::Pending,
    ): Appointment {
        $doctor = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();
        $schedule = DoctorSchedule::query()->where('doctor_id', $doctor->id)->orderBy('id')->firstOrFail();
        $end = Carbon::createFromFormat('H:i:s', $start)->addMinutes(30)->format('H:i:s');

        return Appointment::query()->create([
            'patient_id' => $patient->patient->id,
            'doctor_id' => $doctor->id,
            'doctor_schedule_id' => $schedule->id,
            'appointment_date' => '2026-10-12',
            'start_time' => $start,
            'end_time' => $end,
            'patient_concern' => $concern,
            'status' => $status,
        ]);
    }
}
