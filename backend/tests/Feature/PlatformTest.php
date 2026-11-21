<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Platform;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlatformTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_settings_require_admin_and_keep_smtp_secrets_private(): void
    {
        $user = User::factory()->create();
        $this->getJson('/api/v1/platform-admin/settings')->assertUnauthorized();
        $this->actingAs($user)->getJson('/api/v1/platform-admin/settings')->assertForbidden();
        $admin = User::factory()->create();
        $admin->forceFill(['platform_admin' => true])->save();
        $this->actingAs($admin);
        $settings = app(Platform::class)->settings();
        $secret = 'example-test-smtp-password';
        $this->putJson('/api/v1/platform-admin/settings', [
            ...$settings, 'name' => 'Test Platform', 'default_currency' => 'NGN', 'smtp_password' => $secret,
        ])->assertOk()->assertJsonPath('smtp_password_set', true)->assertJsonMissingPath('smtp_password');
        $this->getJson('/api/v1/platform')->assertOk()->assertJsonPath('name', 'Test Platform')
            ->assertJsonPath('default_currency', 'NGN')->assertJsonMissingPath('smtp_host')
            ->assertJsonMissingPath('smtp_password_set');
        $this->assertNotSame($secret, app(Platform::class)->settings()['smtp_password']);
    }

    public function test_platform_branding_is_only_writable_by_admin(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create();
        $admin->forceFill(['platform_admin' => true])->save();
        $this->actingAs($admin);
        $image = UploadedFile::fake()->createWithContent('logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aLfoAAAAASUVORK5CYII='));
        $this->postJson('/api/v1/platform-admin/assets/logo', ['image' => $image])->assertOk();
        $this->get('/api/v1/platform/assets/logo')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->deleteJson('/api/v1/platform-admin/assets/logo')->assertOk()->assertJsonPath('logo_url', null);
        $this->get('/api/v1/platform/assets/logo')->assertNotFound();
    }

    public function test_registration_requires_current_consent_and_can_be_disabled(): void
    {
        $version = app(Platform::class)->publicSettings()['legal_version'];
        $data = [
            'name' => 'Test Owner', 'business_name' => 'Test Store', 'email' => 'owner@example.test',
            'password' => 'an-example-password', 'password_confirmation' => 'an-example-password',
            'legal_version' => $version,
        ];
        $this->postJson('/api/v1/register', $data)->assertUnprocessable()->assertJsonValidationErrors('legal_accepted');
        app(Platform::class)->save(['registration_enabled' => false]);
        $this->postJson('/api/v1/register', $data + ['legal_accepted' => true])->assertForbidden();
        app(Platform::class)->save(['registration_enabled' => true, 'terms' => 'A new revision']);
        $this->postJson('/api/v1/register', $data + ['legal_accepted' => true])->assertUnprocessable()->assertJsonValidationErrors('legal_version');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_password_reset_sends_a_one_use_token_and_hides_account_existence(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->postJson('/api/v1/forgot-password', ['email' => 'missing@example.test'])->assertNoContent();
        $this->postJson('/api/v1/forgot-password', ['email' => $user->email])->assertNoContent();
        $notice = Notification::sent($user, ResetPassword::class)->sole();
        $this->assertStringContainsString('/reset-password#token=', $notice->toMail($user)->actionUrl);
        $payload = [
            'email' => $user->email, 'token' => $notice->token,
            'password' => 'a-reset-test-password', 'password_confirmation' => 'a-reset-test-password',
        ];
        $this->postJson('/api/v1/reset-password', $payload)->assertNoContent();
        $this->assertTrue(Hash::check($payload['password'], $user->refresh()->password));
        $this->postJson('/api/v1/reset-password', $payload)->assertUnprocessable();
    }
}
