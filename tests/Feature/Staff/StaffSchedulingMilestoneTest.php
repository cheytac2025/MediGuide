<?php

namespace Tests\Feature\Staff;

use App\Enums\AppointmentMessageKind;
use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\DayOfWeek;
use App\Enums\DoctorScheduleStatus;
use App\Enums\DoctorStatus;
use App\Enums\RoleName;
use App\Enums\Sex;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\DoctorDateOverride;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\User;
use App\Services\Ai\AiSuggestedDoctorService;
use App\Services\AppointmentBookingService;
use App\Services\Scheduling\DoctorDateOverrideService;
use App\Support\AiGuidanceResult;
use App\Support\PatientDataEncryption;
use Database\Seeders\DevelopmentClinicSeeder;
use Database\Seeders\DevelopmentDepartmentSeeder;
use Database\Seeders\DevelopmentDoctorScheduleSeeder;
use Database\Seeders\DevelopmentDoctorSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StaffSchedulingMilestoneTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05 08:00:00');
        Http::preventStrayRequests();

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

    public function test_approval_note_is_optional_encrypted_and_visible_without_entering_the_audit_log(): void
    {
        $patient = $this->patient();
        $appointment = $this->appointment($patient, AppointmentStatus::Pending, '2026-10-12', '09:00:00', '09:30:00');
        $note = 'Bring the referral letter <script>alert(1)</script>';

        $this->actingAs($this->staff())
            ->patch(route('staff.appointments.confirm', $appointment), ['note' => $note])
            ->assertRedirect(route('staff.appointments.show', $appointment));

        $appointment->refresh();
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->status);
        $this->assertSame($note, $appointment->latestMessage(AppointmentMessageKind::ApprovalNote)?->body);

        $stored = (string) DB::table('appointment_messages')->where('appointment_id', $appointment->id)->value('body');
        $this->assertSame(PatientDataEncryption::ENCRYPTED, PatientDataEncryption::classify($stored));
        $this->assertStringNotContainsString($note, (string) DB::table('audit_events')->where('action', AuditAction::AppointmentConfirmed->value)->value('context'));

        $this->actingAs($patient->user)
            ->get(route('patient.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('Bring the referral letter')
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_confirmation_without_a_note_does_not_create_a_message(): void
    {
        $appointment = $this->appointment($this->patient(), AppointmentStatus::Pending, '2026-10-12', '09:00:00', '09:30:00');

        $this->actingAs($this->staff())
            ->patch(route('staff.appointments.confirm', $appointment))
            ->assertSessionHas('appointment_status');

        $this->assertSame(0, DB::table('appointment_messages')->count());
    }

    public function test_rejection_requires_a_patient_visible_reason_and_keeps_earlier_messages(): void
    {
        $patient = $this->patient();
        $appointment = $this->appointment($patient, AppointmentStatus::Pending, '2026-10-12', '09:00:00', '09:30:00');

        $this->actingAs($this->staff())
            ->from(route('staff.appointments.show', $appointment))
            ->patch(route('staff.appointments.reject', $appointment), ['reason' => '   '])
            ->assertSessionHasErrors('reason');
        $this->assertSame(AppointmentStatus::Pending, $appointment->fresh()->status);

        $this->actingAs($this->staff())
            ->from(route('staff.appointments.show', $appointment))
            ->patch(route('staff.appointments.reject', $appointment), ['reason' => str_repeat('A', 501)])
            ->assertSessionHasErrors('reason');
        $this->assertSame(AppointmentStatus::Pending, $appointment->fresh()->status);

        $this->actingAs($this->staff())
            ->patch(route('staff.appointments.reject', $appointment), ['reason' => 'The clinic is closed that morning.'])
            ->assertSessionHas('appointment_status');

        $this->assertSame(AppointmentStatus::Rejected, $appointment->fresh()->status);
        $this->actingAs($patient->user)
            ->get(route('patient.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('The clinic is closed that morning.')
            ->assertSee('Rejection reason');

        $this->assertStringNotContainsString(
            'The clinic is closed that morning.',
            (string) DB::table('audit_events')->where('action', AuditAction::AppointmentRejected->value)->value('context'),
        );
    }

    public function test_reschedule_keeps_identity_and_status_and_preserves_history(): void
    {
        $patient = $this->patient();
        $pending = $this->appointment($patient, AppointmentStatus::Pending, '2026-10-12', '09:00:00', '09:30:00');
        $confirmedPatient = $this->patient();
        $confirmed = $this->appointment($confirmedPatient, AppointmentStatus::Confirmed, '2026-10-12', '10:00:00', '10:30:00');
        $wednesday = $this->schedule(DayOfWeek::Wednesday, '13:00:00');
        $doctorUser = User::factory()->role(RoleName::Doctor)->create();
        $this->doctor()->update(['user_id' => $doctorUser->id]);

        $this->actingAs($this->staff())
            ->post(route('staff.appointments.reschedule.store', $pending), [
                'appointment_date' => '2026-10-07',
                'start_time' => '13:00',
                'doctor_schedule_id' => $wednesday->id,
                'reason' => 'Moved to the Wednesday clinic.',
            ])
            ->assertRedirect(route('staff.appointments.show', $pending));

        $pending->refresh();
        $this->assertSame($pending->id, $pending->id);
        $this->assertSame(AppointmentStatus::Pending, $pending->status);
        $this->assertSame('2026-10-07', $pending->appointment_date->toDateString());
        $this->assertSame('13:00:00', $pending->start_time);

        $this->actingAs($this->staff())
            ->post(route('staff.appointments.reschedule.store', $pending), [
                'appointment_date' => '2026-10-07',
                'start_time' => '13:30',
                'doctor_schedule_id' => $wednesday->id,
                'reason' => 'Moved thirty minutes later.',
            ])
            ->assertRedirect();

        $this->actingAs($patient->user)
            ->get(route('patient.appointments.show', $pending))
            ->assertOk()
            ->assertSee('Wednesday, October 7, 2026')
            ->assertSee('Moved to the Wednesday clinic.')
            ->assertSee('Moved thirty minutes later.');

        $this->actingAs($this->staff())
            ->post(route('staff.appointments.reschedule.store', $confirmed), [
                'appointment_date' => '2026-10-07',
                'start_time' => '14:00',
                'doctor_schedule_id' => $wednesday->id,
                'reason' => 'Confirmed visit moved.',
            ])
            ->assertRedirect();

        $this->assertSame(AppointmentStatus::Confirmed, $confirmed->fresh()->status);
        $this->actingAs($doctorUser)
            ->get(route('doctor.appointments.show', $confirmed))
            ->assertOk()
            ->assertSee('Wednesday, October 7, 2026')
            ->assertSee('02:00 PM')
            ->assertSee('Confirmed visit moved.');

        $this->assertStringNotContainsString(
            'Moved to the Wednesday clinic.',
            (string) DB::table('audit_events')->where('action', AuditAction::AppointmentRescheduled->value)->value('context'),
        );
    }

    public function test_reschedule_rejects_no_change_conflicts_terminal_states_and_rolls_back(): void
    {
        $booking = app(AppointmentBookingService::class);
        $patient = $this->patient();
        $appointment = $this->appointment($patient, AppointmentStatus::Pending, '2026-10-12', '09:00:00', '09:30:00');
        $monday = $this->schedule(DayOfWeek::Monday, '09:00:00');
        $other = $this->appointment($this->patient(), AppointmentStatus::Confirmed, '2026-10-12', '10:00:00', '10:30:00');

        $open = array_column($booking->openSlots($this->doctor(), '2026-10-12', $appointment->id), 'start');
        $blocked = array_column($booking->openSlots($this->doctor(), '2026-10-12'), 'start');
        $this->assertContains('09:00', $open);
        $this->assertNotContains('09:00', $blocked);

        try {
            $booking->reschedule($appointment, $monday, '2026-10-12', '09:00', 'Same time', $this->staff());
            $this->fail('A same-slot reschedule should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Choose a different date or time. This appointment is already scheduled then.',
                $exception->errors()['start_time'][0],
            );
        }

        try {
            $booking->reschedule($appointment, $monday, '2026-10-12', '10:00', 'Taken slot', $this->staff());
            $this->fail('An occupied slot should be rejected.');
        } catch (ValidationException) {
            $appointment->refresh();
            $this->assertSame('09:00:00', $appointment->start_time);
            $this->assertSame('10:00:00', $other->fresh()->start_time);
        }

        $event = 'eloquent.updated: '.Appointment::class;
        Event::listen($event, function (): void {
            throw new \RuntimeException('stop');
        });

        try {
            $booking->reschedule($appointment, $monday, '2026-10-12', '11:00', 'Should roll back', $this->staff());
            $this->fail('The reschedule should have stopped.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('stop', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $appointment->refresh();
        $this->assertSame('2026-10-12', $appointment->appointment_date->toDateString());
        $this->assertSame('09:00:00', $appointment->start_time);
        $this->assertSame(0, DB::table('appointment_messages')->where('appointment_id', $appointment->id)->count());
    }

    #[DataProvider('terminalStatuses')]
    public function test_terminal_appointments_cannot_be_rescheduled(AppointmentStatus $status): void
    {
        $appointment = $this->appointment($this->patient(), $status, '2026-09-28', '09:00:00', '09:30:00');

        $this->actingAs($this->staff())
            ->get(route('staff.appointments.reschedule', $appointment))
            ->assertRedirect(route('staff.appointments.show', $appointment))
            ->assertSessionHas('appointment_error');

        $this->assertSame($status, $appointment->fresh()->status);
        $this->assertSame('2026-09-28', $appointment->fresh()->appointment_date->toDateString());
    }

    public function test_other_departments_and_roles_cannot_reschedule_or_change_hours(): void
    {
        $appointment = $this->appointment($this->patient(), AppointmentStatus::Pending, '2026-10-06', '09:00:00', '09:30:00', 'Test Doctor 2');
        $doctor = Doctor::query()->where('display_name', 'Test Doctor 2')->firstOrFail();
        $outsider = $this->staff(['Development Department A']);

        $this->actingAs($outsider)
            ->post(route('staff.appointments.reschedule.store', $appointment), [
                'appointment_date' => '2026-10-06',
                'start_time' => '09:00',
                'doctor_schedule_id' => $this->schedule(DayOfWeek::Tuesday, '09:00:00', 'Test Doctor 2')->id,
                'reason' => 'Wrong department',
            ])
            ->assertNotFound();

        $this->actingAs($outsider)
            ->post(route('staff.doctor-schedules.date-overrides.store', $doctor), [
                'date' => '2026-10-06',
                'unavailable' => '1',
            ])
            ->assertNotFound();

        $this->actingAs(User::factory()->role(RoleName::Patient)->create())
            ->get(route('staff.appointments.reschedule', $appointment))
            ->assertForbidden();

        $this->assertSame('2026-10-06', $appointment->fresh()->appointment_date->toDateString());
    }

    public function test_date_overrides_change_effective_hours_without_changing_the_weekly_schedule(): void
    {
        $doctor = $this->doctor();
        $monday = $this->schedule(DayOfWeek::Monday, '09:00:00');
        $staff = $this->staff();

        $this->actingAs($staff)
            ->get(route('staff.doctor-schedules.show', $doctor))
            ->assertOk()
            ->assertSee('Weekly schedule')
            ->assertSee('future occurrences of that weekday')
            ->assertSee('This date only');

        $this->actingAs($staff)
            ->post(route('staff.doctor-schedules.date-overrides.store', $doctor), [
                'date' => '2026-10-12',
                'unavailable' => '0',
                'windows' => [
                    ['start_time' => '09:00', 'end_time' => '10:00', 'slot_duration' => 30],
                    ['start_time' => '11:00', 'end_time' => '12:00', 'slot_duration' => 30],
                ],
            ])
            ->assertRedirect();

        $booking = app(AppointmentBookingService::class);
        $slots = $booking->getAvailableSlots($doctor, $monday, '2026-10-12');
        $this->assertSame(['09:00', '09:30', '11:00', '11:30'], $slots);
        $this->assertSame('09:00:00', $monday->fresh()->start_time);
        $this->assertSame('12:00:00', $monday->fresh()->end_time);

        $this->actingAs($staff)
            ->post(route('staff.doctor-schedules.date-overrides.store', $doctor), [
                'date' => '2026-10-12',
                'unavailable' => '0',
                'windows' => [
                    ['start_time' => '09:00', 'end_time' => '11:00', 'slot_duration' => 30],
                    ['start_time' => '10:00', 'end_time' => '12:00', 'slot_duration' => 30],
                ],
            ])
            ->assertSessionHasErrors();
        $this->assertSame(['09:00', '09:30', '11:00', '11:30'], $booking->getAvailableSlots($doctor, $monday, '2026-10-12'));

        $this->actingAs($staff)
            ->post(route('staff.doctor-schedules.date-overrides.store', $doctor), [
                'date' => '2026-10-12',
                'unavailable' => '1',
            ])
            ->assertRedirect();
        $this->assertSame([], $booking->getAvailableSlots($doctor, $monday, '2026-10-12'));

        $this->actingAs($staff)
            ->delete(route('staff.doctor-schedules.date-overrides.destroy', $doctor), ['date' => '2026-10-12'])
            ->assertRedirect();
        $this->assertContains('09:00', $booking->getAvailableSlots($doctor, $monday, '2026-10-12'));
        $this->assertSame(0, DoctorDateOverride::query()->where('doctor_id', $doctor->id)->whereNull('removed_at')->count());
        $this->assertGreaterThan(0, DoctorDateOverride::query()->where('doctor_id', $doctor->id)->whereNotNull('removed_at')->count());
    }

    public function test_schedule_changes_do_not_move_existing_appointments(): void
    {
        $doctor = $this->doctor();
        $appointment = $this->appointment($this->patient(), AppointmentStatus::Pending, '2026-10-12', '09:00:00', '09:30:00');

        $this->actingAs($this->staff())
            ->from(route('staff.doctor-schedules.show', $doctor))
            ->post(route('staff.doctor-schedules.date-overrides.store', $doctor), [
                'date' => '2026-10-12',
                'unavailable' => '1',
            ])
            ->assertSessionHasErrors('date');

        $appointment->refresh();
        $this->assertSame(AppointmentStatus::Pending, $appointment->status);
        $this->assertSame('09:00:00', $appointment->start_time);
        $this->assertSame(0, DoctorDateOverride::query()->where('doctor_id', $doctor->id)->whereNull('removed_at')->count());

        $this->actingAs($this->staff())
            ->post(route('staff.doctor-schedules.date-overrides.store', $doctor), [
                'date' => '2026-10-12',
                'unavailable' => '0',
                'windows' => [
                    ['start_time' => '09:00', 'end_time' => '10:00', 'slot_duration' => 30],
                ],
            ])
            ->assertSessionHas('schedule_status');

        $this->assertSame('09:00:00', $appointment->fresh()->start_time);
    }

    public function test_patient_staff_and_suggested_doctors_share_the_effective_schedule(): void
    {
        $doctor = $this->doctor();
        $monday = $this->schedule(DayOfWeek::Monday, '09:00:00');
        $staff = $this->staff();

        app(DoctorDateOverrideService::class)->save($doctor, '2026-10-05', true, [], $staff);

        $booking = app(AppointmentBookingService::class);
        $this->assertSame([], $booking->getAvailableSlots($doctor, $monday, '2026-10-05'));
        $this->assertSame([], $booking->openSlots($doctor, '2026-10-05'));
        $this->assertSame(
            ['date' => '2026-10-07', 'time' => '13:00'],
            $booking->nextAvailableSlot($doctor),
        );

        $clinic = Clinic::query()->where('name', 'Development Clinic A')->firstOrFail();
        $guidance = app(AiSuggestedDoctorService::class)->forGuidance(AiGuidanceResult::fromPayload([
            'type' => 'recommendation',
            'clinic_id' => $clinic->id,
            'specialization' => DevelopmentDoctorSeeder::SPECIALIZATION_A,
            'reason' => 'The described problem matches this service.',
            'confidence' => 0.9,
        ]));
        $card = collect($guidance['doctors'])->firstWhere('id', $doctor->id);
        $this->assertIsArray($card);
        $this->assertTrue($card['bookable']);
        $this->assertStringContainsString('Oct 7, 2026', (string) $card['next_available']);

        $patient = $this->patient();
        $this->actingAs($patient->user)
            ->get(route('patient.book-appointment', [
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
                'appointment_date' => '2026-10-05',
            ]))
            ->assertOk()
            ->assertDontSee('start_time=09%3A00', false);

        $appointment = $this->appointment($patient, AppointmentStatus::Pending, '2026-10-12', '09:00:00', '09:30:00');
        $this->actingAs($staff)
            ->get(route('staff.appointments.reschedule', $appointment))
            ->assertOk()
            ->assertSee('Choose a new date')
            ->assertDontSee('appointment_date=2026-10-05', false);
    }

    public function test_provisional_and_appointment_only_doctors_do_not_gain_ordinary_public_slots(): void
    {
        $clinic = Clinic::query()->where('name', 'Development Clinic A')->firstOrFail();
        $provisional = Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'display_name' => 'Provisional Hours Doctor',
            'specialization' => 'Demonstration',
            'status' => DoctorStatus::Active,
        ]);
        $schedule = DoctorSchedule::query()->create([
            'doctor_id' => $provisional->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'slot_duration' => 30,
            'status' => DoctorScheduleStatus::Active,
            'provisional_demonstration' => true,
        ]);

        app(DoctorDateOverrideService::class)->save($provisional, '2026-10-12', false, [[
            'start_time' => '09:00',
            'end_time' => '09:30',
            'slot_duration' => 30,
        ]], $this->staff());

        $window = DoctorDateOverride::query()->where('doctor_id', $provisional->id)->whereNull('removed_at')->firstOrFail()->windows()->firstOrFail();
        $this->assertTrue($window->provisional_demonstration);
        $this->assertTrue($schedule->fresh()->provisional_demonstration);

        config(['hospital.provisional_local_booking' => false]);
        $this->assertSame([], app(AppointmentBookingService::class)->getAvailableSlots($provisional, $schedule, '2026-10-12'));

        $appointmentOnly = Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'display_name' => 'Dr. Eclipse Jr., Eulalio',
            'specialization' => 'General Surgery',
            'status' => DoctorStatus::Active,
        ]);

        $this->actingAs($this->staff())
            ->post(route('staff.doctor-schedules.store', $appointmentOnly), [
                'day_of_week' => DayOfWeek::Monday->value,
                'start_time' => '09:00',
                'end_time' => '10:00',
                'slot_duration' => 30,
                'status' => DoctorScheduleStatus::Active->value,
            ])
            ->assertSessionHasErrors('doctor');

        $this->actingAs($this->staff())
            ->post(route('staff.doctor-schedules.date-overrides.store', $appointmentOnly), [
                'date' => '2026-10-12',
                'unavailable' => '0',
                'windows' => [
                    ['start_time' => '09:00', 'end_time' => '10:00', 'slot_duration' => 30],
                ],
            ])
            ->assertSessionHasErrors('doctor');

        $this->assertSame(0, DoctorSchedule::query()->where('doctor_id', $appointmentOnly->id)->count());
        $this->assertSame(0, DoctorDateOverride::query()->where('doctor_id', $appointmentOnly->id)->count());
    }

    public function test_appointment_list_filters_by_date_and_doctor(): void
    {
        $first = $this->patient('Ana', 'Reyes');
        $this->appointment($first, AppointmentStatus::Pending, '2026-10-12', '09:00:00', '09:30:00');
        $secondDoctor = Doctor::query()->where('display_name', 'Test Doctor 2')->firstOrFail();
        $other = $this->patient('Bea', 'Cruz');
        Appointment::query()->create([
            'patient_id' => $other->id,
            'doctor_id' => $secondDoctor->id,
            'doctor_schedule_id' => $this->schedule(DayOfWeek::Tuesday, '09:00:00', 'Test Doctor 2')->id,
            'appointment_date' => '2026-10-06',
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'status' => AppointmentStatus::Pending,
        ]);

        $this->actingAs($this->staff())
            ->get(route('staff.appointments', [
                'date' => '2026-10-12',
                'doctor' => $this->doctor()->id,
                'status' => AppointmentStatus::Pending->value,
            ]))
            ->assertOk()
            ->assertSee('Ana Reyes')
            ->assertDontSee('Bea Cruz');

        $this->actingAs($this->staff())
            ->get(route('staff.appointments.show', [
                'appointment' => $first->appointments()->firstOrFail(),
                'date' => '2026-10-12',
                'doctor' => $this->doctor()->id,
            ]))
            ->assertOk()
            ->assertSee('date=2026-10-12', false);
    }

    /**
     * @return array<string, array{0: AppointmentStatus}>
     */
    public static function terminalStatuses(): array
    {
        return [
            'rejected' => [AppointmentStatus::Rejected],
            'cancelled' => [AppointmentStatus::Cancelled],
            'completed' => [AppointmentStatus::Completed],
        ];
    }

    private function appointment(
        Patient $patient,
        AppointmentStatus $status,
        string $date,
        string $start,
        string $end,
        string $doctor = 'Test Doctor 1',
    ): Appointment {
        $day = strtolower(Carbon::parse($date)->format('l'));

        return Appointment::query()->create([
            'patient_id' => $patient->id,
            'doctor_id' => Doctor::query()->where('display_name', $doctor)->firstOrFail()->id,
            'doctor_schedule_id' => DoctorSchedule::query()
                ->where('doctor_id', Doctor::query()->where('display_name', $doctor)->value('id'))
                ->where('day_of_week', $day)
                ->where('status', DoctorScheduleStatus::Active)
                ->orderBy('start_time')
                ->value('id'),
            'appointment_date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'status' => $status,
        ]);
    }

    private function schedule(DayOfWeek $day, string $start, string $doctor = 'Test Doctor 1'): DoctorSchedule
    {
        return DoctorSchedule::query()
            ->where('doctor_id', Doctor::query()->where('display_name', $doctor)->value('id'))
            ->where('day_of_week', $day)
            ->where('start_time', $start)
            ->firstOrFail();
    }

    private function doctor(): Doctor
    {
        return Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();
    }

    /**
     * @param  list<string>  $departments
     */
    private function staff(array $departments = ['Development Department A', 'Development Department B']): User
    {
        $this->sequence++;
        $user = User::factory()->role(RoleName::HospitalStaff)->create([
            'email' => "milestone-staff-{$this->sequence}@example.com",
        ]);
        $user->hospitalStaff()->create()->departments()->sync(
            Department::query()->whereIn('name', $departments)->pluck('id'),
        );

        return $user;
    }

    private function patient(string $first = 'Juan', string $last = 'Dela Cruz'): Patient
    {
        $this->sequence++;
        $user = User::factory()->role(RoleName::Patient)->create([
            'first_name' => $first,
            'last_name' => $last,
            'name' => $first.' '.$last,
            'email' => "milestone-patient-{$this->sequence}@example.com",
        ]);
        $user->patient()->create([
            'date_of_birth' => '1990-01-15',
            'sex' => Sex::Male,
            'contact_number' => '0917'.str_pad((string) $this->sequence, 7, '0', STR_PAD_LEFT),
        ]);

        return $user->patient;
    }
}
