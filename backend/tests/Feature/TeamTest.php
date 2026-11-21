<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Services\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_invitations_are_email_bound_single_use_and_auditable(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->create();
        $owner->businesses()->attach($business, ['role' => 'owner']);
        $this->actingAs($owner);
        $url = "/api/v1/businesses/{$business->id}";

        $invite = $this->postJson("$url/invitations", ['email' => 'team@example.com', 'role' => 'staff'])
            ->assertCreated()->json('token');
        $this->assertDatabaseMissing('business_invitations', ['token_hash' => $invite]);

        $wrongAccount = User::factory()->create();
        $this->actingAs($wrongAccount);
        $this->postJson('/api/v1/invitations/accept', ['token' => $invite])
            ->assertUnprocessable()->assertJsonValidationErrors('token');

        $staff = User::factory()->create(['email' => 'team@example.com']);
        $this->actingAs($staff);
        $this->postJson('/api/v1/invitations/accept', ['token' => $invite])->assertNoContent();
        $this->postJson('/api/v1/invitations/accept', ['token' => $invite])->assertUnprocessable();
        $this->getJson("$url/settings")->assertForbidden();
        $this->getJson("$url/reports/inventory")->assertForbidden();

        $this->actingAs($owner);
        $this->patchJson("$url/members/{$staff->id}", ['role' => 'administrator'])->assertNoContent();
        $this->deleteJson("$url/members/{$owner->id}")->assertForbidden();
        $this->assertDatabaseHas('activity_logs', ['business_id' => $business->id, 'action' => 'member.role_changed']);
        $this->deleteJson("$url/members/{$staff->id}")->assertNoContent();
        $this->actingAs($staff);
        $this->getJson("$url/products")->assertNotFound();
    }

    public function test_registration_using_a_valid_invitation_joins_the_existing_business(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->create();
        $owner->businesses()->attach($business, ['role' => 'owner']);
        $this->actingAs($owner);
        $token = $this->postJson("/api/v1/businesses/{$business->id}/invitations", ['email' => 'new@example.com', 'role' => 'staff'])
            ->assertCreated()->json('token');
        $this->postJson('/api/v1/logout')->assertNoContent();
        $this->postJson('/api/v1/register', [
            'legal_accepted' => true, 'legal_version' => app(Platform::class)->publicSettings()['legal_version'],
            'name' => 'New teammate', 'email' => 'new@example.com', 'token' => $token,
            'password' => 'a-strong-password', 'password_confirmation' => 'a-strong-password',
        ])->assertCreated()->assertJsonPath('businesses.0.id', $business->id)->assertJsonPath('businesses.0.role', 'staff');

        $this->assertDatabaseCount('businesses', 1);
    }
}
