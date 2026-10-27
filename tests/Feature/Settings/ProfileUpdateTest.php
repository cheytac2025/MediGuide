<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();
    }

    public function test_profile_information_cannot_be_self_updated()
    {
        $user = User::factory()->create();
        $originalName = $user->name;
        $originalEmail = $user->email;

        $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ])
            ->assertForbidden();

        $user->refresh();

        $this->assertSame($originalName, $user->name);
        $this->assertSame($originalEmail, $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_user_cannot_delete_their_own_account()
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ])
            ->assertForbidden();

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh());
    }

    public function test_wrong_password_does_not_delete_the_account()
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'wrong-password',
            ])
            ->assertForbidden();

        $this->assertNotNull($user->fresh());
    }
}
