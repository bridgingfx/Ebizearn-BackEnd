<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Campaign;
use App\Models\TaskCategory;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 13: tenant isolation.
 * - contributors see only their own data (wallet, referrals)
 * - businesses see only their own campaigns (cross-business read -> 404)
 * - role gates return 403 across role boundaries
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function makeBusiness(string $email): User
    {
        $user = User::create([
            'name' => 'Biz ' . $email,
            'email' => $email,
            'password' => Hash::make('password123'),
            'role' => 'business',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        Business::create([
            'owner_id' => $user->id,
            'company_name' => 'Company ' . $email,
            'status' => 'active',
        ]);

        Wallet::create(['user_id' => $user->id, 'currency' => 'USD', 'available_balance_cents' => 0]);

        return $user;
    }

    protected function makeCampaign(Business $business, string $title): Campaign
    {
        return Campaign::create([
            'uuid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'category_id' => TaskCategory::firstOrFail()->id,
            'title' => $title,
            'description' => 'Isolation test campaign',
            'status' => 'draft',
            'total_budget_cents' => 10000,
            'remaining_budget_cents' => 10000,
            'reward_per_task_cents' => 100,
            'target_contributors_count' => 10,
        ]);
    }

    public function test_business_cannot_read_another_business_campaign(): void
    {
        $bizA = $this->makeBusiness('a-biz@example.com');
        $bizB = $this->makeBusiness('b-biz@example.com');

        $campaignA = $this->makeCampaign($bizA->business, 'Campaign A');

        Sanctum::actingAs($bizB);

        // Phase 13: cross-business read fails closed via CampaignPolicy → 403
        $this->getJson("/api/v1/business/campaigns/{$campaignA->id}")->assertStatus(403);

        // Own campaign reads fine
        Sanctum::actingAs($bizA);
        $this->getJson("/api/v1/business/campaigns/{$campaignA->id}")->assertStatus(200);

        // Campaign index lists only own campaigns
        Sanctum::actingAs($bizB);
        $response = $this->getJson('/api/v1/business/campaigns');
        $response->assertStatus(200);
        $this->assertEmpty($response->json('data'));
    }

    public function test_contributor_wallet_is_always_their_own(): void
    {
        $a = User::factory()->create(['role' => 'contributor']);
        $b = User::factory()->create(['role' => 'contributor']);

        Wallet::create(['user_id' => $a->id, 'currency' => 'USD', 'available_balance_cents' => 1111]);
        Wallet::create(['user_id' => $b->id, 'currency' => 'USD', 'available_balance_cents' => 2222]);

        Sanctum::actingAs($a);
        $response = $this->getJson('/api/v1/wallet');
        $response->assertStatus(200);
        $this->assertSame($a->wallet->id, $response->json('data.wallet.id'));
        $this->assertSame(1111, $response->json('data.wallet.available_balance_cents'));

        Sanctum::actingAs($b);
        $response = $this->getJson('/api/v1/wallet');
        $this->assertSame(2222, $response->json('data.wallet.available_balance_cents'));
    }

    public function test_role_boundaries_return_403(): void
    {
        $contributor = User::factory()->create(['role' => 'contributor']);
        Sanctum::actingAs($contributor);

        $this->getJson('/api/v1/business/dashboard')->assertStatus(403);
        $this->postJson('/api/v1/business/campaigns', [])->assertStatus(403);
        $this->getJson('/api/v1/admin/dashboard')->assertStatus(403);

        $business = User::factory()->create(['role' => 'business']);
        Sanctum::actingAs($business);

        $this->getJson('/api/v1/contributor/dashboard')->assertStatus(403);
        $this->getJson('/api/v1/wallet')->assertStatus(403);
        $this->getJson('/api/v1/admin/dashboard')->assertStatus(403);

        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/ops/admins')->assertStatus(403);
        $this->getJson('/api/v1/contributor/dashboard')->assertStatus(403);
    }

    public function test_guest_gets_401_on_protected_routes(): void
    {
        $this->getJson('/api/v1/wallet')->assertStatus(401);
        $this->getJson('/api/v1/ops/admins')->assertStatus(401);
        $this->getJson('/api/v1/contributor/referrals')->assertStatus(401);
    }
}
