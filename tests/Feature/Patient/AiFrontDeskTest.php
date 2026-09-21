<?php

namespace Tests\Feature\Patient;

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiFrontDeskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_guests_cannot_access_the_ai_front_desk(): void
    {
        $this->get(route('patient.ai-front-desk'))
            ->assertRedirect(route('login'));
    }

    public function test_patients_can_view_the_ai_front_desk(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'name' => 'Juan Dela Cruz',
        ]);

        $response = $this->actingAs($user)->get(route('patient.ai-front-desk'));

        $response->assertOk();
        $response->assertSee('AI Virtual Front Desk');
        $response->assertSee('AI-Assisted Patient Navigation');
        $response->assertSee('Hi, Juan.');
        $response->assertSee('Describe your symptoms or health concern in your own words');
        $response->assertSee('I have a headache');
        $response->assertSee('Development Department');
        $response->assertSee('Test Specialist');
        $response->assertSee('MediGuide provides patient navigation assistance only');
        $response->assertSee('aria-current="page"', false);
    }

    #[DataProvider('nonPatientRoles')]
    public function test_non_patients_cannot_access_the_ai_front_desk(RoleName $role): void
    {
        $user = User::factory()->role($role)->create();

        $this->actingAs($user)
            ->get(route('patient.ai-front-desk'))
            ->assertForbidden();
    }

    /**
     * @return array<string, array{0: RoleName}>
     */
    public static function nonPatientRoles(): array
    {
        return [
            'hospital staff' => [RoleName::HospitalStaff],
            'doctor' => [RoleName::Doctor],
            'it administrator' => [RoleName::ItAdministrator],
        ];
    }
}
