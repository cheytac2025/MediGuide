<?php

namespace Tests\Feature\Security;

use App\Enums\RoleName;
use App\Enums\Sex;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\User;
use App\Support\AiDisclaimer;
use App\Support\AiFrontDeskConversation;
use App\Support\BookingIntent;
use Database\Seeders\DevelopmentClinicSeeder;
use Database\Seeders\DevelopmentDepartmentSeeder;
use Database\Seeders\DevelopmentDoctorScheduleSeeder;
use Database\Seeders\DevelopmentDoctorSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SessionEncryptionTest extends TestCase
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

        $this->useSessionStorage(encrypt: true);
    }

    #[DataProvider('roles')]
    public function test_each_role_can_log_in_use_the_app_and_log_out_with_encrypted_sessions(RoleName $role, string $home): void
    {
        $user = $this->userWithRole($role);

        $login = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $login->assertRedirect(route($home));
        $sessionId = $this->sessionId($login);

        $raw = $this->rawPayload($sessionId);
        $this->assertNull(json_decode($raw, true), 'Stored session payload must not be readable JSON.');
        $this->assertStringNotContainsString('login_web', $raw);
        $this->assertStringContainsString('login_web', Crypt::decrypt($raw));

        $this->freshRequestCycle();
        $this->withSessionCookie($sessionId)->get(route($home))->assertOk();
        $this->assertAuthenticatedAs($user);

        $this->freshRequestCycle();
        $this->withSessionCookie($sessionId)->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNull(DB::table('sessions')->where('id', $sessionId)->first());
    }

    /**
     * @return array<string, array{0: RoleName, 1: string}>
     */
    public static function roles(): array
    {
        return [
            'patient' => [RoleName::Patient, 'patient.dashboard'],
            'hospital staff' => [RoleName::HospitalStaff, 'staff.dashboard'],
            'doctor' => [RoleName::Doctor, 'doctor.dashboard'],
            'it administrator' => [RoleName::ItAdministrator, 'admin.dashboard'],
        ];
    }

    public function test_ai_disclaimer_conversation_and_booking_intent_are_encrypted_at_rest_and_read_back(): void
    {
        $turns = [
            ['role' => 'user', 'content' => 'Masakit ang dibdib ko since kagabi.'],
            ['role' => 'assistant', 'content' => 'Please seek emergency care if the chest pain is severe.'],
        ];
        $doctor = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();

        $first = $this->withSession([
            AiDisclaimer::SESSION_KEY => true,
            AiFrontDeskConversation::SESSION_KEY => $turns,
            BookingIntent::SESSION_KEY => ['clinic_id' => $doctor->clinic_id, 'doctor_id' => $doctor->id],
        ])->get(route('ai-front-desk'));

        $first->assertOk();
        $sessionId = $this->sessionId($first);
        $raw = $this->rawPayload($sessionId);

        $this->assertStringNotContainsString('Masakit ang dibdib', $raw);
        $this->assertStringNotContainsString('chest pain', $raw);
        $this->assertStringNotContainsString(AiDisclaimer::SESSION_KEY, $raw);

        $decrypted = json_decode(Crypt::decrypt($raw), true);
        $this->assertSame($turns, $decrypted[AiFrontDeskConversation::SESSION_KEY]);
        $this->assertTrue($decrypted[AiDisclaimer::SESSION_KEY]);
        $this->assertSame($doctor->id, $decrypted[BookingIntent::SESSION_KEY]['doctor_id']);

        $this->freshRequestCycle();
        $this->withSessionCookie($sessionId)->get(route('ai-front-desk'))->assertOk();
        $this->assertSame($turns, session(AiFrontDeskConversation::SESSION_KEY));
        $this->assertSame($doctor->id, session(BookingIntent::SESSION_KEY)['doctor_id']);
    }

    public function test_guests_without_a_disclaimer_are_still_redirected(): void
    {
        $this->get(route('ai-front-desk'))->assertRedirect(route('ai-disclaimer'));
    }

    public function test_csrf_tokens_survive_the_encrypted_round_trip_and_missing_tokens_are_rejected(): void
    {
        $this->enforceCsrf();
        $user = $this->userWithRole(RoleName::Patient);

        $page = $this->get(route('login'));
        $page->assertOk();
        $sessionId = $this->sessionId($page);
        $token = json_decode(Crypt::decrypt($this->rawPayload($sessionId)), true)['_token'];

        $this->freshRequestCycle();
        $this->withSessionCookie($sessionId)
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(419);

        $this->freshRequestCycle();
        $this->withSessionCookie($sessionId)
            ->post(route('login.store'), ['_token' => $token, 'email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('patient.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_existing_unencrypted_sessions_become_empty_and_users_must_log_in_again(): void
    {
        $this->useSessionStorage(encrypt: false);
        $user = $this->userWithRole(RoleName::Patient);

        $login = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $sessionId = $this->sessionId($login);
        $this->assertStringContainsString('login_web', $this->rawPayload($sessionId));

        $this->useSessionStorage(encrypt: true);

        $this->withSessionCookie($sessionId)
            ->get(route('patient.dashboard'))
            ->assertRedirect(route('login'));
        $this->assertGuest();

        $this->freshRequestCycle();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('patient.dashboard'));
    }

    private function useSessionStorage(bool $encrypt): void
    {
        config(['session.driver' => 'database', 'session.encrypt' => $encrypt]);
        $this->freshRequestCycle();
    }

    /**
     * Each real HTTP request builds a new session store that reads the
     * persisted row; drop the in-memory store so tests do the same.
     */
    private function freshRequestCycle(): void
    {
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
    }

    private function withSessionCookie(string $sessionId): static
    {
        return $this->withCookie(config('session.cookie'), $sessionId);
    }

    private function sessionId(TestResponse $response): string
    {
        $cookie = $response->getCookie(config('session.cookie'));
        $this->assertNotNull($cookie, 'The response must set the session cookie.');

        return (string) $cookie->getValue();
    }

    private function rawPayload(string $sessionId): string
    {
        $payload = DB::table('sessions')->where('id', $sessionId)->value('payload');
        $this->assertNotNull($payload, 'The session row must be persisted.');

        return base64_decode($payload);
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
        $user = User::factory()->role($role)->create(['email' => "session-{$role->value}@example.com"]);

        match ($role) {
            RoleName::Patient => $user->patient()->create([
                'date_of_birth' => '1990-01-15',
                'sex' => Sex::Female,
                'contact_number' => '09170000001',
            ]),
            RoleName::HospitalStaff => $user->hospitalStaff()->create()->departments()->sync(
                Department::query()->where('name', 'Development Department A')->pluck('id'),
            ),
            RoleName::Doctor => Doctor::query()->where('display_name', 'Test Doctor 1')->update(['user_id' => $user->id]),
            default => null,
        };

        return $user->fresh();
    }
}
