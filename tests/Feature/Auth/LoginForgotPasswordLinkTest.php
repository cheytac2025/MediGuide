<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginForgotPasswordLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_blade_login_forgot_password_points_to_password_request_route(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee(route('password.request', absolute: false), false);
        $response->assertSee('Forgot Password?', false);
    }
}
