<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Services\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_an_owner_and_returns_only_their_businesses(): void
    {
        Business::factory()->create();
        $password = 'long-test-secret';
        $this->postJson('/api/v1/register', [
            'legal_accepted' => true, 'legal_version' => app(Platform::class)->publicSettings()['legal_version'],
            'name' => 'Alex Merchant',
            'email' => 'Alex@example.com',
            'business_name' => 'Alex Supply',
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertCreated()
            ->assertJsonPath('user.email', 'alex@example.com')
            ->assertJsonMissingPath('user.password')
            ->assertJsonCount(1, 'businesses')
            ->assertJsonPath('businesses.0.role', 'owner');

        $this->assertAuthenticated();
        $this->assertDatabaseHas('business_memberships', ['role' => 'owner']);
    }

    public function test_password_validation_prevents_partial_registration(): void
    {
        $this->postJson('/api/v1/register', [
            'legal_accepted' => true, 'legal_version' => app(Platform::class)->publicSettings()['legal_version'],
            'name' => 'Alex Merchant',
            'email' => 'alex@example.com',
            'business_name' => 'Alex Supply',
            'password' => 'invalid',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('businesses', 0);
    }

    public function test_login_and_logout_control_account_access(): void
    {
        $user = User::factory()->create();
        $this->getJson('/api/v1/account')->assertUnauthorized();

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'incorrect',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('user.id', $user->id);
        $this->getJson('/api/v1/account')->assertOk()->assertJsonPath('user.id', $user->id);

        $this->postJson('/api/v1/logout')->assertNoContent();
        $this->assertGuest();
    }

    public function test_password_changes_require_the_current_password_and_rotate_credentials(): void
    {
        config(['sanctum.stateful' => ['localhost:5173']]);
        $user = User::factory()->create();
        $this->actingAs($user)->withHeader('Origin', 'http://localhost:5173');
        $input = ['password' => 'a-new-secure-password', 'password_confirmation' => 'a-new-secure-password'];

        $this->putJson('/api/v1/profile/password', ['current_password' => 'wrong'] + $input)
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson('/api/v1/profile/password', ['current_password' => 'password'] + $input)
            ->assertNoContent();
        $this->assertTrue(Hash::check($input['password'], $user->refresh()->password));
        $this->assertFalse(Hash::check('password', $user->password));
    }
}
