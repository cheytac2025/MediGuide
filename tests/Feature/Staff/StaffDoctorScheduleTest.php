<?php

namespace Tests\Feature\Staff;

use App\Enums\AppointmentStatus;
use App\Enums\DayOfWeek;
use App\Enums\DoctorScheduleStatus;
use App\Enums\DoctorStatus;
use App\Enums\RoleName;
use App\Enums\Sex;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\User;
use App\Services\AppointmentBookingService;
use Database\Seeders\DevelopmentClinicSeeder;
use Database\Seeders\DevelopmentDepartmentSeeder;
use Database\Seeders\DevelopmentDoctorScheduleSeeder;
use Database\Seeders\DevelopmentDoctorSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StaffDoctorScheduleTest extends TestCase
{
    use RefreshDatabase;

    private int $patientSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05 08:00:00'); // Monday

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

    public function test_guests_cannot_access_doctor_schedule_management(): void
    {
        $this->get(route('staff.doctor-schedules'))
            ->assertRedirect(route('login'));
    }

    public function test_patients_cannot_access_doctor_schedule_management(): void
    {
        $this->actingAs(User::factory()->role(RoleName::Patient)->create())
            ->get(route('staff.doctor-schedules'))
            ->assertForbidden();
    }

    public function test_doctors_cannot_access_doctor_schedule_management(): void
    {
        $this->actingAs(User::factory()->role(RoleName::Doctor)->create())
            ->get(route('staff.doctor-schedules'))
            ->assertForbidden();
    }

    public function test_it_administrators_cannot_access_doctor_schedule_management(): void
    {
        $this->actingAs(User::factory()->role(RoleName::ItAdministrator)->create())
            ->get(route('staff.doctor-schedules'))
            ->assertForbidden();
    }

    public function test_hospital_staff_can_access_doctor_schedule_management(): void
    {
        $this->actingAs($this->staffUser())
            ->get(route('staff.doctor-schedules'))
            ->assertOk()
            ->assertSee('Doctor Schedules')
            ->assertSee('Test Doctor 1')
            ->assertSee('Manage Schedule')
            ->assertDontSee('Test Doctor 3'); // inactive
    }

    public function test_staff_sees_active_doctors(): void
    {
        $this->actingAs($this->staffUser())
            ->get(route('staff.doctor-schedules'))
            ->assertOk()
            ->assertSee('Test Doctor 1')
            ->assertSee('Test Doctor 2')
            ->assertDontSee('Test Doctor 3');
    }

    public function test_staff_can_view_selected_doctors_schedules(): void
    {
        $doctor = $this->doctorOne();

        $this->actingAs($this->staffUser())
            ->get(route('staff.doctor-schedules.show', $doctor))
            ->assertOk()
            ->assertSee($doctor->display_name)
            ->assertSee('MONDAY')
            ->assertSee('WEDNESDAY')
            ->assertSee('+ Add Schedule');
    }

    public function test_staff_can_add_a_valid_schedule(): void
    {
        $doctor = $this->doctorOne();

        $this->actingAs($this->staffUser())
            ->post(route('staff.doctor-schedules.store', $doctor), $this->payload(
                day: DayOfWeek::Friday,
                start: '09:00',
                end: '11:00',
            ))
            ->assertRedirect(route('staff.doctor-schedules.show', $doctor))
            ->assertSessionHas('schedule_status', 'Doctor schedule added successfully.');

        $this->assertDatabaseHas('doctor_schedules', [
            'doctor_id' => $doctor->id,
            'day_of_week' => DayOfWeek::Friday->value,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'slot_duration' => 30,
            'status' => DoctorScheduleStatus::Active->value,
        ]);
    }

    public function test_added_schedule_belongs_to_the_selected_doctor(): void
    {
        $doctor = $this->doctorOne();
        $other = Doctor::query()->where('display_name', 'Test Doctor 2')->firstOrFail();

        $this->actingAs($this->staffUser())
            ->post(route('staff.doctor-schedules.store', $doctor), $this->payload(
                day: DayOfWeek::Thursday,
                start: '08:00',
                end: '10:00',
            ));

        $created = DoctorSchedule::query()
            ->where('day_of_week', DayOfWeek::Thursday)
            ->where('start_time', '08:00:00')
            ->firstOrFail();

        $this->assertSame($doctor->id, $created->doctor_id);
        $this->assertNotSame($other->id, $created->doctor_id);
    }

    public function test_staff_can_edit_a_valid_schedule(): void
    {
        $schedule = $this->mondaySchedule();

        $this->actingAs($this->staffUser())
            ->patch(route('staff.doctor-schedules.update', $schedule), $this->payload(
                day: DayOfWeek::Monday,
                start: '08:00',
                end: '11:00',
            ))
            ->assertRedirect(route('staff.doctor-schedules.show', $schedule->doctor_id))
            ->assertSessionHas('schedule_status', 'Doctor schedule updated successfully.');

        $this->assertDatabaseHas('doctor_schedules', [
            'id' => $schedule->id,
            'doctor_id' => $schedule->doctor_id,
            'start_time' => '08:00:00',
            'end_time' => '11:00:00',
        ]);
    }

    public function test_doctor_cannot_be_changed_through_schedule_edit(): void
    {
        $schedule = $this->mondaySchedule();
        $other = Doctor::query()->where('display_name', 'Test Doctor 2')->firstOrFail();
        $originalDoctorId = $schedule->doctor_id;

        $this->actingAs($this->staffUser())
            ->patch(route('staff.doctor-schedules.update', $schedule), array_merge(
                $this->payload(day: DayOfWeek::Monday, start: '08:00', end: '11:00'),
                ['doctor_id' => $other->id],
            ));

        $this->assertSame($originalDoctorId, $schedule->fresh()->doctor_id);
        $this->assertNotSame($other->id, $schedule->fresh()->doctor_id);
    }

    public function test_invalid_start_end_range_is_rejected(): void
    {
        $doctor = $this->doctorOne();

        $this->actingAs($this->staffUser())
            ->from(route('staff.doctor-schedules.show', $doctor))
            ->post(route('staff.doctor-schedules.store', $doctor), $this->payload(
                day: DayOfWeek::Friday,
                start: '12:00',
                end: '09:00',
            ))
            ->assertRedirect(route('staff.doctor-schedules.show', $doctor))
            ->assertSessionHasErrors('end_time');
    }

    public function test_zero_slot_duration_is_rejected(): void
    {
        $doctor = $this->doctorOne();

        $this->actingAs($this->staffUser())
            ->post(route('staff.doctor-schedules.store', $doctor), $this->payload(
                day: DayOfWeek::Friday,
                start: '09:00',
                end: '11:00',
                slotDuration: 0,
            ))
            ->assertSessionHasErrors('slot_duration');
    }

    public function test_negative_slot_duration_is_rejected(): void
    {
        $doctor = $this->doctorOne();

        $this->actingAs($this->staffUser())
            ->post(route('staff.doctor-schedules.store', $doctor), $this->payload(
                day: DayOfWeek::Friday,
                start: '09:00',
                end: '11:00',
                slotDuration: -15,
            ))
            ->assertSessionHasErrors('slot_duration');
    }

    public function test_exact_duplicate_schedule_is_rejected(): void
    {
        $doctor = $this->doctorOne();

        $this->actingAs($this->staffUser())
            ->post(route('staff.doctor-schedules.store', $doctor), $this->payload(
                day: DayOfWeek::Monday,
                start: '09:00',
                end: '12:00',
            ))
            ->assertSessionHasErrors('start_time');
    }

    public function test_overlapping_active_schedule_is_rejected(): void
    {
        $doctor = $this->doctorOne();

        $response = $this->actingAs($this->staffUser())
            ->from(route('staff.doctor-schedules.show', $doctor))
            ->post(route('staff.doctor-schedules.store', $doctor), $this->payload(
                day: DayOfWeek::Monday,
                start: '11:00',
                end: '14:00',
            ));

        $response->assertRedirect(route('staff.doctor-schedules.show', $doctor));
        $response->assertSessionHasErrors('start_time');
        $this->assertStringContainsString(
            'overlaps another active schedule',
            session('errors')->first('start_time'),
        );
    }

    public function test_non_overlapping_schedule_is_allowed(): void
    {
        $doctor = $this->doctorOne();

        $this->actingAs($this->staffUser())
            ->post(route('staff.doctor-schedules.store', $doctor), $this->payload(
                day: DayOfWeek::Monday,
                start: '13:00',
                end: '16:00',
            ))
            ->assertRedirect(route('staff.doctor-schedules.show', $doctor))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('doctor_schedules', [
            'doctor_id' => $doctor->id,
            'day_of_week' => DayOfWeek::Monday->value,
            'start_time' => '13:00:00',
            'end_time' => '16:00:00',
        ]);
    }

    public function test_editing_schedule_does_not_conflict_with_itself(): void
    {
        $schedule = $this->mondaySchedule();

        $this->actingAs($this->staffUser())
            ->patch(route('staff.doctor-schedules.update', $schedule), $this->payload(
                day: DayOfWeek::Monday,
                start: '09:00',
                end: '12:00',
                slotDuration: 30,
            ))
            ->assertRedirect(route('staff.doctor-schedules.show', $schedule->doctor_id))
            ->assertSessionHas('schedule_status');
    }

    public function test_activating_overlapping_inactive_schedule_is_rejected(): void
    {
        $doctor = $this->doctorOne();

        $inactive = DoctorSchedule::query()->create([
            'doctor_id' => $doctor->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '10:00:00',
            'end_time' => '13:00:00',
            'slot_duration' => 30,
            'status' => DoctorScheduleStatus::Inactive,
        ]);

        $this->actingAs($this->staffUser())
            ->patch(route('staff.doctor-schedules.status', $inactive), [
                'status' => DoctorScheduleStatus::Active->value,
            ])
            ->assertRedirect(route('staff.doctor-schedules.show', $doctor))
            ->assertSessionHasErrors('start_time');

        $this->assertSame(DoctorScheduleStatus::Inactive, $inactive->fresh()->status);
    }

    public function test_safe_schedule_can_be_deactivated(): void
    {
        $schedule = $this->mondaySchedule();

        $this->actingAs($this->staffUser())
            ->patch(route('staff.doctor-schedules.status', $schedule), [
                'status' => DoctorScheduleStatus::Inactive->value,
            ])
            ->assertRedirect(route('staff.doctor-schedules.show', $schedule->doctor_id))
            ->assertSessionHas('schedule_status', 'Doctor schedule deactivated successfully.');

        $this->assertSame(DoctorScheduleStatus::Inactive, $schedule->fresh()->status);
    }

    public function test_future_pending_appointment_blocks_deactivation(): void
    {
        $schedule = $this->mondaySchedule();
        $this->place($this->patient(), $schedule, AppointmentStatus::Pending, '2026-10-12', '09:00:00', '09:30:00');

        $this->actingAs($this->staffUser())
            ->patch(route('staff.doctor-schedules.status', $schedule), [
                'status' => DoctorScheduleStatus::Inactive->value,
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(DoctorScheduleStatus::Active, $schedule->fresh()->status);
    }

    public function test_future_confirmed_appointment_blocks_deactivation(): void
    {
        $schedule = $this->mondaySchedule();
        $this->place($this->patient(), $schedule, AppointmentStatus::Confirmed, '2026-10-12', '09:00:00', '09:30:00');

        $this->actingAs($this->staffUser())
            ->patch(route('staff.doctor-schedules.status', $schedule), [
                'status' => DoctorScheduleStatus::Inactive->value,
            ])
            ->assertSessionHasErrors('status');
    }

    public function test_cancelled_appointment_does_not_block_deactivation(): void
    {
        $schedule = $this->mondaySchedule();
        $this->place($this->patient(), $schedule, AppointmentStatus::Cancelled, '2026-10-12', '09:00:00', '09:30:00');

        $this->actingAs($this->staffUser())
            ->patch(route('staff.doctor-schedules.status', $schedule), [
                'status' => DoctorScheduleStatus::Inactive->value,
            ])
            ->assertSessionHas('schedule_status', 'Doctor schedule deactivated successfully.');
    }

    public function test_rejected_appointment_does_not_block_deactivation(): void
    {
        $schedule = $this->mondaySchedule();
        $this->place($this->patient(), $schedule, AppointmentStatus::Rejected, '2026-10-12', '09:00:00', '09:30:00');

        $this->actingAs($this->staffUser())
            ->patch(route('staff.doctor-schedules.status', $schedule), [
                'status' => DoctorScheduleStatus::Inactive->value,
            ])
            ->assertSessionHas('schedule_status', 'Doctor schedule deactivated successfully.');
    }

    public function test_editing_start_time_that_invalidates_pending_appointment_is_rejected(): void
    {
        $schedule = $this->mondaySchedule();
        $appointment = $this->place(
            $this->patient(),
            $schedule,
            AppointmentStatus::Pending,
            '2026-10-12',
            '09:30:00',
            '10:00:00',
        );

        $this->actingAs($this->staffUser())
            ->patch(route('staff.doctor-schedules.update', $schedule), $this->payload(
                day: DayOfWeek::Monday,
                start: '10:00',
                end: '17:00',
            ))
            ->assertSessionHasErrors('start_time');

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'start_time' => '09:30:00',
            'status' => AppointmentStatus::Pending->value,
        ]);
    }

    public function test_editing_end_time_that_invalidates_confirmed_appointment_is_rejected(): void
    {
        $schedule = $this->mondaySchedule();
        $appointment = $this->place(
            $this->patient(),
            $schedule,
            AppointmentStatus::Confirmed,
            '2026-10-12',
            '11:00:00',
            '11:30:00',
        );

        $this->actingAs($this->staffUser())
            ->patch(route('staff.doctor-schedules.update', $schedule), $this->payload(
                day: DayOfWeek::Monday,
                start: '09:00',
                end: '10:30',
            ))
            ->assertSessionHasErrors('start_time');

        $this->assertDatabaseHas('appointments', ['id' => $appointment->id]);
    }

    public function test_changing_day_that_invalidates_future_appointment_is_rejected(): void
    {
        $schedule = $this->mondaySchedule();
        $appointment = $this->place(
            $this->patient(),
            $schedule,
            AppointmentStatus::Pending,
            '2026-10-12',
            '09:00:00',
            '09:30:00',
        );

        $this->actingAs($this->staffUser())
            ->patch(route('staff.doctor-schedules.update', $schedule), $this->payload(
                day: DayOfWeek::Tuesday,
                start: '09:00',
                end: '12:00',
            ))
            ->assertSessionHasErrors('start_time');

        $this->assertSame(DayOfWeek::Monday, $schedule->fresh()->day_of_week);
        $this->assertDatabaseHas('appointments', ['id' => $appointment->id]);
    }

    public function test_changing_slot_duration_that_invalidates_appointment_is_rejected(): void
    {
        $schedule = $this->mondaySchedule();
        $appointment = $this->place(
            $this->patient(),
            $schedule,
            AppointmentStatus::Confirmed,
            '2026-10-12',
            '09:30:00',
            '10:00:00',
        );

        $this->actingAs($this->staffUser())
            ->patch(route('staff.doctor-schedules.update', $schedule), $this->payload(
                day: DayOfWeek::Monday,
                start: '09:00',
                end: '12:00',
                slotDuration: 60,
            ))
            ->assertSessionHasErrors('start_time');

        $this->assertSame(30, $schedule->fresh()->slot_duration);
        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'start_time' => '09:30:00',
        ]);
    }

    public function test_schedule_edit_never_deletes_existing_appointment(): void
    {
        $schedule = $this->mondaySchedule();
        $appointment = $this->place(
            $this->patient(),
            $schedule,
            AppointmentStatus::Confirmed,
            '2026-10-12',
            '09:00:00',
            '09:30:00',
        );

        $this->actingAs($this->staffUser())
            ->patch(route('staff.doctor-schedules.update', $schedule), $this->payload(
                day: DayOfWeek::Monday,
                start: '10:00',
                end: '12:00',
            ));

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'doctor_schedule_id' => $schedule->id,
            'status' => AppointmentStatus::Confirmed->value,
        ]);
    }

    public function test_successful_schedule_change_is_visible_on_doctor_read_only_schedule_page(): void
    {
        $doctor = $this->doctorOne();
        $doctorUser = User::factory()->role(RoleName::Doctor)->create([
            'first_name' => 'Dev',
            'last_name' => 'Doctor',
            'name' => 'Dev Doctor',
        ]);
        $doctor->update(['user_id' => $doctorUser->id]);

        $this->actingAs($this->staffUser())
            ->post(route('staff.doctor-schedules.store', $doctor), $this->payload(
                day: DayOfWeek::Friday,
                start: '10:00',
                end: '12:00',
            ));

        $this->actingAs($doctorUser)
            ->get(route('doctor.schedule'))
            ->assertOk()
            ->assertSee('FRIDAY')
            ->assertSee('10:00 AM');
    }

    public function test_patient_available_slots_reflect_successful_schedule_change(): void
    {
        $schedule = $this->mondaySchedule();

        $before = app(AppointmentBookingService::class)->getAvailableSlots(
            $schedule->doctor,
            $schedule,
            '2026-10-12',
        );
        $this->assertContains('09:00', $before);
        $this->assertContains('11:30', $before);

        $this->actingAs($this->staffUser())
            ->patch(route('staff.doctor-schedules.update', $schedule), $this->payload(
                day: DayOfWeek::Monday,
                start: '09:00',
                end: '10:00',
                slotDuration: 30,
            ))
            ->assertSessionHas('schedule_status');

        $after = app(AppointmentBookingService::class)->getAvailableSlots(
            $schedule->doctor->fresh(),
            $schedule->fresh(),
            '2026-10-12',
        );

        $this->assertSame(['09:00', '09:30'], $after);
        $this->assertNotContains('11:30', $after);
    }

    public function test_patients_cannot_call_schedule_management_write_routes(): void
    {
        $doctor = $this->doctorOne();
        $schedule = $this->mondaySchedule();
        $patient = User::factory()->role(RoleName::Patient)->create();

        $this->actingAs($patient)
            ->post(route('staff.doctor-schedules.store', $doctor), $this->payload())
            ->assertForbidden();

        $this->actingAs($patient)
            ->patch(route('staff.doctor-schedules.update', $schedule), $this->payload())
            ->assertForbidden();

        $this->actingAs($patient)
            ->patch(route('staff.doctor-schedules.status', $schedule), [
                'status' => DoctorScheduleStatus::Inactive->value,
            ])
            ->assertForbidden();
    }

    public function test_doctors_cannot_call_schedule_management_write_routes(): void
    {
        $doctor = $this->doctorOne();
        $schedule = $this->mondaySchedule();
        $doctorUser = User::factory()->role(RoleName::Doctor)->create();

        $this->actingAs($doctorUser)
            ->post(route('staff.doctor-schedules.store', $doctor), $this->payload())
            ->assertForbidden();

        $this->actingAs($doctorUser)
            ->patch(route('staff.doctor-schedules.update', $schedule), $this->payload())
            ->assertForbidden();
    }

    public function test_it_admin_cannot_call_staff_schedule_management_routes(): void
    {
        $doctor = $this->doctorOne();
        $schedule = $this->mondaySchedule();
        $admin = User::factory()->role(RoleName::ItAdministrator)->create();

        $this->actingAs($admin)
            ->get(route('staff.doctor-schedules'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('staff.doctor-schedules.store', $doctor), $this->payload())
            ->assertForbidden();

        $this->actingAs($admin)
            ->patch(route('staff.doctor-schedules.update', $schedule), $this->payload())
            ->assertForbidden();
    }

    /**
     * @return array{day_of_week: string, start_time: string, end_time: string, slot_duration: int, status: string}
     */
    private function payload(
        DayOfWeek $day = DayOfWeek::Friday,
        string $start = '09:00',
        string $end = '11:00',
        int $slotDuration = 30,
        DoctorScheduleStatus $status = DoctorScheduleStatus::Active,
    ): array {
        return [
            'day_of_week' => $day->value,
            'start_time' => $start,
            'end_time' => $end,
            'slot_duration' => $slotDuration,
            'status' => $status->value,
        ];
    }

    private function doctorOne(): Doctor
    {
        return Doctor::query()
            ->where('display_name', 'Test Doctor 1')
            ->where('status', DoctorStatus::Active)
            ->firstOrFail();
    }

    private function mondaySchedule(): DoctorSchedule
    {
        return DoctorSchedule::query()
            ->where('doctor_id', $this->doctorOne()->id)
            ->where('day_of_week', DayOfWeek::Monday)
            ->where('start_time', '09:00:00')
            ->firstOrFail();
    }

    private function staffUser(): User
    {
        return User::factory()->role(RoleName::HospitalStaff)->create([
            'first_name' => 'Dev',
            'last_name' => 'Staff',
            'name' => 'Dev Staff',
            'email' => 'staff-schedules@example.com',
        ]);
    }

    private function patient(): Patient
    {
        $this->patientSequence++;

        $user = User::factory()->create([
            'email' => "staff-schedule-patient-{$this->patientSequence}@example.com",
        ]);

        return $user->patient()->create([
            'date_of_birth' => '1990-01-15',
            'sex' => Sex::Female,
            'contact_number' => '0917'.str_pad((string) $this->patientSequence, 7, '0', STR_PAD_LEFT),
        ]);
    }

    private function place(
        Patient $patient,
        DoctorSchedule $schedule,
        AppointmentStatus $status,
        string $date,
        string $start,
        string $end,
    ): Appointment {
        return Appointment::query()->create([
            'patient_id' => $patient->id,
            'doctor_id' => $schedule->doctor_id,
            'doctor_schedule_id' => $schedule->id,
            'appointment_date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'status' => $status,
        ]);
    }
}
