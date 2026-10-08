<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Per-sidebar-section permissions, delegation (an admin only hands out what
 * they hold), wallet credit permission, and assigned-user scoping.
 */
class StaffAccessControlTest extends TestCase
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

    protected function superadmin(): User
    {
        return User::where('role', 'superadmin')->first() ?? $this->makeUser('superadmin');
    }

    public function test_each_sidebar_section_can_be_switched_off_per_account(): void
    {
        $admin = $this->makeUser('admin');
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/health')->assertOk();
        $this->getJson('/api/v1/admin/referrals/overview')->assertOk();

        $admin->syncPermissionOverrides([], ['view_system_health', 'view_traffic', 'view_referrals']);
        $this->getJson('/api/v1/admin/health')->assertStatus(403);
        $this->getJson('/api/v1/admin/traffic')->assertStatus(403);
        $this->getJson('/api/v1/admin/referrals/overview')->assertStatus(403);
        // Other sections are untouched.
        $this->getJson('/api/v1/admin/demo-requests')->assertOk();
    }

    public function test_businesses_only_admin_sees_business_accounts_only(): void
    {
        $business = $this->makeUser('business');
        $contributor = $this->makeUser('contributor');
        $admin = $this->makeUser('admin');
        // Keeps manage_businesses; manage_roles also lists every account.
        $admin->syncPermissionOverrides([], ['manage_users', 'manage_roles']);
        Sanctum::actingAs($admin);

        $roles = collect($this->getJson('/api/v1/admin/users')->assertOk()->json('data'))->pluck('role')->unique()->values()->all();
        $this->assertSame(['business'], $roles);

        $this->getJson("/api/v1/admin/users/{$business->id}")->assertOk();
        $this->getJson("/api/v1/admin/users/{$contributor->id}")->assertStatus(404);
    }

    public function test_wallet_credit_needs_its_own_permission_and_a_reason(): void
    {
        $target = $this->makeUser('business');
        $wallet = Wallet::where('user_id', $target->id)->first();
        $admin = $this->makeUser('admin');
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/ops/wallets/{$wallet->id}/credit", ['amount' => 10, 'description' => 'Bonus'])->assertStatus(403);

        $admin->syncPermissionOverrides(['adjust_wallets'], []);
        $this->postJson("/api/v1/ops/wallets/{$wallet->id}/credit", ['amount' => 10])->assertStatus(422);
        $this->postJson("/api/v1/ops/wallets/{$wallet->id}/credit", ['amount' => 10, 'description' => 'Goodwill bonus'])->assertOk();
        $this->assertSame(1000, (int) $wallet->fresh()->available_balance_cents);
        $this->getJson('/api/v1/ops/wallets')->assertOk();
    }

    public function test_admin_can_only_delegate_permissions_they_hold(): void
    {
        $moderator = $this->makeUser('moderator');
        $admin = $this->makeUser('admin');
        Sanctum::actingAs($admin);

        // Admin holds view_traffic (role default) but not adjust_wallets.
        $this->putJson("/api/v1/ops/users/{$moderator->id}/permissions", ['grants' => ['view_traffic'], 'denies' => []])->assertOk();
        $this->putJson("/api/v1/ops/users/{$moderator->id}/permissions", ['grants' => ['view_traffic', 'adjust_wallets'], 'denies' => []])
            ->assertStatus(403)->assertJsonPath('code', 'permission_not_held');

        // A grant Super Admin already placed may stay when the admin re-saves.
        $moderator->syncPermissionOverrides(['view_traffic', 'adjust_wallets'], []);
        $this->putJson("/api/v1/ops/users/{$moderator->id}/permissions", ['grants' => ['view_traffic', 'adjust_wallets'], 'denies' => []])->assertOk();
    }

    public function test_assigned_admin_only_sees_their_users(): void
    {
        $mine = $this->makeUser('contributor');
        $other = $this->makeUser('contributor');
        $admin = $this->makeUser('admin');

        Sanctum::actingAs($this->superadmin());
        $this->putJson("/api/v1/ops/staff/{$admin->id}/assignments", ['user_ids' => [$mine->id]])->assertOk();
        $this->getJson("/api/v1/ops/staff/{$admin->id}/assignments")->assertOk()->assertJsonCount(1, 'data.users');

        Sanctum::actingAs($admin);
        $ids = collect($this->getJson('/api/v1/admin/users')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$mine->id], $ids);
        $this->getJson("/api/v1/admin/users/{$mine->id}")->assertOk();
        $this->getJson("/api/v1/admin/users/{$other->id}")->assertStatus(404);
        $this->getJson("/api/v1/ops/users/{$other->id}/permissions")->assertStatus(404);

        // Clearing the assignments restores full access.
        Sanctum::actingAs($this->superadmin());
        $this->putJson("/api/v1/ops/staff/{$admin->id}/assignments", ['user_ids' => []])->assertOk();
        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/admin/users/{$other->id}")->assertOk();
    }

    public function test_assignment_rules_and_superadmin_only(): void
    {
        $admin = $this->makeUser('admin');
        $moderator = $this->makeUser('moderator');
        $otherAdmin = $this->makeUser('admin');
        $contributor = $this->makeUser('contributor');

        Sanctum::actingAs($this->superadmin());
        // Admins cannot be assigned to admins; moderators cannot get moderators.
        $this->putJson("/api/v1/ops/staff/{$admin->id}/assignments", ['user_ids' => [$otherAdmin->id]])->assertStatus(422);
        $this->putJson("/api/v1/ops/staff/{$moderator->id}/assignments", ['user_ids' => [$contributor->id]])->assertOk();

        Sanctum::actingAs($admin);
        $this->putJson("/api/v1/ops/staff/{$moderator->id}/assignments", ['user_ids' => []])->assertStatus(403);
    }
}
