<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_directory_is_restricted_and_searchable(): void
    {
        $member = User::factory()->create(['name' => 'Directory match', 'email' => 'match@example.test']);
        $this->getJson('/api/v1/platform-admin/users')->assertUnauthorized();
        $this->actingAs($member)->getJson('/api/v1/platform-admin/businesses')->assertForbidden();
        $this->actingAs($this->admin())->getJson('/api/v1/platform-admin/users?search=match%40example.test')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $member->id)
            ->assertJsonMissingPath('data.0.password');
    }

    public function test_account_suspension_blocks_existing_sessions_and_new_logins_until_reactivated(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $reason = ['reason' => 'Access review'];
        $this->actingAs($admin)->putJson("/api/v1/platform-admin/users/{$admin->id}/status", $reason + ['suspended' => true])->assertForbidden();
        $this->putJson("/api/v1/platform-admin/users/{$user->id}/status", $reason + ['suspended' => true])->assertOk();
        $this->actingAs($user->refresh())->getJson('/api/v1/account')->assertForbidden();
        $this->postJson('/api/v1/logout')->assertNoContent();
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->assertUnprocessable();

        auth()->forgetGuards();
        $this->actingAs($admin, 'web')->putJson("/api/v1/platform-admin/users/{$user->id}/status", $reason + ['suspended' => false])
            ->assertOk()->assertJsonPath('suspended_at', null);
        $this->assertDatabaseHas('platform_activity', ['action' => 'user.reactivated', 'actor_id' => $admin->id, 'target_id' => $user->id]);
        $this->postJson('/api/v1/logout')->assertNoContent();
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    }

    public function test_business_suspension_preserves_memberships_and_blocks_tenant_access(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create();
        $business = Business::factory()->create();
        $other = Business::factory()->create();
        $owner->businesses()->attach([$business->id => ['role' => 'owner'], $other->id => ['role' => 'owner']]);
        $path = "/api/v1/platform-admin/businesses/{$business->id}/status";

        $this->actingAs($admin)->putJson($path, ['suspended' => true, 'reason' => 'Account review'])->assertOk()->assertJsonCount(1, 'members.data');
        $this->actingAs($owner)->getJson("/api/v1/businesses/{$business->id}/products")->assertForbidden();
        $this->getJson("/api/v1/businesses/{$other->id}/products")->assertOk();
        $this->getJson('/api/v1/account')->assertOk()->assertJsonCount(2, 'businesses');

        $this->actingAs($admin)->putJson($path, ['suspended' => false, 'reason' => 'Review complete'])->assertOk();
        $this->actingAs($owner)->getJson("/api/v1/businesses/{$business->id}/products")->assertOk();
    }

    public function test_platform_admin_can_manage_members_and_transfer_ownership_without_removing_the_current_owner(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create();
        $replacement = User::factory()->create();
        $business = Business::factory()->create();
        $owner->businesses()->attach($business, ['role' => 'owner']);
        $path = "/api/v1/platform-admin/businesses/{$business->id}/members";
        $reason = ['reason' => 'Approved by the business'];

        $this->actingAs($admin)->deleteJson("$path/{$owner->id}", $reason)->assertConflict();
        $this->putJson("$path/{$replacement->id}", $reason + ['role' => 'staff'])->assertOk();
        $this->putJson("$path/{$replacement->id}", $reason + ['role' => 'owner'])->assertOk();
        $this->assertDatabaseHas('business_memberships', ['business_id' => $business->id, 'user_id' => $replacement->id, 'role' => 'owner']);
        $this->assertDatabaseHas('business_memberships', ['business_id' => $business->id, 'user_id' => $owner->id, 'role' => 'administrator']);
        $this->deleteJson("$path/{$owner->id}", $reason)->assertOk();
        $this->getJson('/api/v1/platform-admin/activity')->assertOk()->assertJsonCount(3, 'data');
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['platform_admin' => true])->save();

        return $admin;
    }
}
