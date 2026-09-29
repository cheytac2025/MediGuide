<?php

namespace Tests\Feature\Patient;

use App\Enums\AppointmentStatus;
use App\Enums\DayOfWeek;
use App\Enums\RoleName;
use App\Enums\Sex;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\User;
use App\Notifications\AppointmentConfirmedNotification;
use App\Notifications\AppointmentRejectedNotification;
use App\Notifications\AppointmentSubmittedNotification;
use Database\Seeders\DevelopmentClinicSeeder;
use Database\Seeders\DevelopmentDepartmentSeeder;
use Database\Seeders\DevelopmentDoctorScheduleSeeder;
use Database\Seeders\DevelopmentDoctorSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PatientNotificationTest extends TestCase
{
    use RefreshDatabase;

    private int $patientSequence = 0;

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

    public function test_guests_cannot_access_notifications(): void
    {
        $this->get(route('patient.notifications'))
            ->assertRedirect(route('login'));
    }

    public function test_patients_can_access_notifications(): void
    {
        $this->actingAs($this->patientUser())
            ->get(route('patient.notifications'))
            ->assertOk()
            ->assertSee('Notifications')
            ->assertSee('Stay updated on your appointment requests.')
            ->assertSee('No notifications yet.');
    }

    public function test_non_patients_cannot_access_notifications(): void
    {
        foreach ([RoleName::HospitalStaff, RoleName::Doctor, RoleName::ItAdministrator] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get(route('patient.notifications'))
                ->assertForbidden();
        }
    }

    public function test_successful_booking_creates_submitted_notification(): void
    {
        $user = $this->patientUser();

        $this->actingAs($user)
            ->post(route('patient.book-appointment.store'), $this->bookingPayload())
            ->assertRedirect(route('patient.appointments'));

        $appointment = Appointment::query()->firstOrFail();
        $notification = $user->fresh()->notifications()->first();

        $this->assertNotNull($notification);
        $this->assertSame(AppointmentSubmittedNotification::class, $notification->type);
        $this->assertSame('Appointment Request Submitted', $notification->data['title']);
        $this->assertSame($appointment->id, $notification->data['appointment_id']);
        $this->assertSame('appointment_submitted', $notification->data['event']);
        $this->assertNull($notification->read_at);
        $this->assertStringContainsString('Test Doctor 1', $notification->data['message']);
    }

    public function test_failed_booking_creates_no_notification(): void
    {
        $user = $this->patientUser();

        $this->actingAs($user)
            ->post(route('patient.book-appointment.store'), [
                ...$this->bookingPayload(),
                'clinic_id' => 999999,
            ])
            ->assertRedirect();

        $this->assertSame(0, Appointment::query()->count());
        $this->assertSame(0, $user->fresh()->notifications()->count());
    }

    public function test_staff_confirmation_creates_confirmed_notification(): void
    {
        $patientUser = $this->patientUser();
        $appointment = $this->pendingAppointment($patientUser->patient);

        $this->actingAs($this->staffUser())
            ->patch(route('staff.appointments.confirm', $appointment))
            ->assertRedirect(route('staff.appointments.show', $appointment));

        $notification = $patientUser->fresh()->notifications()
            ->where('type', AppointmentConfirmedNotification::class)
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame('Appointment Confirmed', $notification->data['title']);
        $this->assertSame($appointment->id, $notification->data['appointment_id']);
        $this->assertNull($notification->read_at);
    }

    public function test_invalid_confirmation_creates_no_notification(): void
    {
        $patientUser = $this->patientUser();
        $appointment = $this->pendingAppointment($patientUser->patient);
        $appointment->update(['status' => AppointmentStatus::Cancelled]);

        $before = $patientUser->fresh()->notifications()->count();

        $this->actingAs($this->staffUser())
            ->patch(route('staff.appointments.confirm', $appointment))
            ->assertSessionHas('appointment_error');

        $this->assertSame($before, $patientUser->fresh()->notifications()->count());
        $this->assertSame(0, $patientUser->fresh()->notifications()
            ->where('type', AppointmentConfirmedNotification::class)
            ->count());
    }

    public function test_staff_rejection_creates_rejected_notification(): void
    {
        $patientUser = $this->patientUser();
        $appointment = $this->pendingAppointment($patientUser->patient);

        $this->actingAs($this->staffUser())
            ->patch(route('staff.appointments.reject', $appointment))
            ->assertRedirect(route('staff.appointments.show', $appointment));

        $notification = $patientUser->fresh()->notifications()
            ->where('type', AppointmentRejectedNotification::class)
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame('Appointment Request Rejected', $notification->data['title']);
        $this->assertSame($appointment->id, $notification->data['appointment_id']);
    }

    public function test_invalid_rejection_creates_no_duplicate_notification(): void
    {
        $patientUser = $this->patientUser();
        $appointment = $this->pendingAppointment($patientUser->patient);

        $this->actingAs($this->staffUser())
            ->patch(route('staff.appointments.reject', $appointment));

        $this->assertSame(1, $patientUser->fresh()->notifications()
            ->where('type', AppointmentRejectedNotification::class)
            ->count());

        $this->actingAs($this->staffUser())
            ->patch(route('staff.appointments.reject', $appointment))
            ->assertSessionHas('appointment_error');

        $this->assertSame(1, $patientUser->fresh()->notifications()
            ->where('type', AppointmentRejectedNotification::class)
            ->count());
    }

    public function test_patient_sees_only_own_notifications_newest_first(): void
    {
        $viewer = $this->patientUser();
        $other = $this->patientUser();

        $other->notify(new AppointmentSubmittedNotification($this->pendingAppointment($other->patient)));
        Carbon::setTestNow('2026-10-05 08:01:00');
        $first = $this->pendingAppointment($viewer->patient, '09:00:00', '09:30:00');
        $viewer->notify(new AppointmentSubmittedNotification($first));
        Carbon::setTestNow('2026-10-05 08:02:00');
        $second = $this->pendingAppointment($viewer->patient, '10:00:00', '10:30:00');
        $viewer->notify(new AppointmentConfirmedNotification($second));

        $response = $this->actingAs($viewer)->get(route('patient.notifications'));

        $response->assertOk();
        $response->assertSee('Appointment Confirmed');
        $response->assertSee('Appointment Request Submitted');
        $response->assertDontSee((string) $other->notifications()->first()->id);

        $ids = $viewer->fresh()->notifications()->latest()->pluck('id')->all();
        $this->assertSame($ids, $response->viewData('notifications')->pluck('id')->all());
    }

    public function test_patient_can_mark_own_notification_read_but_not_another_patients(): void
    {
        $owner = $this->patientUser();
        $other = $this->patientUser();
        $owner->notify(new AppointmentSubmittedNotification($this->pendingAppointment($owner->patient)));
        $other->notify(new AppointmentSubmittedNotification($this->pendingAppointment($other->patient)));

        $owned = $owner->fresh()->notifications()->firstOrFail();
        $foreign = $other->fresh()->notifications()->firstOrFail();

        $this->actingAs($owner)
            ->patch(route('patient.notifications.read', $owned))
            ->assertRedirect(route('patient.notifications'));

        $this->assertNotNull($owned->fresh()->read_at);

        $this->actingAs($owner)
            ->patch(route('patient.notifications.read', $foreign))
            ->assertNotFound();

        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_mark_all_as_read_affects_only_authenticated_patient(): void
    {
        $owner = $this->patientUser();
        $other = $this->patientUser();
        $owner->notify(new AppointmentSubmittedNotification($this->pendingAppointment($owner->patient)));
        $owner->notify(new AppointmentConfirmedNotification($this->pendingAppointment($owner->patient, '10:00:00', '10:30:00')));
        $other->notify(new AppointmentSubmittedNotification($this->pendingAppointment($other->patient)));

        $this->actingAs($owner)
            ->patch(route('patient.notifications.read-all'))
            ->assertRedirect(route('patient.notifications'));

        $this->assertSame(0, $owner->fresh()->unreadNotifications()->count());
        $this->assertSame(1, $other->fresh()->unreadNotifications()->count());
    }

    public function test_header_bell_uses_real_unread_count(): void
    {
        $user = $this->patientUser();
        $user->notify(new AppointmentSubmittedNotification($this->pendingAppointment($user->patient)));
        $user->notify(new AppointmentConfirmedNotification($this->pendingAppointment($user->patient, '10:00:00', '10:30:00')));

        $this->actingAs($user)
            ->get(route('patient.notifications'))
            ->assertOk()
            ->assertSee('href="'.route('patient.notifications').'"', false)
            ->assertSee('mg-badge-dot', false)
            ->assertSee('>2</span>', false);

        $user->unreadNotifications->markAsRead();

        $this->actingAs($user)
            ->get(route('patient.dashboard'))
            ->assertOk()
            ->assertDontSee('mg-badge-dot');
    }

    public function test_view_appointment_marks_read_and_opens_owned_appointment(): void
    {
        $user = $this->patientUser();
        $appointment = $this->pendingAppointment($user->patient);
        $user->notify(new AppointmentSubmittedNotification($appointment));
        $notification = $user->fresh()->notifications()->firstOrFail();

        $this->actingAs($user)
            ->get(route('patient.notifications.open', $notification))
            ->assertRedirect(route('patient.appointments.show', $appointment));

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_view_appointment_rejects_foreign_appointment_ownership(): void
    {
        $viewer = $this->patientUser();
        $other = $this->patientUser();
        $foreignAppointment = $this->pendingAppointment($other->patient);

        $viewer->notify(new AppointmentSubmittedNotification($foreignAppointment));
        $notification = $viewer->fresh()->notifications()->firstOrFail();
        $notification->forceFill([
            'data' => array_merge($notification->data, ['appointment_id' => $foreignAppointment->id]),
        ])->save();

        $this->actingAs($viewer)
            ->get(route('patient.notifications.open', $notification))
            ->assertRedirect(route('patient.notifications'))
            ->assertSessionHas('notification_error');
    }

    /**
     * @return array{clinic_id: int, doctor_id: int, doctor_schedule_id: int, appointment_date: string, start_time: string, patient_concern: string|null}
     */
    private function bookingPayload(string $start = '09:00'): array
    {
        $doctor = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();
        $clinicId = $doctor->clinic_id;
        $schedule = DoctorSchedule::query()
            ->where('doctor_id', $doctor->id)
            ->where('day_of_week', DayOfWeek::Monday)
            ->where('start_time', '09:00:00')
            ->firstOrFail();

        return [
            'clinic_id' => $clinicId,
            'doctor_id' => $doctor->id,
            'doctor_schedule_id' => $schedule->id,
            'appointment_date' => '2026-10-05',
            'start_time' => $start,
            'patient_concern' => 'Notification test concern',
        ];
    }

    private function pendingAppointment(Patient $patient, string $start = '09:00:00', string $end = '09:30:00'): Appointment
    {
        $doctor = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();
        $schedule = DoctorSchedule::query()
            ->where('doctor_id', $doctor->id)
            ->where('day_of_week', DayOfWeek::Monday)
            ->firstOrFail();

        return Appointment::query()->create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'doctor_schedule_id' => $schedule->id,
            'appointment_date' => '2026-10-12',
            'start_time' => $start,
            'end_time' => $end,
            'patient_concern' => 'Pending concern',
            'status' => AppointmentStatus::Pending,
        ]);
    }

    private function patientUser(): User
    {
        $this->patientSequence++;

        $user = User::factory()->role(RoleName::Patient)->create([
            'first_name' => 'Patient',
            'last_name' => 'Notify'.$this->patientSequence,
            'name' => 'Patient Notify'.$this->patientSequence,
            'email' => "notify-patient-{$this->patientSequence}@example.com",
        ]);

        $user->patient()->create([
            'date_of_birth' => '1990-01-15',
            'sex' => Sex::Male,
            'contact_number' => '0917'.str_pad((string) $this->patientSequence, 7, '0', STR_PAD_LEFT),
        ]);

        return $user->fresh(['patient', 'role']);
    }

    private function staffUser(): User
    {
        return User::factory()->role(RoleName::HospitalStaff)->create([
            'first_name' => 'Staff',
            'last_name' => 'User',
            'name' => 'Staff User',
            'email' => 'notify-staff-'.uniqid('', true).'@example.com',
        ]);
    }
}
