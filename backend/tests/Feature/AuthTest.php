<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_protected_endpoints_require_login(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
        $this->getJson('/api/conversations')->assertUnauthorized();
        $this->postJson('/api/chat', ['content' => 'hi'])->assertUnauthorized();
        $this->getJson('/api/model/status')->assertUnauthorized();
    }

    public function test_there_is_no_registration_endpoint(): void
    {
        $this->postJson('/api/register', ['email' => 'x@example.com', 'password' => 'secret-password'])->assertNotFound();
    }

    public function test_user_can_log_in_and_out(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);

        // The SPA's origin is what makes Sanctum start a cookie session.
        $this->withHeader('Origin', 'http://localhost:5173');

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'correct-horse-battery'])
            ->assertOk()
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonMissingPath('user.password');

        $this->assertAuthenticatedAs($user);

        $this->postJson('/api/logout')->assertOk();
        $this->assertGuest('web');
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest('web');
    }

    public function test_login_is_rate_limited(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
        }

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])->assertTooManyRequests();
    }

    public function test_user_create_command_refuses_a_second_user(): void
    {
        User::factory()->create();

        $this->artisan('user:create')->assertExitCode(1);
        $this->assertSame(1, User::count());
    }
}
