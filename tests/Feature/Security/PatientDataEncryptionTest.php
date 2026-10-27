<?php

namespace Tests\Feature\Security;

use App\Casts\EncryptedPatientData;
use App\Enums\AppointmentStatus;
use App\Enums\DayOfWeek;
use App\Enums\RoleName;
use App\Enums\Sex;
use App\Exceptions\UnreadablePatientDataException;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\User;
use App\Support\PatientDataEncryption;
use Database\Seeders\DevelopmentClinicSeeder;
use Database\Seeders\DevelopmentDepartmentSeeder;
use Database\Seeders\DevelopmentDoctorScheduleSeeder;
use Database\Seeders\DevelopmentDoctorSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PatientDataEncryptionTest extends TestCase
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

    public function test_the_application_uses_laravels_aes_256_authenticated_cipher(): void
    {
        $this->assertSame('AES-256-CBC', config('app.cipher'));

        $payload = json_decode(base64_decode(PatientDataEncryption::encrypt('ubo')), true);

        $this->assertIsArray($payload);
        $this->assertSame(16, strlen(base64_decode($payload['iv'])));
        $this->assertSame(64, strlen($payload['mac']));
        $this->assertNotSame(PatientDataEncryption::encrypt('ubo'), PatientDataEncryption::encrypt('ubo'));
    }

    public function test_appointment_concerns_are_stored_encrypted_and_decrypt_for_authorized_model_access(): void
    {
        $appointment = $this->appointmentFor($this->patientUser(), 'Test Doctor 1', 'Severe chest pain since last night');

        $raw = $this->rawConcern($appointment);

        $this->assertNotSame('Severe chest pain since last night', $raw);
        $this->assertStringNotContainsString('chest pain', $raw);
        $this->assertSame(PatientDataEncryption::ENCRYPTED, PatientDataEncryption::classify($raw));
        $this->assertSame('Severe chest pain since last night', Crypt::decryptString($raw));
        $this->assertSame('Severe chest pain since last night', $appointment->fresh()->patient_concern);
    }

    #[DataProvider('multilingualConcerns')]
    public function test_filipino_english_and_taglish_concerns_round_trip(string $concern): void
    {
        $appointment = $this->appointmentFor($this->patientUser(), 'Test Doctor 1', $concern);

        $this->assertSame(PatientDataEncryption::ENCRYPTED, PatientDataEncryption::classify($this->rawConcern($appointment)));
        $this->assertSame($concern, $appointment->fresh()->patient_concern);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function multilingualConcerns(): array
    {
        return [
            'english' => ['I have had a fever and dry cough for three days.'],
            'filipino' => ['Masakit ang ulo ko at nilalagnat ako mula kahapon.'],
            'taglish' => ['Sobrang sakit ng tiyan ko, parang may acid reflux since Monday.'],
            'accents and symbols' => ['Señora Peñafrancia — ubo’t sipon, 38.5°C, “hindi makatulog” 😷'],
        ];
    }

    public function test_null_concerns_stay_null_and_empty_concerns_stay_empty(): void
    {
        $withNull = $this->appointmentFor($this->patientUser(), 'Test Doctor 1', null);
        $withEmpty = $this->appointmentFor($this->patientUser(), 'Test Doctor 1', '', '10:00:00');

        $this->assertNull($this->rawConcern($withNull));
        $this->assertNull($withNull->fresh()->patient_concern);
        $this->assertSame('', $this->rawConcern($withEmpty));
        $this->assertSame('', $withEmpty->fresh()->patient_concern);
    }

    public function test_the_longest_supported_concern_round_trips_without_truncation(): void
    {
        $concern = Str::repeat('Ñ', 1000);
        $appointment = $this->appointmentFor($this->patientUser(), 'Test Doctor 1', $concern);
        $raw = $this->rawConcern($appointment);

        $this->assertSame($concern, $appointment->fresh()->patient_concern);
        $this->assertLessThan(65535, strlen($raw), 'Ciphertext must fit the existing TEXT column.');
    }

    public function test_tampered_ciphertext_is_detected_and_never_silently_returned(): void
    {
        $appointment = $this->appointmentFor($this->patientUser(), 'Test Doctor 1', 'Confidential concern');
        $payload = json_decode(base64_decode($this->rawConcern($appointment)), true);
        $payload['value'] = base64_encode(random_bytes(32));
        $tampered = base64_encode((string) json_encode($payload));
        DB::table('appointments')->where('id', $appointment->id)->update(['patient_concern' => $tampered]);

        $this->assertSame(PatientDataEncryption::MALFORMED, PatientDataEncryption::classify($tampered));

        try {
            $appointment->fresh()->patient_concern;
            $this->fail('Tampered ciphertext must not decrypt.');
        } catch (UnreadablePatientDataException $exception) {
            $this->assertStringNotContainsString($tampered, $exception->getMessage());
        }
    }

    public function test_ciphertext_from_an_unknown_key_and_unreadable_pages_fail_safely(): void
    {
        $patient = $this->patientUser();
        $appointment = $this->appointmentFor($patient, 'Test Doctor 1', 'Original concern');
        $foreign = (new Encrypter(random_bytes(32), 'AES-256-CBC'))->encryptString('Foreign key concern');
        DB::table('appointments')->where('id', $appointment->id)->update(['patient_concern' => $foreign]);

        $this->assertSame(PatientDataEncryption::MALFORMED, PatientDataEncryption::classify($foreign));

        config(['app.debug' => false]);

        $response = $this->actingAs($patient)->get(route('patient.appointments.show', $appointment));

        $response->assertOk();
        $response->assertSee('Unverified');
        $this->assertStringNotContainsString($foreign, (string) $response->getContent());
        $this->assertStringNotContainsString('Foreign key concern', (string) $response->getContent());

        try {
            $appointment->fresh()->patient_concern;
            $this->fail('Ciphertext from an unknown key must not decrypt.');
        } catch (UnreadablePatientDataException) {
            $this->assertTrue(true);
        }
    }

    public function test_legacy_plaintext_records_remain_readable_during_the_transition(): void
    {
        $patient = $this->patientUser();
        $appointment = $this->appointmentFor($patient, 'Test Doctor 1', null);
        $this->storeRawConcern($appointment, 'Legacy plaintext concern');

        $this->assertSame(PatientDataEncryption::PLAINTEXT, PatientDataEncryption::classify('Legacy plaintext concern'));
        $this->assertSame('Legacy plaintext concern', $appointment->fresh()->patient_concern);

        $this->actingAs($patient)
            ->get(route('patient.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('Legacy plaintext concern');

        $staff = $this->staffUser(['Development Department A']);

        $this->actingAs($staff)
            ->get(route('staff.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('Legacy plaintext concern');
    }

    public function test_plaintext_is_refused_once_the_transition_is_closed(): void
    {
        $appointment = $this->appointmentFor($this->patientUser(), 'Test Doctor 1', null);
        $this->storeRawConcern($appointment, 'Legacy plaintext concern');
        config(['patient_data.allow_legacy_plaintext' => false]);

        $this->expectException(UnreadablePatientDataException::class);

        $appointment->fresh()->patient_concern;
    }

    public function test_conversion_requires_explicit_execution_and_a_confirmed_backup(): void
    {
        $appointment = $this->appointmentFor($this->patientUser(), 'Test Doctor 1', null);
        $this->storeRawConcern($appointment, 'Legacy plaintext concern');

        $this->artisan('patient-data:encrypt')->assertSuccessful();
        $this->assertSame('Legacy plaintext concern', $this->rawConcern($appointment));

        $this->artisan('patient-data:encrypt', ['--execute' => true])->assertFailed();
        $this->assertSame('Legacy plaintext concern', $this->rawConcern($appointment));
    }

    public function test_conversion_encrypts_legacy_rows_and_is_idempotent_without_double_encryption(): void
    {
        $legacy = $this->legacyAppointments(['Ubo at sipon', 'Back pain', 'Headache']);
        $alreadyEncrypted = $this->appointmentFor($this->patientUser(), 'Test Doctor 2', 'Already encrypted concern');
        $encryptedBefore = $this->rawConcern($alreadyEncrypted);

        $this->runConversion(['--column' => ['appointments.patient_concern']]);

        foreach ($legacy as $concern => $appointment) {
            $raw = $this->rawConcern($appointment);

            $this->assertSame(PatientDataEncryption::ENCRYPTED, PatientDataEncryption::classify($raw));
            $this->assertSame($concern, Crypt::decryptString($raw), 'Values must be encrypted exactly once.');
            $this->assertSame($concern, $appointment->fresh()->patient_concern);
        }

        $this->assertSame($encryptedBefore, $this->rawConcern($alreadyEncrypted));

        $snapshot = DB::table('appointments')->orderBy('id')->pluck('patient_concern', 'id')->all();
        $this->runConversion(['--column' => ['appointments.patient_concern']]);

        $this->assertSame($snapshot, DB::table('appointments')->orderBy('id')->pluck('patient_concern', 'id')->all());
    }

    public function test_conversion_resumes_after_an_interrupted_run(): void
    {
        $legacy = $this->legacyAppointments(['First legacy', 'Second legacy', 'Third legacy']);

        $this->runConversion(['--column' => ['appointments.patient_concern'], '--limit' => 1]);

        $states = collect($legacy)->map(fn (Appointment $appointment): string => PatientDataEncryption::classify($this->rawConcern($appointment)));
        $this->assertSame(1, $states->filter(fn (string $state): bool => $state === PatientDataEncryption::ENCRYPTED)->count());
        $this->assertSame(2, $states->filter(fn (string $state): bool => $state === PatientDataEncryption::PLAINTEXT)->count());

        foreach ($legacy as $concern => $appointment) {
            $this->assertSame($concern, $appointment->fresh()->patient_concern, 'Mixed state must stay readable.');
        }

        $this->runConversion(['--column' => ['appointments.patient_concern']]);

        foreach ($legacy as $concern => $appointment) {
            $this->assertSame(PatientDataEncryption::ENCRYPTED, PatientDataEncryption::classify($this->rawConcern($appointment)));
            $this->assertSame($concern, $appointment->fresh()->patient_concern);
        }
    }

    public function test_conversion_reports_malformed_rows_by_id_without_printing_or_replacing_values(): void
    {
        $legacy = $this->legacyAppointments(['Readable legacy concern']);
        $broken = $this->appointmentFor($this->patientUser(), 'Test Doctor 2', 'Will be corrupted');
        $payload = json_decode(base64_decode($this->rawConcern($broken)), true);
        $payload['mac'] = str_repeat('0', 64);
        $corrupted = base64_encode((string) json_encode($payload));
        $this->storeRawConcern($broken, $corrupted);

        Artisan::call('patient-data:encrypt', [
            '--execute' => true,
            '--backup-confirmed' => true,
            '--column' => ['appointments.patient_concern'],
        ]);
        $output = Artisan::output();

        $this->assertStringContainsString("row IDs {$broken->id}", $output);
        $this->assertStringNotContainsString($corrupted, $output);
        $this->assertStringNotContainsString('Readable legacy concern', $output);
        $this->assertSame($corrupted, $this->rawConcern($broken));
        $this->assertSame(
            PatientDataEncryption::ENCRYPTED,
            PatientDataEncryption::classify($this->rawConcern($legacy['Readable legacy concern'])),
        );
    }

    public function test_conversion_never_overwrites_a_value_changed_after_it_was_read(): void
    {
        $appointment = $this->legacyAppointments(['Original legacy concern'])['Original legacy concern'];
        $edited = false;

        DB::beforeExecuting(function (string $sql) use (&$edited, $appointment): void {
            if ($edited || ! str_starts_with(strtolower($sql), 'update "appointments"')) {
                return;
            }

            $edited = true;
            DB::table('appointments')->where('id', $appointment->id)->update(['patient_concern' => 'Edited concurrently']);
        });

        $this->artisan('patient-data:encrypt', [
            '--execute' => true,
            '--backup-confirmed' => true,
            '--column' => ['appointments.patient_concern'],
        ])->assertFailed();

        $this->assertTrue($edited);
        $this->assertSame('Edited concurrently', $this->rawConcern($appointment));

        $this->runConversion(['--column' => ['appointments.patient_concern']]);

        $this->assertSame('Edited concurrently', Crypt::decryptString($this->rawConcern($appointment)));
    }

    public function test_conversion_preserves_record_timestamps(): void
    {
        $appointment = $this->legacyAppointments(['Timestamp concern'])['Timestamp concern'];
        $user = $this->patientUser();
        DB::table('appointments')->where('id', $appointment->id)->update(['updated_at' => '2026-09-01 10:00:00']);
        DB::table('patients')->where('id', $user->patient->id)->update(['updated_at' => '2026-09-02 11:00:00']);
        config(['patient_data.encrypt_profile_fields' => true]);

        $this->runConversion([]);

        $this->assertSame('2026-09-01 10:00:00', DB::table('appointments')->where('id', $appointment->id)->value('updated_at'));
        $this->assertSame('2026-09-02 11:00:00', DB::table('patients')->where('id', $user->patient->id)->value('updated_at'));
        $this->assertSame(PatientDataEncryption::ENCRYPTED, PatientDataEncryption::classify($this->rawConcern($appointment)));
    }

    public function test_reverse_conversion_restores_plaintext_for_a_controlled_rollback(): void
    {
        $appointment = $this->appointmentFor($this->patientUser(), 'Test Doctor 1', 'Rollback concern');

        $this->runConversion(['--column' => ['appointments.patient_concern'], '--reverse' => true]);

        $this->assertSame('Rollback concern', $this->rawConcern($appointment));
        $this->assertSame('Rollback concern', $appointment->fresh()->patient_concern);
    }

    public function test_profile_fields_stay_plaintext_until_explicitly_activated(): void
    {
        $user = $this->patientUser();
        $raw = DB::table('patients')->where('id', $user->patient->id)->first();

        $this->assertSame(PatientDataEncryption::PLAINTEXT, PatientDataEncryption::classify($raw->contact_number));
        $this->assertSame('1990-01-15', $raw->date_of_birth);
        $this->assertTrue($user->patient->fresh()->date_of_birth->equalTo('1990-01-15'));

        $this->runConversion(['--column' => ['patients.contact_number']]);

        $this->assertSame($raw->contact_number, DB::table('patients')->where('id', $user->patient->id)->value('contact_number'));
    }

    public function test_activated_profile_encryption_protects_registration_and_still_displays_the_profile(): void
    {
        config(['patient_data.encrypt_profile_fields' => true]);

        $this->post(route('register.store'), [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'date_of_birth' => '2001-03-09',
            'sex' => Sex::Female->value,
            'contact_number' => '+639171112222',
            'email' => 'maria.encrypted@example.com',
            'password' => 'MediGuide@2026',
            'password_confirmation' => 'MediGuide@2026',
        ])->assertRedirect(route('login'));

        $user = User::query()->where('email', 'maria.encrypted@example.com')->firstOrFail();
        $raw = DB::table('patients')->where('user_id', $user->id)->first();

        $this->assertSame(PatientDataEncryption::ENCRYPTED, PatientDataEncryption::classify($raw->contact_number));
        $this->assertSame(PatientDataEncryption::ENCRYPTED, PatientDataEncryption::classify($raw->date_of_birth));
        $this->assertStringNotContainsString('09171112222', $raw->contact_number);
        $this->assertSame('09171112222', $user->patient->contact_number);
        $this->assertTrue($user->patient->date_of_birth->equalTo('2001-03-09'));

        $this->actingAs($user)
            ->get(route('patient.profile'))
            ->assertOk()
            ->assertSee('09171112222')
            ->assertSee('March 9, 2001');
    }

    public function test_activated_profile_conversion_encrypts_legacy_profiles(): void
    {
        $user = $this->patientUser();
        config(['patient_data.encrypt_profile_fields' => true]);

        $this->runConversion(['--column' => ['patients.contact_number', 'patients.date_of_birth']]);

        $raw = DB::table('patients')->where('id', $user->patient->id)->first();
        $patient = Patient::query()->findOrFail($user->patient->id);

        $this->assertSame(PatientDataEncryption::ENCRYPTED, PatientDataEncryption::classify($raw->contact_number));
        $this->assertSame(PatientDataEncryption::ENCRYPTED, PatientDataEncryption::classify($raw->date_of_birth));
        $this->assertSame($user->patient->contact_number, $patient->contact_number);
        $this->assertTrue($patient->date_of_birth->equalTo('1990-01-15'));
    }

    public function test_rolling_back_the_widening_migration_is_refused_while_ciphertext_exists(): void
    {
        $user = $this->patientUser();
        config(['patient_data.encrypt_profile_fields' => true]);
        $user->patient->update(['contact_number' => '09170000000']);
        $migration = require database_path('migrations/2026_10_27_100000_widen_patient_profile_columns_for_encryption.php');

        $this->expectExceptionMessage('Decrypt them before rolling back');

        $migration->down();
    }

    public function test_booking_stores_an_encrypted_concern_and_keeps_booking_rules(): void
    {
        $user = $this->patientUser();
        $payload = $this->bookingPayload('Masakit ang lalamunan ko since kahapon');

        $this->actingAs($user)
            ->post(route('patient.book-appointment.store'), $payload)
            ->assertRedirect(route('patient.appointments'));

        $appointment = Appointment::query()->sole();

        $this->assertSame(AppointmentStatus::Pending, $appointment->status);
        $this->assertSame(PatientDataEncryption::ENCRYPTED, PatientDataEncryption::classify($this->rawConcern($appointment)));

        $this->actingAs($user)
            ->get(route('patient.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('Masakit ang lalamunan ko since kahapon');

        $this->actingAs($this->patientUser())
            ->post(route('patient.book-appointment.store'), $payload)
            ->assertRedirect()
            ->assertSessionHas('booking_error');

        $this->assertSame(1, Appointment::query()->count(), 'Double-booking prevention must still apply.');
    }

    public function test_notifications_work_and_never_copy_the_concern(): void
    {
        $user = $this->patientUser();

        $this->actingAs($user)
            ->post(route('patient.book-appointment.store'), $this->bookingPayload('Private symptom description'));

        $appointment = Appointment::query()->sole();
        $staff = $this->staffUser(['Development Department A']);

        $this->actingAs($staff)
            ->patch(route('staff.appointments.confirm', $appointment))
            ->assertRedirect();

        $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);
        $this->assertSame(2, $user->notifications()->count());

        foreach (DB::table('notifications')->pluck('data') as $data) {
            $this->assertStringNotContainsString('Private symptom description', $data);
            $this->assertStringNotContainsString($user->patient->contact_number, $data);
        }

        $this->actingAs($user)
            ->get(route('patient.notifications'))
            ->assertOk()
            ->assertSee('Appointment Confirmed');
    }

    public function test_decrypted_concerns_are_only_shown_to_authorized_owners(): void
    {
        $owner = $this->patientUser();
        $appointment = $this->appointmentFor($owner, 'Test Doctor 1', 'Owner-only concern', status: AppointmentStatus::Confirmed);

        $doctorUser = User::factory()->role(RoleName::Doctor)->create();
        Doctor::query()->where('display_name', 'Test Doctor 1')->update(['user_id' => $doctorUser->id]);
        $otherDoctor = User::factory()->role(RoleName::Doctor)->create();
        Doctor::query()->where('display_name', 'Test Doctor 2')->update(['user_id' => $otherDoctor->id]);

        $this->actingAs($owner)->get(route('patient.appointments.show', $appointment))->assertOk()->assertSee('Owner-only concern');
        $this->actingAs($this->staffUser(['Development Department A']))->get(route('staff.appointments.show', $appointment))->assertOk()->assertSee('Owner-only concern');
        $this->actingAs($doctorUser)->get(route('doctor.appointments.show', $appointment))->assertOk()->assertSee('Owner-only concern');

        $this->actingAs($this->patientUser())->get(route('patient.appointments.show', $appointment))->assertNotFound();
        $this->actingAs($this->staffUser(['Development Department B']))->get(route('staff.appointments.show', $appointment))->assertNotFound();
        $this->actingAs($this->staffUser([]))->get(route('staff.appointments.show', $appointment))->assertNotFound();
        $this->actingAs($otherDoctor)->get(route('doctor.appointments.show', $appointment))->assertNotFound();
        $this->actingAs($otherDoctor)->get(route('doctor.appointments'))->assertOk()->assertDontSee('Owner-only concern');
    }

    public function test_model_serialization_does_not_expose_decrypted_or_encrypted_patient_data(): void
    {
        $user = $this->patientUser();
        $appointment = $this->appointmentFor($user, 'Test Doctor 1', 'Serialized concern');
        $json = $appointment->fresh()->load('patient.user')->toJson();

        $this->assertStringNotContainsString('Serialized concern', $json);
        $this->assertStringNotContainsString('patient_concern', $json);
        $this->assertStringNotContainsString('contact_number', $json);
        $this->assertStringNotContainsString('date_of_birth', $json);
        $this->assertStringNotContainsString('password', $json);
    }

    public function test_encryption_keys_are_not_exposed(): void
    {
        $user = $this->patientUser();
        $appointment = $this->appointmentFor($user, 'Test Doctor 1', 'Key exposure check');
        $key = (string) config('app.key');
        $rawKey = base64_decode(Str::after($key, 'base64:'));

        $contents = [
            $this->actingAs($user)->get(route('patient.appointments.show', $appointment))->getContent(),
            $this->actingAs($user)->get(route('patient.profile'))->getContent(),
            $this->actingAs($this->staffUser(['Development Department A']))->get(route('staff.appointments.show', $appointment))->getContent(),
        ];

        Artisan::call('patient-data:encrypt');
        $contents[] = Artisan::output();

        foreach ($contents as $content) {
            $this->assertStringNotContainsString($key, (string) $content);
            $this->assertStringNotContainsString(Str::after($key, 'base64:'), (string) $content);
            $this->assertStringNotContainsString($rawKey, (string) $content);
        }
    }

    public function test_the_cast_rejects_unsupported_configuration(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EncryptedPatientData('integer');
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function runConversion(array $options): void
    {
        $this->artisan('patient-data:encrypt', [
            '--execute' => true,
            '--backup-confirmed' => true,
            ...$options,
        ])->assertSuccessful();
    }

    /**
     * @param  list<string>  $concerns
     * @return array<string, Appointment>
     */
    private function legacyAppointments(array $concerns): array
    {
        $appointments = [];
        $start = 9;

        foreach ($concerns as $concern) {
            $appointment = $this->appointmentFor($this->patientUser(), 'Test Doctor 1', null, sprintf('%02d:00:00', $start++));
            $this->storeRawConcern($appointment, $concern);
            $appointments[$concern] = $appointment;
        }

        return $appointments;
    }

    private function rawConcern(Appointment $appointment): ?string
    {
        return DB::table('appointments')->where('id', $appointment->id)->value('patient_concern');
    }

    private function storeRawConcern(Appointment $appointment, string $value): void
    {
        DB::table('appointments')->where('id', $appointment->id)->update(['patient_concern' => $value]);
        DB::table('record_integrity')
            ->where('subject_type', 'appointment')
            ->where('subject_id', $appointment->id)
            ->delete();
        DB::table('integrity_subjects')
            ->where('subject_type', 'appointment')
            ->where('subject_id', $appointment->id)
            ->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingPayload(string $concern): array
    {
        $doctor = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();

        return [
            'clinic_id' => Clinic::query()->where('name', 'Development Clinic A')->value('id'),
            'doctor_id' => $doctor->id,
            'doctor_schedule_id' => $this->scheduleFor($doctor)->id,
            'appointment_date' => '2026-10-05',
            'start_time' => '09:00',
            'patient_concern' => $concern,
        ];
    }

    private function scheduleFor(Doctor $doctor): DoctorSchedule
    {
        return DoctorSchedule::query()
            ->where('doctor_id', $doctor->id)
            ->where('day_of_week', DayOfWeek::Monday)
            ->where('start_time', '09:00:00')
            ->firstOrFail();
    }

    private function patientUser(): User
    {
        $this->sequence++;

        $user = User::factory()->role(RoleName::Patient)->create([
            'email' => "encryption-patient-{$this->sequence}@example.com",
        ]);

        $user->patient()->create([
            'date_of_birth' => '1990-01-15',
            'sex' => Sex::Female,
            'contact_number' => '0917'.str_pad((string) $this->sequence, 7, '0', STR_PAD_LEFT),
        ]);

        return $user->fresh(['patient', 'role']);
    }

    /**
     * @param  list<string>  $departmentNames
     */
    private function staffUser(array $departmentNames): User
    {
        $this->sequence++;

        $user = User::factory()->role(RoleName::HospitalStaff)->create([
            'email' => "encryption-staff-{$this->sequence}@example.com",
        ]);

        $user->hospitalStaff()->create()->departments()->sync(
            Department::query()->whereIn('name', $departmentNames)->pluck('id'),
        );

        return $user->fresh(['role']);
    }

    private function appointmentFor(
        User $patientUser,
        string $doctorName,
        ?string $concern,
        string $start = '09:00:00',
        AppointmentStatus $status = AppointmentStatus::Pending,
    ): Appointment {
        $doctor = Doctor::query()->where('display_name', $doctorName)->firstOrFail();
        $schedule = DoctorSchedule::query()->where('doctor_id', $doctor->id)->orderBy('id')->firstOrFail();
        $end = Carbon::createFromFormat('H:i:s', $start)->addMinutes(30)->format('H:i:s');

        return Appointment::query()->create([
            'patient_id' => $patientUser->patient->id,
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
