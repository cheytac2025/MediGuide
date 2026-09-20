<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
use App\Enums\Sex;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Juan',
            'middle_name' => 'Santos',
            'last_name' => 'Dela Cruz',
            'date_of_birth' => '2015-07-24',
            'sex' => Sex::Male->value,
            'contact_number' => '09171234567',
            'email' => 'juan.delacruz@example.com',
            'password' => 'MediGuide@2026',
            'password_confirmation' => 'MediGuide@2026',
        ], $overrides);
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get(route('register'));

        $response->assertOk();
        $response->assertSee('Create Your Account');
        $response->assertSee('Queen Mary');
        $response->assertDontSee('IT ADMINISTRATOR');
        $response->assertDontSee('role_id');
    }

    public function test_a_patient_can_register_with_valid_information(): void
    {
        $response = $this->post(route('register.store'), $this->validPayload());

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status', 'Account created successfully. Please sign in using your registered email and password.');
        $this->assertGuest();

        $user = User::query()->where('email', 'juan.delacruz@example.com')->first();

        $this->assertNotNull($user);
        $this->assertSame('Juan', $user->first_name);
        $this->assertSame('Santos', $user->middle_name);
        $this->assertSame('Dela Cruz', $user->last_name);
        $this->assertSame('Juan Santos Dela Cruz', $user->name);
        $this->assertSame(RoleName::Patient->value, $user->role->slug);
        $this->assertTrue(Hash::check('MediGuide@2026', $user->password));
        $this->assertNotSame('MediGuide@2026', $user->getRawOriginal('password'));
        $this->assertNotNull($user->patient);
        $this->assertSame('09171234567', $user->patient->contact_number);
        $this->assertSame(Sex::Male, $user->patient->sex);
        $this->assertTrue($user->patient->date_of_birth->equalTo('2015-07-24'));
    }

    public function test_valid_name_formats_are_accepted(): void
    {
        $names = [
            ['first_name' => 'Mark Angelo', 'last_name' => 'Santos'],
            ['first_name' => 'Juan', 'last_name' => 'Dela Cruz'],
            ['first_name' => 'Anne Marie', 'last_name' => 'Mary-Jane'],
            ['first_name' => 'D\'Angelo', 'last_name' => 'O\'Neil'],
        ];

        foreach ($names as $index => $name) {
            $email = "valid.name{$index}@example.com";

            $this->post(route('register.store'), $this->validPayload([
                ...$name,
                'email' => $email,
            ]))->assertRedirect(route('login'));

            $this->assertDatabaseHas('users', ['email' => $email]);
        }
    }

    public function test_international_philippine_mobile_numbers_are_normalized(): void
    {
        $this->post(route('register.store'), $this->validPayload([
            'contact_number' => '+639171234567',
        ]))->assertRedirect(route('login'));

        $this->assertDatabaseHas('patients', [
            'contact_number' => '09171234567',
        ]);
    }

    #[DataProvider('invalidNameProvider')]
    public function test_invalid_names_are_rejected(string $field, string $value): void
    {
        $this->from(route('register'))
            ->post(route('register.store'), $this->validPayload([
                $field => $value,
            ]))
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors($field);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('patients', 0);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidNameProvider(): array
    {
        return [
            'number in first name' => ['first_name', 'Mark8 Angelo'],
            'zero in first name' => ['first_name', 'J0hn'],
            'numbers in first name' => ['first_name', 'Juan123'],
            'symbol in first name' => ['first_name', '@Karl'],
            'exclamation in first name' => ['first_name', 'Neil!'],
            'numeric first name' => ['first_name', '123456'],
            'number in last name' => ['last_name', 'Cruz8'],
            'symbol in last name' => ['last_name', 'Dela@Cruz'],
        ];
    }

    #[DataProvider('invalidEmailProvider')]
    public function test_invalid_emails_are_rejected(string $email): void
    {
        $this->from(route('register'))
            ->post(route('register.store'), $this->validPayload([
                'email' => $email,
            ]))
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('users', 0);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidEmailProvider(): array
    {
        return [
            'missing at' => ['karl'],
            'missing domain' => ['karl@'],
            'missing local' => ['@gmail.com'],
            'missing tld' => ['karl@gmail'],
        ];
    }

    public function test_duplicate_emails_are_rejected(): void
    {
        User::factory()->create(['email' => 'juan.delacruz@example.com']);

        $this->from(route('register'))
            ->post(route('register.store'), $this->validPayload())
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors(['email' => 'An account with this email address already exists.']);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('patients', 0);
    }

    #[DataProvider('weakPasswordProvider')]
    public function test_weak_passwords_are_rejected(string $password): void
    {
        $this->from(route('register'))
            ->post(route('register.store'), $this->validPayload([
                'password' => $password,
                'password_confirmation' => $password,
            ]))
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function weakPasswordProvider(): array
    {
        return [
            'only lowercase' => ['password'],
            'mixed case only' => ['Password'],
            'no special character' => ['Password1'],
            'numbers only' => ['12345678'],
        ];
    }

    public function test_mismatched_password_confirmation_is_rejected(): void
    {
        $this->from(route('register'))
            ->post(route('register.store'), $this->validPayload([
                'password_confirmation' => 'MediGuide@2027',
            ]))
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_future_date_of_birth_is_rejected(): void
    {
        $this->from(route('register'))
            ->post(route('register.store'), $this->validPayload([
                'date_of_birth' => now()->addDay()->toDateString(),
            ]))
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors(['date_of_birth' => 'Date of birth cannot be in the future.']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_invalid_phone_numbers_are_rejected(): void
    {
        foreach (['09171234', '0917ABC4567'] as $number) {
            $this->from(route('register'))
                ->post(route('register.store'), $this->validPayload([
                    'contact_number' => $number,
                    'email' => 'phone.'. $number.'@example.com',
                ]))
                ->assertRedirect(route('register'))
                ->assertSessionHasErrors('contact_number');
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_missing_required_fields_are_rejected(): void
    {
        $this->from(route('register'))
            ->post(route('register.store'), [])
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors([
                'first_name',
                'last_name',
                'date_of_birth',
                'sex',
                'contact_number',
                'email',
                'password',
            ]);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('patients', 0);
    }

    public function test_public_registration_cannot_assign_privileged_roles(): void
    {
        $adminRole = Role::query()->where('slug', RoleName::ItAdministrator->value)->firstOrFail();

        $this->post(route('register.store'), $this->validPayload([
            'role_id' => $adminRole->id,
            'role' => 'IT ADMINISTRATOR',
            'is_admin' => 1,
            'status' => 'inactive',
        ]))->assertRedirect(route('login'));

        $user = User::query()->where('email', 'juan.delacruz@example.com')->firstOrFail();

        $this->assertSame(RoleName::Patient->value, $user->role->slug);
        $this->assertNotSame($adminRole->id, $user->role_id);
        $this->assertSame('active', $user->status->value);
    }

    public function test_safe_inputs_are_preserved_and_passwords_are_not(): void
    {
        $this->from(route('register'))
            ->post(route('register.store'), $this->validPayload([
                'first_name' => 'Mark8',
            ]))
            ->assertRedirect(route('register'))
            ->assertSessionHasInput('first_name', 'Mark8')
            ->assertSessionHasInput('email', 'juan.delacruz@example.com')
            ->assertSessionMissing('password')
            ->assertSessionMissing('password_confirmation');
    }
}
