<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Profile;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Business sidebar sections, business team members (owner-chosen access,
 * capped at the owner's), and moderators setting access per user.
 */
class BusinessTeamAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function makeUser(string $role, array $attrs = []): User
    {
        $user = User::forceCreate(array_merge([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($role) . ' ' . Str::random(4),
            'email' => $role . Str::random(6) . '@example.com',
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ], $attrs));
        Profile::forceCreate(['user_id' => $user->id, 'country_code' => 'AE']);
        Wallet::firstOrCreate(['user_id' => $user->id], ['currency' => 'USD', 'available_balance_cents' => 0]);

        return $user;
    }

    protected function makeOwner(): User
    {
        $owner = $this->makeUser('business');
        Business::forceCreate(['owner_id' => $owner->id, 'company_name' => 'Acme Ltd', 'status' => 'active']);

        return $owner;
    }

    protected function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh());
    }

    public function test_business_sidebar_sections_can_be_switched_off(): void
    {
        $owner = $this->makeOwner();
        $this->actAs($owner);
        $this->getJson('/api/v1/business/campaigns')->assertOk();
        $this->getJson('/api/v1/business/deposits')->assertOk();

        $owner->syncPermissionOverrides([], ['view_own_campaigns', 'view_billing', 'view_business_analytics']);
        $this->actAs($owner);
        $this->getJson('/api/v1/business/campaigns')->assertForbidden();
        $this->getJson('/api/v1/business/deposits')->assertForbidden();
        $this->getJson('/api/v1/business/analytics')->assertForbidden();
        $this->getJson('/api/v1/business/dashboard')->assertOk();
    }

    public function test_owner_adds_team_member_with_chosen_sections(): void
    {
        $owner = $this->makeOwner();
        $this->actAs($owner);

        $this->postJson('/api/v1/business/team', [
            'name' => 'Priya Member',
            'email' => 'priya.member@example.com',
            'password' => 'Str0ngPass!',
            'permissions' => ['view_own_campaigns', 'review_campaign_proofs'],
        ])->assertCreated()->assertJsonPath('data.members.0.email', 'priya.member@example.com');

        $member = User::where('email', 'priya.member@example.com')->firstOrFail();
        $this->assertSame($owner->id, (int) $member->business_owner_id);
        $this->assertSame('business', $member->role);

        // The member works on the owner's business…
        $this->actAs($member);
        $this->assertSame('Acme Ltd', $member->fresh()->business->company_name);
        $this->getJson('/api/v1/business/campaigns')->assertOk();
        $this->getJson('/api/v1/business/submissions')->assertOk();
        // …only in the ticked sections, and never manages the team.
        $this->getJson('/api/v1/business/deposits')->assertForbidden();
        $this->getJson('/api/v1/business/analytics')->assertForbidden();
        $this->postJson('/api/v1/business/campaigns', [])->assertForbidden();
        $this->getJson('/api/v1/business/team')->assertForbidden();

        // The owner widens access.
        $this->actAs($owner);
        $this->putJson("/api/v1/business/team/{$member->id}/permissions", [
            'permissions' => ['view_own_campaigns', 'view_billing'],
        ])->assertOk();
        $this->actAs($member);
        $this->getJson('/api/v1/business/deposits')->assertOk();
        $this->getJson('/api/v1/business/submissions')->assertForbidden();

        // Suspended members cannot sign in.
        $this->actAs($owner);
        $this->patchJson("/api/v1/business/team/{$member->id}/status", ['status' => 'suspended'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => 'priya.member@example.com', 'password' => 'Str0ngPass!'])->assertForbidden();
    }

    public function test_owner_cannot_give_more_than_they_have_and_member_is_capped(): void
    {
        $owner = $this->makeOwner();
        $owner->syncPermissionOverrides([], ['view_billing']);
        $this->actAs($owner);

        $this->postJson('/api/v1/business/team', [
            'name' => 'Too Much',
            'email' => 'too.much@example.com',
            'password' => 'Str0ngPass!',
            'permissions' => ['view_billing'],
        ])->assertForbidden()->assertJsonPath('code', 'permission_not_held');

        // Team management itself can never be handed to a member.
        $this->postJson('/api/v1/business/team', [
            'name' => 'Team Admin',
            'email' => 'team.admin@example.com',
            'password' => 'Str0ngPass!',
            'permissions' => ['manage_team'],
        ])->assertStatus(422);

        $this->postJson('/api/v1/business/team', [
            'name' => 'Capped',
            'email' => 'capped@example.com',
            'password' => 'Str0ngPass!',
            'permissions' => ['view_own_campaigns'],
        ])->assertCreated();
        $member = User::where('email', 'capped@example.com')->firstOrFail();

        // Super Admin takes Campaigns away from the owner: the member loses it too.
        $owner->syncPermissionOverrides([], ['view_billing', 'view_own_campaigns']);
        $this->actAs($member);
        $this->getJson('/api/v1/business/campaigns')->assertForbidden();
        $this->assertNotContains('view_own_campaigns', $member->fresh()->effectivePermissions());
    }

    public function test_owners_only_see_their_own_team(): void
    {
        $a = $this->makeOwner();
        $b = $this->makeOwner();
        $this->actAs($a);
        $this->postJson('/api/v1/business/team', [
            'name' => 'A Member', 'email' => 'a.member@example.com', 'password' => 'Str0ngPass!', 'permissions' => [],
        ])->assertCreated();
        $member = User::where('email', 'a.member@example.com')->firstOrFail();

        $this->actAs($b);
        $this->getJson('/api/v1/business/team')->assertOk()->assertJsonCount(0, 'data.members');
        $this->putJson("/api/v1/business/team/{$member->id}/permissions", ['permissions' => []])->assertNotFound();
        $this->deleteJson("/api/v1/business/team/{$member->id}")->assertNotFound();
    }

    public function test_moderator_sets_access_per_user_only(): void
    {
        $moderator = $this->makeUser('moderator');
        $moderator->syncPermissionOverrides(['manage_roles'], []);
        $contributor = $this->makeUser('contributor');
        $admin = $this->makeUser('admin');
        $otherModerator = $this->makeUser('moderator');
        $this->actAs($moderator);

        $this->getJson('/api/v1/ops/roles')->assertOk();
        $this->putJson('/api/v1/ops/roles/contributor/permissions', ['permissions' => []])->assertForbidden();

        // A contributor below them: allowed, but only with powers they hold.
        $this->putJson("/api/v1/ops/users/{$contributor->id}/permissions", ['grants' => [], 'denies' => ['use_referrals']])->assertOk();
        $this->putJson("/api/v1/ops/users/{$contributor->id}/permissions", ['grants' => ['process_payouts'], 'denies' => []])
            ->assertForbidden()->assertJsonPath('code', 'permission_not_held');

        // Never staff at or above their own level.
        $this->putJson("/api/v1/ops/users/{$admin->id}/permissions", ['grants' => [], 'denies' => []])->assertForbidden();
        $this->putJson("/api/v1/ops/users/{$otherModerator->id}/permissions", ['grants' => [], 'denies' => []])->assertForbidden();

        // Without manage_roles the moderator cannot reach it at all.
        $this->actAs($otherModerator);
        $this->getJson('/api/v1/ops/roles')->assertForbidden();
    }

    public function test_moderator_reaches_staff_sections_only_when_granted(): void
    {
        $moderator = $this->makeUser('moderator');
        $this->makeOwner();
        $this->actAs($moderator);
        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->getJson('/api/v1/admin/health')->assertForbidden();
        $this->getJson('/api/v1/admin/dashboard')->assertForbidden();

        $moderator->syncPermissionOverrides(['manage_businesses', 'view_system_health'], []);
        $this->actAs($moderator);
        $roles = collect($this->getJson('/api/v1/admin/users')->assertOk()->json('data'))->pluck('role')->unique()->values()->all();
        $this->assertSame(['business'], $roles);
        $this->getJson('/api/v1/admin/health')->assertOk();
        $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }
}
