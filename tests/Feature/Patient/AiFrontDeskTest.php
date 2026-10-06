<?php

namespace Tests\Feature\Patient;

use App\Models\User;
use App\Support\AiDisclaimer;
use Database\Seeders\DevelopmentClinicSeeder;
use Database\Seeders\DevelopmentDepartmentSeeder;
use Database\Seeders\DevelopmentDoctorScheduleSeeder;
use Database\Seeders\DevelopmentDoctorSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AiFrontDeskTest extends TestCase
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

    public function test_guests_can_reach_the_ai_disclaimer_from_the_home_page(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $this->assertNotEquals(route('login'), $response->headers->get('Location'));
        $this->assertDisclaimerContent($response);
    }

    public function test_guest_root_does_not_redirect_to_login(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $this->assertNotEquals(route('login'), $response->headers->get('Location'));
        $response->assertSee('Before You Continue');
    }

    public function test_guest_with_acknowledged_disclaimer_reaches_ai_front_desk_from_root(): void
    {
        $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->get('/')
            ->assertRedirect(route('ai-front-desk'));

        $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->get(route('ai-front-desk'))
            ->assertOk()
            ->assertSee('AI Virtual Front Desk');
    }

    public function test_guests_can_still_access_login_and_register(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Welcome Back');

        $this->get(route('register'))
            ->assertOk()
            ->assertSee('Create Your Account');
    }

    public function test_guests_can_reach_the_dedicated_ai_disclaimer_route(): void
    {
        $response = $this->get(route('ai-disclaimer'));

        $response->assertOk();
        $this->assertDisclaimerContent($response);
        $response->assertSee('disabled', false);
    }

    public function test_guests_who_have_not_acknowledged_the_disclaimer_cannot_use_the_ai_front_desk(): void
    {
        $this->get(route('ai-front-desk'))
            ->assertRedirect(route('ai-disclaimer'));

        $this->assertFalse(session()->get(AiDisclaimer::SESSION_KEY) === true);
    }

    public function test_guests_can_acknowledge_the_disclaimer_and_it_is_stored_in_the_session(): void
    {
        $response = $this->post(route('ai-disclaimer.acknowledge'), [
            'acknowledged' => '1',
        ]);

        $response->assertRedirect(route('ai-front-desk'));
        $response->assertSessionHas(AiDisclaimer::SESSION_KEY, true);
        $this->assertTrue(session()->get(AiDisclaimer::SESSION_KEY) === true);
    }

    public function test_disclaimer_acknowledgement_requires_the_checkbox(): void
    {
        $this->from(route('ai-disclaimer'))
            ->post(route('ai-disclaimer.acknowledge'), [])
            ->assertRedirect(route('ai-disclaimer'))
            ->assertSessionHasErrors('acknowledged');

        $this->assertFalse(session()->get(AiDisclaimer::SESSION_KEY) === true);
    }

    public function test_acknowledged_guests_can_access_the_ai_front_desk(): void
    {
        $response = $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->get(route('ai-front-desk'));

        $response->assertOk();
        $response->assertSee('AI Virtual Front Desk');
        $response->assertSee('AI-Assisted Patient Navigation');
        $response->assertSee('Describe your symptoms or health concern in your own words');
        $response->assertSee('I have a headache');
        $response->assertSee('New Conversation');
        $response->assertSee('View Available Doctors');
        $response->assertSee('MediGuide provides patient navigation assistance only');
        $response->assertDontSee('Development Clinic A');
        $response->assertDontSee('Before You Continue');
        $response->assertDontSee('Continue to MediGuide');

        $config = $response->viewData('frontDeskConfig');
        $this->assertArrayNotHasKey('mockRecommendation', $config);
        $this->assertSame(route('ai-front-desk.guidance'), $config['guidanceUrl']);
    }

    public function test_guest_ai_front_desk_shows_login_and_create_account_without_patient_identity(): void
    {
        $response = $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->get(route('ai-front-desk'));

        $response->assertOk();
        $response->assertSee("Hi! I'm MediGuide.", false);
        $response->assertSee('Log In');
        $response->assertSee('Create Account');
        $response->assertSee('href="'.route('login').'"', false);
        $response->assertSee('href="'.route('register').'"', false);
        $response->assertDontSee('Hi, Juan.');
        $response->assertDontSee('Signed in as');
        $response->assertDontSee('My Appointments');
        $response->assertDontSee('Patient Profile');
        $response->assertSee('aria-current="page"', false);
    }

    public function test_acknowledged_session_is_not_asked_for_the_disclaimer_again(): void
    {
        $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->get(route('ai-disclaimer'))
            ->assertRedirect(route('ai-front-desk'));

        $this->withSession([AiDisclaimer::SESSION_KEY => true])
            ->get(route('home'))
            ->assertRedirect(route('ai-front-desk'));
    }

    public function test_authenticated_patients_can_access_the_same_ai_front_desk(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'name' => 'Juan Dela Cruz',
        ]);

        $response = $this->actingAs($user)
            ->withSession([AiDisclaimer::SESSION_KEY => true])
            ->get(route('ai-front-desk'));

        $response->assertOk();
        $response->assertSee('AI Virtual Front Desk');
        $response->assertSee('Hi, Juan.');
        $response->assertSee('Juan Dela Cruz');
        $response->assertSee('Patient');
        $response->assertDontSee('Log In');
        $response->assertDontSee('Create Account');
        $response->assertSee('aria-current="page"', false);
    }

    public function test_patient_dashboard_remains_protected(): void
    {
        $this->get(route('patient.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_guest_book_appointment_remains_protected(): void
    {
        $this->get(route('patient.book-appointment'))
            ->assertRedirect(route('login'));
    }

    public function test_legacy_patient_ai_front_desk_route_redirects_to_the_public_route(): void
    {
        $this->get(route('patient.ai-front-desk'))
            ->assertRedirect(route('ai-front-desk'));
    }

    private function assertDisclaimerContent(TestResponse $response): void
    {
        $response->assertSee('Before You Continue');
        $response->assertSee('MediGuide is an AI-assisted virtual front desk designed to help guide you to an appropriate hospital department or medical specialist based on the information you provide.');
        $response->assertSee('MediGuide does not provide medical diagnoses, prescriptions, treatment recommendations, or emergency medical services.');
        $response->assertSee('I have read and understand the disclaimer above.');
        $response->assertSee('Continue to MediGuide');
        $response->assertSee('Queen Mary');
        $response->assertSee('Log In');
        $response->assertSee('Create Account');
    }
}
