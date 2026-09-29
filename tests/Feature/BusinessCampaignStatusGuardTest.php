<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Business campaign status guard.
 *
 * A business may pause / resume / cancel its LIVE campaigns only. Drafts go
 * live exclusively through the wizard launch (atomic escrow funding gate);
 * pending_review campaigns are activated exclusively by staff approval.
 * Before the guard, a business could flip draft / pending_review straight to
 * 'active', bypassing both gates and exposing unfunded tasks to
 * contributors (approvals would credit from a phantom budget with no escrow
 * behind it). Cross-tenant / missing-business access must answer 404, not 500.
 */
class BusinessCampaignStatusGuardTest extends TestCase
{
    use RefreshDatabase;

    private function businessUser(): User
    {
        $this->seed();

        return User::where('email', 'brand@acme.com')->firstOrFail();
    }

    private function makeCampaign(User $business, string $status, array $overrides = []): Campaign
    {
        return Campaign::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'business_id' => $business->business->id,
            'category_id' => TaskCategory::firstOrFail()->id,
            'title' => 'Status guard campaign ' . Str::random(6),
            'description' => 'Status guard test.',
            'status' => $status,
            'total_budget_cents' => 10000,
            'reward_per_task_cents' => 15,
            'target_contributors_count' => 10,
            'remaining_budget_cents' => 10000,
            'reserved_budget_cents' => 0,
        ], $overrides));
    }

    public function test_business_cannot_activate_a_draft_campaign(): void
    {
        $business = $this->businessUser();
        $campaign = $this->makeCampaign($business, 'draft');

        Sanctum::actingAs($business);

        $response = $this->patchJson(
            "/api/v1/business/campaigns/{$campaign->id}/status",
            ['status' => 'active']
        );

        $response->assertStatus(422);
        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id, 'status' => 'draft']);
    }

    public function test_business_cannot_activate_a_campaign_awaiting_staff_review(): void
    {
        $business = $this->businessUser();
        $campaign = $this->makeCampaign($business, 'pending_review');

        Sanctum::actingAs($business);

        $response = $this->patchJson(
            "/api/v1/business/campaigns/{$campaign->id}/status",
            ['status' => 'active']
        );

        $response->assertStatus(422);
        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id, 'status' => 'pending_review']);
    }

    public function test_business_cannot_reactivate_a_cancelled_campaign(): void
    {
        $business = $this->businessUser();
        $campaign = $this->makeCampaign($business, 'cancelled', [
            'remaining_budget_cents' => 0,
            'reserved_budget_cents' => 0,
        ]);

        Sanctum::actingAs($business);

        $response = $this->patchJson(
            "/api/v1/business/campaigns/{$campaign->id}/status",
            ['status' => 'active']
        );

        $response->assertStatus(422);
        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id, 'status' => 'cancelled']);
    }

    public function test_business_can_pause_resume_and_cancel_a_live_campaign(): void
    {
        $business = $this->businessUser();

        // Simulate the escrow hold sitting in the wallet's pending balance.
        $wallet = Wallet::firstOrCreate(
            ['user_id' => $business->id],
            ['currency' => 'USD', 'available_balance_cents' => 0]
        );
        $wallet->update(['available_balance_cents' => 0, 'pending_balance_cents' => 10000]);

        $campaign = $this->makeCampaign($business, 'active');

        Sanctum::actingAs($business);

        $this->patchJson("/api/v1/business/campaigns/{$campaign->id}/status", ['status' => 'paused'])
            ->assertStatus(200);
        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id, 'status' => 'paused']);

        $this->patchJson("/api/v1/business/campaigns/{$campaign->id}/status", ['status' => 'active'])
            ->assertStatus(200);
        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id, 'status' => 'active']);

        // Cancelling refunds the unspent escrow (pending -> available) and
        // zeroes the campaign pool.
        $this->patchJson("/api/v1/business/campaigns/{$campaign->id}/status", ['status' => 'cancelled'])
            ->assertStatus(200);
        $this->assertDatabaseHas('campaigns', [
            'id' => $campaign->id,
            'status' => 'cancelled',
            'remaining_budget_cents' => 0,
            'reserved_budget_cents' => 0,
        ]);

        $wallet->refresh();
        $this->assertSame(0, (int) $wallet->pending_balance_cents);
        $this->assertSame(10000, (int) $wallet->available_balance_cents);
    }

    public function test_business_can_withdraw_a_campaign_awaiting_review(): void
    {
        $business = $this->businessUser();

        $wallet = Wallet::firstOrCreate(
            ['user_id' => $business->id],
            ['currency' => 'USD', 'available_balance_cents' => 0]
        );
        $wallet->update(['available_balance_cents' => 0, 'pending_balance_cents' => 5000]);

        $campaign = $this->makeCampaign($business, 'pending_review', [
            'remaining_budget_cents' => 5000,
        ]);

        Sanctum::actingAs($business);

        $this->patchJson("/api/v1/business/campaigns/{$campaign->id}/status", ['status' => 'cancelled'])
            ->assertStatus(200);
        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id, 'status' => 'cancelled']);

        $wallet->refresh();
        $this->assertSame(0, (int) $wallet->pending_balance_cents);
        $this->assertSame(5000, (int) $wallet->available_balance_cents);
    }

    public function test_missing_business_profile_returns_404_not_500(): void
    {
        $this->seed();

        $user = User::create([
            'name' => 'Orphan Biz',
            'email' => 'orphan-biz@example.com',
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => 'business',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($user);

        // Before the guard these dereferenced null->id and answered 500.
        $this->getJson('/api/v1/business/submissions')->assertStatus(404);
        $this->patchJson('/api/v1/business/campaigns/1/status', ['status' => 'paused'])
            ->assertStatus(404);
    }

    public function test_public_task_feed_clamps_per_page(): void
    {
        $business = $this->businessUser();
        $campaign = $this->makeCampaign($business, 'active');

        Task::create([
            'uuid' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'category_id' => $campaign->category_id,
            'title' => 'Clamp task',
            'reward_cents' => 15,
            'status' => 'available',
            'slots_total' => 5,
            'slots_taken' => 0,
        ]);

        $response = $this->getJson('/api/v1/tasks?per_page=5000');

        $response->assertStatus(200);
        $this->assertSame(100, $response->json('meta.per_page'));
    }
}
