<?php

namespace Tests\Feature\Security;

use App\Enums\AppointmentStatus;
use App\Enums\RoleName;
use App\Enums\Sex;
use App\Enums\UserStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\User;
use App\Notifications\AppointmentSubmittedNotification;
use Database\Seeders\DevelopmentClinicSeeder;
use Database\Seeders\DevelopmentDepartmentSeeder;
use Database\Seeders\DevelopmentDoctorScheduleSeeder;
use Database\Seeders\DevelopmentDoctorSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
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

    #[DataProvider('rolesWithHome')]
    public function test_an_existing_session_loses_access_after_deactivation(RoleName $role, string $home): void
    {
        $user = $this->userWithRole($role);

        $this->actingAs($user)->get(route($home))->assertSuccessful();

        $user->forceFill(['status' => UserStatus::Inactive])->save();

        $this->get(route($home))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_admin_deactivation_ends_a_staff_session_and_reactivation_allows_login_again(): void
    {
        $admin = User::factory()->role(RoleName::ItAdministrator)->create();
        $staff = $this->staffUser(['Development Department A']);

        $this->actingAs($staff)->get(route('staff.dashboard'))->assertOk();

        $this->actingAs($admin)
            ->patch(route('admin.users.status', $staff), ['status' => UserStatus::Inactive->value])
            ->assertRedirect(route('admin.users'));

        $this->actingAs($staff->fresh())
            ->get(route('staff.appointments'))
            ->assertRedirect(route('login'));
        $this->assertGuest();

        $this->post(route('login.store'), ['email' => $staff->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs($admin)
            ->patch(route('admin.users.status', $staff), ['status' => UserStatus::Active->value]);
        auth()->logout();

        $this->post(route('login.store'), ['email' => $staff->email, 'password' => 'password'])
            ->assertRedirect(route('staff.dashboard'));
        $this->assertAuthenticatedAs($staff->fresh());
    }

    public function test_deactivated_users_are_logged_out_of_state_changing_requests(): void
    {
        $patientUser = $this->patientUser();
        $appointment = $this->appointmentFor($patientUser, 'Test Doctor 1');
        $patientUser->forceFill(['status' => UserStatus::Inactive])->save();

        $this->actingAs($patientUser)
            ->patch(route('patient.appointments.cancel', $appointment))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(AppointmentStatus::Pending, $appointment->fresh()->status);
    }

    public function test_logout_invalidates_the_session_and_regenerates_the_csrf_token(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($user);
        $sessionId = session()->getId();
        $token = session()->token();

        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertNotSame($token, session()->token());
    }

    public function test_session_cookie_settings_are_hardened(): void
    {
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
        $this->assertSame('json', config('session.serialization'));

        $previous = $_SERVER['APP_ENV'] ?? null;
        $previousSecure = $_SERVER['SESSION_SECURE_COOKIE'] ?? null;
        unset($_SERVER['SESSION_SECURE_COOKIE']);

        try {
            $_SERVER['APP_ENV'] = 'production';
            $this->assertTrue((require config_path('session.php'))['secure']);

            $_SERVER['APP_ENV'] = 'local';
            $this->assertFalse((require config_path('session.php'))['secure']);
        } finally {
            $previous === null ? $_SERVER['APP_ENV'] = 'testing' : $_SERVER['APP_ENV'] = $previous;

            if ($previousSecure !== null) {
                $_SERVER['SESSION_SECURE_COOKIE'] = $previousSecure;
            }
        }
    }

    #[DataProvider('allRoles')]
    public function test_accounts_cannot_change_their_login_email_or_delete_themselves_through_starter_settings(RoleName $role): void
    {
        $user = $this->userWithRole($role);
        $email = $user->email;

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => 'Changed Name', 'email' => 'takeover@example.com'])
            ->assertForbidden();

        $this->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertForbidden();

        $fresh = $user->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame($email, $fresh->email);
        $this->assertNotSame('Changed Name', $fresh->name);
        $this->assertAuthenticatedAs($fresh);
    }

    public function test_every_role_is_denied_every_other_roles_routes(): void
    {
        $fixtures = $this->routeFixtures();
        $users = [];

        foreach (RoleName::cases() as $role) {
            $users[$role->value] = $this->userWithRole($role);
        }

        foreach ($this->protectedRoutes($fixtures) as [$method, $name, $parameters, $owner]) {
            $this->assertGuestIsSentToLogin($method, $name, $parameters);

            foreach (RoleName::cases() as $role) {
                if ($role === $owner) {
                    continue;
                }

                $response = $this->actingAs($users[$role->value])
                    ->call($method, route($name, $parameters));

                $this->assertSame(
                    403,
                    $response->getStatusCode(),
                    "{$role->value} should receive 403 for {$method} {$name}.",
                );
            }
        }

        $this->assertSame(AppointmentStatus::Pending, $fixtures['appointment']->fresh()->status);
        $this->assertSame(1, $fixtures['patientUser']->notifications()->count());
    }

    public function test_state_changing_routes_reject_requests_without_a_csrf_token(): void
    {
        $this->enforceCsrf();
        $fixtures = $this->routeFixtures();
        $staff = $this->staffUser(['Development Department A', 'Development Department B']);
        $admin = User::factory()->role(RoleName::ItAdministrator)->create();
        $notification = $fixtures['patientUser']->notifications()->firstOrFail();

        $requests = [
            [$fixtures['patientUser'], 'POST', route('patient.book-appointment.store')],
            [$fixtures['patientUser'], 'PATCH', route('patient.appointments.cancel', $fixtures['appointment'])],
            [$fixtures['patientUser'], 'PATCH', route('patient.notifications.read', $notification->id)],
            [$fixtures['patientUser'], 'DELETE', route('patient.notifications.destroy', $notification->id)],
            [$fixtures['patientUser'], 'DELETE', route('patient.notifications.clear')],
            [$staff, 'PATCH', route('staff.appointments.confirm', $fixtures['appointment'])],
            [$staff, 'PATCH', route('staff.appointments.reject', $fixtures['appointment'])],
            [$staff, 'POST', route('staff.doctor-schedules.store', $fixtures['doctor'])],
            [$staff, 'PATCH', route('staff.doctor-schedules.update', $fixtures['schedule'])],
            [$staff, 'PATCH', route('staff.doctor-schedules.status', $fixtures['schedule'])],
            [$admin, 'POST', route('admin.clinics.store')],
            [$admin, 'PATCH', route('admin.clinics.update', $fixtures['clinic'])],
            [$admin, 'POST', route('admin.doctors.store')],
            [$admin, 'PATCH', route('admin.doctors.status', $fixtures['doctor'])],
            [$admin, 'POST', route('admin.users.staff.store')],
            [$admin, 'PATCH', route('admin.users.update', $fixtures['staffUser'])],
            [$admin, 'PATCH', route('admin.users.status', $fixtures['staffUser'])],
        ];

        foreach ($requests as [$user, $method, $url]) {
            $this->actingAs($user)
                ->call($method, $url)
                ->assertStatus(419);
        }

        $this->assertSame(AppointmentStatus::Pending, $fixtures['appointment']->fresh()->status);
        $this->assertNull($notification->fresh()->read_at);
        $this->assertSame(UserStatus::Active, $fixtures['staffUser']->fresh()->status);
    }

    public function test_state_changing_role_routes_do_not_use_get(): void
    {
        $mutatingSuffixes = ['store', 'update', 'status', 'confirm', 'reject', 'cancel', 'read', 'read-all', 'destroy', 'clear', 'review'];

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! preg_match('/^(patient|staff|doctor|admin)\./', $name)) {
                continue;
            }

            $suffix = substr($name, strrpos($name, '.') + 1);

            if (in_array($suffix, $mutatingSuffixes, true)) {
                $this->assertNotContains('GET', $route->methods(), "{$name} must not accept GET.");
            }
        }
    }

    public function test_patients_cannot_read_or_modify_another_patients_appointment_or_notifications(): void
    {
        $owner = $this->patientUser();
        $intruder = $this->patientUser();
        $appointment = $this->appointmentFor($owner, 'Test Doctor 1', 'Owner private concern');
        $owner->notify(new AppointmentSubmittedNotification($appointment));
        $notification = $owner->notifications()->firstOrFail();

        $this->actingAs($intruder)->get(route('patient.appointments.show', $appointment))->assertNotFound();
        $this->actingAs($intruder)->patch(route('patient.appointments.cancel', $appointment))->assertNotFound();
        $this->actingAs($intruder)->get(route('patient.notifications.open', $notification->id))->assertNotFound();
        $this->actingAs($intruder)->patch(route('patient.notifications.read', $notification->id))->assertNotFound();
        $this->actingAs($intruder)->delete(route('patient.notifications.destroy', $notification->id))->assertNotFound();
        $this->actingAs($intruder)->delete(route('patient.notifications.clear'))->assertRedirect();

        $this->actingAs($intruder)
            ->get(route('patient.appointments'))
            ->assertOk()
            ->assertDontSee('Owner private concern')
            ->assertDontSee($owner->email);

        $this->actingAs($intruder)
            ->get(route('patient.profile'))
            ->assertOk()
            ->assertSee($intruder->email)
            ->assertDontSee($owner->email);

        $this->assertSame(AppointmentStatus::Pending, $appointment->fresh()->status);
        $this->assertNotNull($notification->fresh());
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_doctors_only_see_their_own_appointments_and_unlinked_doctors_get_no_access(): void
    {
        $doctorUser = User::factory()->role(RoleName::Doctor)->create();
        $this->doctor('Test Doctor 1')->update(['user_id' => $doctorUser->id]);
        $own = $this->appointmentFor($this->patientUser(), 'Test Doctor 1', 'Own doctor concern', AppointmentStatus::Confirmed);
        $other = $this->appointmentFor($this->patientUser(), 'Test Doctor 2', 'Other doctor concern', AppointmentStatus::Confirmed);

        $this->actingAs($doctorUser)->get(route('doctor.appointments.show', $own))->assertOk();
        $this->actingAs($doctorUser)->get(route('doctor.appointments.show', $other))->assertNotFound();
        $this->actingAs($doctorUser)
            ->get(route('doctor.appointments'))
            ->assertOk()
            ->assertDontSee('Other doctor concern');

        $unlinked = User::factory()->role(RoleName::Doctor)->create();

        $this->actingAs($unlinked)->get(route('doctor.appointments'))->assertRedirect(route('dashboard.unavailable'));
        $this->actingAs($unlinked)->get(route('doctor.appointments.show', $own))->assertRedirect(route('dashboard.unavailable'));
        $this->actingAs($unlinked)->get(route('doctor.schedule'))->assertRedirect(route('dashboard.unavailable'));
    }

    public function test_staff_department_assignment_changes_apply_on_the_next_request(): void
    {
        $staff = $this->staffUser(['Development Department A']);
        $admin = User::factory()->role(RoleName::ItAdministrator)->create();
        $departmentB = $this->appointmentFor($this->patientUser(), 'Test Doctor 2');

        $this->actingAs($staff)->get(route('staff.appointments.show', $departmentB))->assertNotFound();

        $this->actingAs($admin)->patch(route('admin.users.update', $staff), [
            'first_name' => $staff->first_name,
            'middle_name' => null,
            'last_name' => $staff->last_name,
            'email' => $staff->email,
            'status' => UserStatus::Active->value,
            'departments' => [Department::query()->where('name', 'Development Department B')->value('id')],
        ])->assertRedirect(route('admin.users'));

        $this->startFreshRequestCycle();
        $this->actingAs($staff->fresh())->get(route('staff.appointments.show', $departmentB))->assertOk();

        $this->actingAs($admin)->patch(route('admin.users.update', $staff), [
            'first_name' => $staff->first_name,
            'middle_name' => null,
            'last_name' => $staff->last_name,
            'email' => $staff->email,
            'status' => UserStatus::Active->value,
            'departments' => [Department::query()->where('name', 'Development Department A')->value('id')],
        ]);

        $this->startFreshRequestCycle();
        $this->actingAs($staff->fresh())->get(route('staff.appointments.show', $departmentB))->assertNotFound();
        $this->actingAs($staff->fresh())->patch(route('staff.appointments.confirm', $departmentB))->assertNotFound();
        $this->assertSame(AppointmentStatus::Pending, $departmentB->fresh()->status);
    }

    public function test_admin_cannot_manage_patient_or_administrator_accounts_or_inject_roles(): void
    {
        $admin = User::factory()->role(RoleName::ItAdministrator)->create();
        $otherAdmin = User::factory()->role(RoleName::ItAdministrator)->create();
        $patient = $this->patientUser();
        $staff = $this->staffUser(['Development Department A']);
        $adminRoleId = $admin->role_id;

        foreach ([$patient, $otherAdmin] as $target) {
            $this->actingAs($admin)->get(route('admin.users.edit', $target))->assertNotFound();
            $this->actingAs($admin)
                ->patch(route('admin.users.status', $target), ['status' => UserStatus::Inactive->value])
                ->assertNotFound();
            $this->assertSame(UserStatus::Active, $target->fresh()->status);
        }

        $this->actingAs($admin)->patch(route('admin.users.update', $staff), [
            'first_name' => 'Mira',
            'middle_name' => null,
            'last_name' => 'Santos',
            'email' => $staff->email,
            'status' => UserStatus::Active->value,
            'role_id' => $adminRoleId,
            'role' => RoleName::ItAdministrator->value,
            'departments' => [Department::query()->where('name', 'Development Department A')->value('id')],
        ])->assertRedirect(route('admin.users'));

        $this->assertTrue($staff->fresh()->hasRole(RoleName::HospitalStaff));

        $this->actingAs($admin)->post(route('admin.users.staff.store'), [
            'first_name' => 'Taken',
            'last_name' => 'Over',
            'email' => $patient->email,
            'password' => 'MediGuide@Staff1',
            'password_confirmation' => 'MediGuide@Staff1',
            'status' => UserStatus::Active->value,
            'departments' => [Department::query()->where('name', 'Development Department A')->value('id')],
        ])->assertSessionHasErrors('email');

        $this->assertTrue($patient->fresh()->hasRole(RoleName::Patient));
        $this->assertTrue(Hash::check('password', $patient->fresh()->password));
    }

    public function test_responses_do_not_expose_credentials_or_secrets(): void
    {
        $patient = $this->patientUser();
        $patient->forceFill(['remember_token' => 'remember-token-secret-value'])->save();
        $appointment = $this->appointmentFor($patient, 'Test Doctor 1');
        $staff = $this->staffUser(['Development Department A']);
        config(['services.anthropic.key' => 'sk-ant-test-secret-value']);
        $this->withoutVite();

        $responses = [
            $this->actingAs($patient)->get(route('patient.dashboard')),
            $this->actingAs($patient)->get(route('patient.profile')),
            $this->actingAs($patient)->get(route('patient.appointments.show', $appointment)),
            $this->actingAs($patient)->get(route('profile.edit')),
            $this->actingAs($staff)->get(route('staff.appointments.show', $appointment)),
        ];

        foreach ($responses as $response) {
            $response->assertOk();
            $content = $response->getContent();
            $this->assertStringNotContainsString($patient->fresh()->password, $content);
            $this->assertStringNotContainsString('remember-token-secret-value', $content);
            $this->assertStringNotContainsString('sk-ant-test-secret-value', $content);
            $this->assertStringNotContainsString((string) config('database.connections.mysql.password') ?: 'unused-db-password-marker', $content);
        }
    }

    /**
     * @return array<string, array{0: RoleName, 1: string}>
     */
    public static function rolesWithHome(): array
    {
        return [
            'patient' => [RoleName::Patient, 'patient.dashboard'],
            'hospital staff' => [RoleName::HospitalStaff, 'staff.dashboard'],
            'doctor' => [RoleName::Doctor, 'doctor.dashboard'],
            'it administrator' => [RoleName::ItAdministrator, 'admin.dashboard'],
        ];
    }

    /**
     * @return array<string, array{0: RoleName}>
     */
    public static function allRoles(): array
    {
        return [
            'patient' => [RoleName::Patient],
            'hospital staff' => [RoleName::HospitalStaff],
            'doctor' => [RoleName::Doctor],
            'it administrator' => [RoleName::ItAdministrator],
        ];
    }

    /**
     * @return array{patientUser: User, appointment: Appointment, doctor: Doctor, schedule: DoctorSchedule, clinic: Clinic, staffUser: User}
     */
    private function routeFixtures(): array
    {
        $patientUser = $this->patientUser();
        $appointment = $this->appointmentFor($patientUser, 'Test Doctor 1');
        $patientUser->notify(new AppointmentSubmittedNotification($appointment));
        $doctor = $this->doctor('Test Doctor 1');

        return [
            'patientUser' => $patientUser,
            'appointment' => $appointment,
            'doctor' => $doctor,
            'schedule' => DoctorSchedule::query()->where('doctor_id', $doctor->id)->orderBy('id')->firstOrFail(),
            'clinic' => $doctor->clinic,
            'staffUser' => $this->staffUser(['Development Department A']),
        ];
    }

    /**
     * @param  array{patientUser: User, appointment: Appointment, doctor: Doctor, schedule: DoctorSchedule, clinic: Clinic, staffUser: User}  $fixtures
     * @return list<array{0: string, 1: string, 2: array<string, mixed>, 3: RoleName}>
     */
    private function protectedRoutes(array $fixtures): array
    {
        $appointment = ['appointment' => $fixtures['appointment']->id];
        $notification = ['notification' => $fixtures['patientUser']->notifications()->value('id')];
        $patient = RoleName::Patient;
        $staff = RoleName::HospitalStaff;
        $doctor = RoleName::Doctor;
        $admin = RoleName::ItAdministrator;

        return [
            ['GET', 'patient.dashboard', [], $patient],
            ['GET', 'patient.profile', [], $patient],
            ['GET', 'patient.notifications', [], $patient],
            ['PATCH', 'patient.notifications.read-all', [], $patient],
            ['DELETE', 'patient.notifications.clear', [], $patient],
            ['PATCH', 'patient.notifications.read', $notification, $patient],
            ['DELETE', 'patient.notifications.destroy', $notification, $patient],
            ['GET', 'patient.notifications.open', $notification, $patient],
            ['GET', 'patient.book-appointment', [], $patient],
            ['POST', 'patient.book-appointment.review', [], $patient],
            ['POST', 'patient.book-appointment.store', [], $patient],
            ['GET', 'patient.appointments', [], $patient],
            ['GET', 'patient.appointments.show', $appointment, $patient],
            ['PATCH', 'patient.appointments.cancel', $appointment, $patient],
            ['GET', 'doctor.dashboard', [], $doctor],
            ['GET', 'doctor.appointments', [], $doctor],
            ['GET', 'doctor.appointments.show', $appointment, $doctor],
            ['GET', 'doctor.schedule', [], $doctor],
            ['GET', 'staff.dashboard', [], $staff],
            ['GET', 'staff.appointments', [], $staff],
            ['GET', 'staff.appointments.show', $appointment, $staff],
            ['PATCH', 'staff.appointments.confirm', $appointment, $staff],
            ['PATCH', 'staff.appointments.reject', $appointment, $staff],
            ['GET', 'staff.doctor-schedules', [], $staff],
            ['GET', 'staff.doctor-schedules.show', ['doctor' => $fixtures['doctor']->id], $staff],
            ['POST', 'staff.doctor-schedules.store', ['doctor' => $fixtures['doctor']->id], $staff],
            ['PATCH', 'staff.doctor-schedules.update', ['doctorSchedule' => $fixtures['schedule']->id], $staff],
            ['PATCH', 'staff.doctor-schedules.status', ['doctorSchedule' => $fixtures['schedule']->id], $staff],
            ['GET', 'admin.dashboard', [], $admin],
            ['GET', 'admin.clinics', [], $admin],
            ['GET', 'admin.clinics.create', [], $admin],
            ['POST', 'admin.clinics.store', [], $admin],
            ['GET', 'admin.clinics.edit', ['clinic' => $fixtures['clinic']->id], $admin],
            ['PATCH', 'admin.clinics.update', ['clinic' => $fixtures['clinic']->id], $admin],
            ['PATCH', 'admin.clinics.status', ['clinic' => $fixtures['clinic']->id], $admin],
            ['GET', 'admin.doctors', [], $admin],
            ['GET', 'admin.doctors.create', [], $admin],
            ['POST', 'admin.doctors.store', [], $admin],
            ['GET', 'admin.doctors.edit', ['doctor' => $fixtures['doctor']->id], $admin],
            ['PATCH', 'admin.doctors.update', ['doctor' => $fixtures['doctor']->id], $admin],
            ['PATCH', 'admin.doctors.status', ['doctor' => $fixtures['doctor']->id], $admin],
            ['GET', 'admin.hospital-staff', [], $admin],
            ['GET', 'admin.users', [], $admin],
            ['GET', 'admin.users.staff.create', [], $admin],
            ['POST', 'admin.users.staff.store', [], $admin],
            ['GET', 'admin.users.edit', ['user' => $fixtures['staffUser']->id], $admin],
            ['PATCH', 'admin.users.update', ['user' => $fixtures['staffUser']->id], $admin],
            ['PATCH', 'admin.users.status', ['user' => $fixtures['staffUser']->id], $admin],
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function assertGuestIsSentToLogin(string $method, string $name, array $parameters): void
    {
        auth()->logout();

        $this->call($method, route($name, $parameters))->assertRedirect(route('login'));
    }

    private function enforceCsrf(): void
    {
        $this->app->instance(
            PreventRequestForgery::class,
            new class($this->app, $this->app->make(Encrypter::class)) extends PreventRequestForgery
            {
                protected function runningUnitTests()
                {
                    return false;
                }
            },
        );
    }

    private function userWithRole(RoleName $role): User
    {
        return match ($role) {
            RoleName::Patient => $this->patientUser(),
            RoleName::HospitalStaff => $this->staffUser(['Development Department A']),
            RoleName::Doctor => $this->linkedDoctorUser(),
            default => User::factory()->role($role)->create(),
        };
    }

    private function linkedDoctorUser(): User
    {
        $user = User::factory()->role(RoleName::Doctor)->create();
        $this->doctor('Test Doctor 1')->update(['user_id' => $user->id]);

        return $user->fresh(['role', 'doctor']);
    }

    /**
     * The router keeps resolved controller instances for the life of the
     * application; each real HTTP request boots a fresh one.
     */
    private function startFreshRequestCycle(): void
    {
        foreach (Route::getRoutes() as $route) {
            $route->flushController();
        }
    }

    private function patientUser(): User
    {
        $this->sequence++;

        $user = User::factory()->role(RoleName::Patient)->create([
            'email' => "security-patient-{$this->sequence}@example.com",
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
            'email' => "security-staff-{$this->sequence}@example.com",
        ]);

        $user->hospitalStaff()->create()->departments()->sync(
            Department::query()->whereIn('name', $departmentNames)->pluck('id'),
        );

        return $user->fresh(['role']);
    }

    private function doctor(string $displayName): Doctor
    {
        return Doctor::query()->where('display_name', $displayName)->firstOrFail();
    }

    private function appointmentFor(
        User $patientUser,
        string $doctorName,
        string $concern = 'Security test concern',
        AppointmentStatus $status = AppointmentStatus::Pending,
    ): Appointment {
        $doctor = $this->doctor($doctorName);
        $schedule = DoctorSchedule::query()->where('doctor_id', $doctor->id)->orderBy('id')->firstOrFail();

        return Appointment::query()->create([
            'patient_id' => $patientUser->patient->id,
            'doctor_id' => $doctor->id,
            'doctor_schedule_id' => $schedule->id,
            'appointment_date' => '2026-10-12',
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'patient_concern' => $concern,
            'status' => $status,
        ]);
    }
}
