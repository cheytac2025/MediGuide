<?php

namespace Tests\Feature\Admin;

use App\Enums\AppointmentStatus;
use App\Enums\ClinicStatus;
use App\Enums\DayOfWeek;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorStatus;
use App\Enums\RoleName;
use App\Enums\Sex;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\User;
use App\Support\AiDisclaimer;
use Database\Seeders\DevelopmentClinicSeeder;
use Database\Seeders\DevelopmentDepartmentSeeder;
use Database\Seeders\DevelopmentDoctorScheduleSeeder;
use Database\Seeders\DevelopmentDoctorSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminDoctorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(DevelopmentDepartmentSeeder::class);
        $this->seed(DevelopmentClinicSeeder::class);
        $this->seed(DevelopmentDoctorSeeder::class);
        $this->seed(DevelopmentDoctorScheduleSeeder::class);
    }

    public function test_guests_cannot_access_doctor_management(): void
    {
        $this->get(route('admin.doctors'))->assertRedirect(route('login'));
    }

    public function test_patients_receive_403(): void
    {
        $this->actingAs(User::factory()->role(RoleName::Patient)->create())
            ->get(route('admin.doctors'))
            ->assertForbidden();
    }

    public function test_hospital_staff_receive_403(): void
    {
        $this->actingAs(User::factory()->role(RoleName::HospitalStaff)->create())
            ->get(route('admin.doctors'))
            ->assertForbidden();
    }

    public function test_doctors_receive_403(): void
    {
        $this->actingAs(User::factory()->role(RoleName::Doctor)->create())
            ->get(route('admin.doctors'))
            ->assertForbidden();
    }

    public function test_it_admin_can_access_the_doctor_list(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.doctors'))
            ->assertOk()
            ->assertSee('Doctor Management')
            ->assertSee('Test Doctor 1')
            ->assertSee('+ Add Doctor')
            ->assertSee('No linked account')
            ->assertDontSee('password');
    }

    public function test_it_admin_can_open_add_doctor(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.doctors.create'))
            ->assertOk()
            ->assertSee('Add Doctor')
            ->assertSee('Development Clinic A');
    }

    public function test_it_admin_can_create_a_linked_doctor_account(): void
    {
        $clinic = $this->clinic('Development Clinic A');

        $this->actingAs($this->admin())
            ->post(route('admin.doctors.store'), $this->payload($clinic, [
                'role' => RoleName::Patient->value,
                'role_id' => 1,
                'user_id' => 99,
            ]))
            ->assertRedirect(route('admin.doctors'))
            ->assertSessionHas('doctor_status', 'Doctor added successfully.');

        $doctor = Doctor::query()->where('display_name', 'Dr. Ana Cruz')->firstOrFail();
        $user = User::query()->where('email', 'ana.cruz@mediguide.test')->firstOrFail();

        $this->assertSame($clinic->id, $doctor->clinic_id);
        $this->assertSame($user->id, $doctor->user_id);
        $this->assertTrue($user->hasRole(RoleName::Doctor));
        $this->assertFalse($user->hasRole(RoleName::Patient));
        $this->assertTrue(Hash::check('MediGuide@Doc1', $user->password));
        $this->assertNotSame('MediGuide@Doc1', $user->getRawOriginal('password'));
    }

    public function test_new_doctor_can_authenticate_and_reaches_the_doctor_dashboard(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.doctors.store'), $this->payload($this->clinic('Development Clinic A')));

        $this->post(route('logout'));

        $this->post(route('login.store'), [
            'email' => 'ana.cruz@mediguide.test',
            'password' => 'MediGuide@Doc1',
        ])->assertRedirect(route('doctor.dashboard'));
    }

    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'ana.cruz@mediguide.test']);
        $admin = $this->admin();
        $before = User::query()->count();

        $this->actingAs($admin)
            ->post(route('admin.doctors.store'), $this->payload($this->clinic('Development Clinic A')))
            ->assertSessionHasErrors('email');

        $this->assertSame($before, User::query()->count());
        $this->assertDatabaseMissing('doctors', ['display_name' => 'Dr. Ana Cruz']);
    }

    public function test_failed_doctor_creation_does_not_leave_an_orphan_user(): void
    {
        Doctor::creating(function (): void {
            throw new \RuntimeException('Forced doctor failure.');
        });

        $this->actingAs($this->admin())
            ->from(route('admin.doctors.create'))
            ->post(route('admin.doctors.store'), $this->payload($this->clinic('Development Clinic A')))
            ->assertRedirect(route('admin.doctors.create'))
            ->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('users', ['email' => 'ana.cruz@mediguide.test']);
        $this->assertDatabaseMissing('doctors', ['display_name' => 'Dr. Ana Cruz']);
    }

    public function test_clinic_must_exist(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.doctors.store'), $this->payload($this->clinic('Development Clinic A'), [
                'clinic_id' => 999_999,
            ]))
            ->assertSessionHasErrors('clinic_id');
    }

    public function test_inactive_clinic_cannot_receive_an_active_doctor(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.doctors.store'), $this->payload($this->clinic('Development Clinic C')))
            ->assertSessionHasErrors('clinic_id');

        $this->assertDatabaseMissing('doctors', ['display_name' => 'Dr. Ana Cruz']);
    }

    public function test_inactive_department_cannot_receive_an_active_doctor(): void
    {
        $department = Department::query()->where('name', 'Development Department C')->firstOrFail();
        $clinic = Clinic::query()->create([
            'department_id' => $department->id,
            'name' => 'Active Clinic Inactive Department',
            'status' => ClinicStatus::Active,
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.doctors.store'), $this->payload($clinic))
            ->assertSessionHasErrors('clinic_id');
    }

    public function test_it_admin_can_edit_doctor_information(): void
    {
        $doctor = $this->doctor('Test Doctor 2');

        $this->actingAs($this->admin())
            ->patch(route('admin.doctors.update', $doctor), [
                'display_name' => 'Test Doctor Two',
                'clinic_id' => $doctor->clinic_id,
                'specialization' => 'Updated Specialization',
                'status' => DoctorStatus::Active->value,
            ])
            ->assertRedirect(route('admin.doctors'))
            ->assertSessionHas('doctor_status', 'Doctor updated successfully.');

        $doctor->refresh();
        $this->assertSame('Test Doctor Two', $doctor->display_name);
        $this->assertSame('Updated Specialization', $doctor->specialization);
    }

    public function test_email_uniqueness_is_preserved_during_edit(): void
    {
        $clinic = $this->clinic('Development Clinic A');
        $this->actingAs($this->admin())
            ->post(route('admin.doctors.store'), $this->payload($clinic));

        $other = User::factory()->create(['email' => 'taken@mediguide.test']);
        $doctor = Doctor::query()->where('display_name', 'Dr. Ana Cruz')->firstOrFail();

        $this->actingAs($this->admin())
            ->patch(route('admin.doctors.update', $doctor), [
                'display_name' => $doctor->display_name,
                'clinic_id' => $doctor->clinic_id,
                'specialization' => $doctor->specialization,
                'status' => DoctorStatus::Active->value,
                'first_name' => 'Ana',
                'middle_name' => null,
                'last_name' => 'Cruz',
                'email' => $other->email,
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame('ana.cruz@mediguide.test', $doctor->fresh()->user->email);
    }

    public function test_it_admin_can_deactivate_and_reactivate_a_doctor(): void
    {
        $doctor = $this->doctor('Test Doctor 1');

        $this->actingAs($this->admin())
            ->patch(route('admin.doctors.status', $doctor), ['status' => DoctorStatus::Inactive->value])
            ->assertSessionHas('doctor_status', 'Doctor deactivated successfully.');

        $this->assertSame(DoctorStatus::Inactive, $doctor->fresh()->status);

        $this->actingAs($this->admin())
            ->patch(route('admin.doctors.status', $doctor), ['status' => DoctorStatus::Active->value])
            ->assertSessionHas('doctor_status', 'Doctor activated successfully.');

        $this->assertSame(DoctorStatus::Active, $doctor->fresh()->status);
    }

    public function test_doctor_cannot_be_activated_under_an_inactive_clinic(): void
    {
        $doctor = $this->doctor('Test Doctor 3');

        $this->actingAs($this->admin())
            ->patch(route('admin.doctors.status', $doctor), ['status' => DoctorStatus::Active->value])
            ->assertSessionHas('doctor_error', 'This doctor cannot be activated because the assigned clinic or department is inactive.');

        $this->assertSame(DoctorStatus::Inactive, $doctor->fresh()->status);
    }

    public function test_doctor_cannot_be_activated_under_an_inactive_department(): void
    {
        $department = Department::query()->where('status', DepartmentStatus::Inactive)->firstOrFail();
        $clinic = Clinic::query()->create([
            'department_id' => $department->id,
            'name' => 'Temporarily Active Clinic',
            'status' => ClinicStatus::Active,
        ]);
        $doctor = Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'display_name' => 'Inactive Department Doctor',
            'status' => DoctorStatus::Inactive,
        ]);
        $clinic->update(['status' => ClinicStatus::Inactive]);
        $clinic->update(['status' => ClinicStatus::Active]);

        $this->actingAs($this->admin())
            ->patch(route('admin.doctors.status', $doctor), ['status' => DoctorStatus::Active->value])
            ->assertSessionHas('doctor_error');

        $this->assertSame(DoctorStatus::Inactive, $doctor->fresh()->status);
    }

    public function test_deactivated_doctor_is_excluded_from_patient_booking_and_active_doctor_remains(): void
    {
        $clinic = $this->clinic('Development Clinic A');
        $patient = $this->patient();

        $this->actingAs($patient)
            ->get(route('patient.book-appointment', ['clinic_id' => $clinic->id]))
            ->assertOk()
            ->assertSee('Test Doctor 1');

        $this->doctor('Test Doctor 1')->update(['status' => DoctorStatus::Inactive]);

        $this->actingAs($patient)
            ->get(route('patient.book-appointment', ['clinic_id' => $clinic->id]))
            ->assertOk()
            ->assertDontSee('Test Doctor 1');
    }

    public function test_deactivation_preserves_schedules_and_appointments(): void
    {
        $doctor = $this->doctor('Test Doctor 1');
        $scheduleCount = DoctorSchedule::query()->where('doctor_id', $doctor->id)->count();
        $appointment = $this->appointmentFor($doctor);

        $this->actingAs($this->admin())
            ->patch(route('admin.doctors.status', $doctor), ['status' => DoctorStatus::Inactive->value]);

        $this->assertSame($scheduleCount, DoctorSchedule::query()->where('doctor_id', $doctor->id)->count());
        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'doctor_id' => $doctor->id,
            'status' => AppointmentStatus::Confirmed->value,
        ]);
    }

    public function test_clinic_change_is_rejected_while_future_appointments_exist(): void
    {
        $doctor = $this->doctor('Test Doctor 1');
        $otherClinic = $this->clinic('Development Clinic B');
        $this->appointmentFor($doctor);

        $this->actingAs($this->admin())
            ->patch(route('admin.doctors.update', $doctor), [
                'display_name' => $doctor->display_name,
                'clinic_id' => $otherClinic->id,
                'specialization' => $doctor->specialization,
                'status' => DoctorStatus::Active->value,
            ])
            ->assertSessionHasErrors('clinic_id');

        $this->assertSame($this->clinic('Development Clinic A')->id, $doctor->fresh()->clinic_id);
    }

    public function test_non_admins_cannot_create_edit_or_change_doctor_status(): void
    {
        $doctor = $this->doctor('Test Doctor 1');
        $payload = $this->payload($this->clinic('Development Clinic A'));

        foreach ([RoleName::Patient, RoleName::HospitalStaff, RoleName::Doctor] as $role) {
            $user = User::factory()->role($role)->create();

            $this->actingAs($user)->post(route('admin.doctors.store'), $payload)->assertForbidden();
            $this->actingAs($user)->patch(route('admin.doctors.update', $doctor), [
                'display_name' => 'Hijacked',
                'clinic_id' => $doctor->clinic_id,
                'specialization' => 'No',
                'status' => DoctorStatus::Inactive->value,
            ])->assertForbidden();
            $this->actingAs($user)->patch(route('admin.doctors.status', $doctor), [
                'status' => DoctorStatus::Inactive->value,
            ])->assertForbidden();
        }

        $this->assertSame('Test Doctor 1', $doctor->fresh()->display_name);
        $this->assertSame(DoctorStatus::Active, $doctor->fresh()->status);
    }

    public function test_doctor_filters_and_sidebar_work(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.doctors', ['status' => 'active']))
            ->assertOk()
            ->assertSee('Test Doctor 1')
            ->assertDontSee('Test Doctor 3');

        $this->actingAs($admin)
            ->get(route('admin.doctors', ['status' => 'inactive']))
            ->assertOk()
            ->assertSee('Test Doctor 3')
            ->assertDontSee('Test Doctor 1');

        $this->actingAs($admin)
            ->get(route('admin.doctors'))
            ->assertOk()
            ->assertSee(route('admin.doctors'), false)
            ->assertSee('aria-current="page"', false);

        $this->actingAs($admin)
            ->get(route('admin.doctors.create'))
            ->assertSee('aria-current="page"', false);

        $this->actingAs($admin)
            ->get(route('admin.doctors.edit', $this->doctor('Test Doctor 1')))
            ->assertSee('aria-current="page"', false);
    }

    public function test_deactivated_doctor_is_excluded_from_ai_doctor_list(): void
    {
        $clinic = $this->clinic('Development Clinic A');
        $this->doctor('Test Doctor 1')->update(['status' => DoctorStatus::Inactive]);

        $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->getJson(route('ai-front-desk.clinics.doctors', $clinic))
            ->assertOk()
            ->assertJsonMissing(['display_name' => 'Test Doctor 1']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Clinic $clinic, array $overrides = []): array
    {
        return array_merge([
            'display_name' => 'Dr. Ana Cruz',
            'clinic_id' => $clinic->id,
            'specialization' => 'General Medicine',
            'status' => DoctorStatus::Active->value,
            'first_name' => 'Ana',
            'middle_name' => null,
            'last_name' => 'Cruz',
            'email' => 'ana.cruz@mediguide.test',
            'password' => 'MediGuide@Doc1',
            'password_confirmation' => 'MediGuide@Doc1',
        ], $overrides);
    }

    private function admin(): User
    {
        return User::factory()->role(RoleName::ItAdministrator)->create();
    }

    private function clinic(string $name): Clinic
    {
        return Clinic::query()->where('name', $name)->firstOrFail();
    }

    private function doctor(string $name): Doctor
    {
        return Doctor::query()->where('display_name', $name)->firstOrFail();
    }

    private function patient(): User
    {
        $user = User::factory()->create();
        $user->patient()->create([
            'date_of_birth' => '1991-02-02',
            'sex' => Sex::Female,
            'contact_number' => '09170000011',
        ]);

        return $user->fresh(['patient']);
    }

    private function appointmentFor(Doctor $doctor): Appointment
    {
        $schedule = DoctorSchedule::query()
            ->where('doctor_id', $doctor->id)
            ->where('day_of_week', DayOfWeek::Monday)
            ->firstOrFail();

        return Appointment::query()->create([
            'patient_id' => $this->patient()->patient->id,
            'doctor_id' => $doctor->id,
            'doctor_schedule_id' => $schedule->id,
            'appointment_date' => '2026-10-12',
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'status' => AppointmentStatus::Confirmed,
        ]);
    }
}
