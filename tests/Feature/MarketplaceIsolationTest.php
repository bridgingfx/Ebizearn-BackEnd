<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Campaign;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\TaskSubmission;
use App\Models\TaskType;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 4/9/13: tenant and role isolation across the marketplace core.
 * - businesses touch only their own campaigns/tasks (403 otherwise)
 * - contributors cannot reach business or staff APIs (403)
 * - businesses cannot reach staff APIs; moderators cannot reach /ops
 * - staff campaign management: pause/cancel with escrow release, safe delete
 */
class MarketplaceIsolationTest extends TestCase
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
        Business::create(['owner_id' => $user->id, 'company_name' => 'Co ' . $email, 'status' => 'active']);
        Wallet::create(['user_id' => $user->id, 'currency' => 'USD', 'available_balance_cents' => 0]);

        return $user;
    }

    protected function makeContributor(string $email): User
    {
        $user = User::create([
            'name' => 'C ' . $email,
            'email' => $email,
            'password' => Hash::make('password123'),
            'role' => 'contributor',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        Wallet::create(['user_id' => $user->id, 'currency' => 'USD', 'available_balance_cents' => 0]);

        return $user;
    }

    protected function makeModerator(string $email): User
    {
        return User::create([
            'name' => 'Mod ' . $email,
            'email' => $email,
            'password' => Hash::make('password123'),
            'role' => 'moderator',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    protected function makeCampaign(Business $business): Campaign
    {
        return Campaign::create([
            'uuid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'category_id' => TaskCategory::firstOrFail()->id,
            'title' => 'Iso campaign ' . Str::random(6),
            'description' => 'isolation test',
            'status' => 'active',
            'total_budget_cents' => 100000,
            'remaining_budget_cents' => 100000,
            'reserved_budget_cents' => 0,
            'reward_per_task_cents' => 15,
            'target_contributors_count' => 100,
        ]);
    }

    protected function makeTask(Campaign $campaign): Task
    {
        return Task::create([
            'uuid' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'category_id' => $campaign->category_id,
            'task_type_id' => TaskType::where('key', 'follow')->firstOrFail()->id,
            'title' => 'Iso task ' . Str::random(6),
            'description' => 'x',
            'platform' => 'instagram',
            'reward_cents' => 15,
            'slots_total' => 10,
            'slots_filled' => 0,
            'status' => 'available',
        ]);
    }

    public function test_business_cannot_touch_another_business_tasks(): void
    {
        $bizA = $this->makeBusiness('iso-a@example.com');
        $bizB = $this->makeBusiness('iso-b@example.com');
        $taskA = $this->makeTask($this->makeCampaign($bizA->business));

        Sanctum::actingAs($bizB);

        $this->getJson("/api/v1/business/tasks/{$taskA->id}")->assertStatus(403);
        $this->patchJson("/api/v1/business/tasks/{$taskA->id}", ['title' => 'Hijacked'])
            ->assertStatus(403);
        $this->deleteJson("/api/v1/business/tasks/{$taskA->id}")->assertStatus(403);

        // B cannot create a task under A's campaign either.
        $this->postJson('/api/v1/business/tasks', [
            'campaign_id' => $taskA->campaign_id,
            'task_type_key' => 'follow',
            'title' => 'Hijack task',
            'platform' => 'instagram',
            'reward_cents' => 15,
            'slots_total' => 5,
        ])->assertStatus(403);

        $this->assertEquals('Iso task', substr($taskA->fresh()->title, 0, 8));
    }

    public function test_business_task_list_is_tenant_scoped(): void
    {
        $bizA = $this->makeBusiness('iso-a2@example.com');
        $bizB = $this->makeBusiness('iso-b2@example.com');
        $this->makeTask($this->makeCampaign($bizA->business));

        Sanctum::actingAs($bizB);

        $response = $this->getJson('/api/v1/business/tasks');
        $response->assertStatus(200);
        $this->assertSame(0, count($response->json('data.data') ?? $response->json('data')));
    }

    public function test_contributor_cannot_reach_business_or_staff_apis(): void
    {
        $contributor = $this->makeContributor('iso-c@example.com');

        Sanctum::actingAs($contributor);

        $this->getJson('/api/v1/business/tasks')->assertStatus(403);
        $this->postJson('/api/v1/business/campaigns/wizard/preview', [
            'reward_cents' => 20, 'contributors' => 10,
        ])->assertStatus(403);
        $this->getJson('/api/v1/staff/tasks')->assertStatus(403);
        $this->getJson('/api/v1/moderator/verification-queue')->assertStatus(403);
        $this->getJson('/api/v1/ops/task-types')->assertStatus(403);
    }

    public function test_business_cannot_reach_staff_or_ops_apis(): void
    {
        $biz = $this->makeBusiness('iso-b3@example.com');

        Sanctum::actingAs($biz);

        $this->getJson('/api/v1/staff/tasks')->assertStatus(403);
        $this->getJson('/api/v1/staff/campaigns')->assertStatus(403);
        $this->getJson('/api/v1/moderator/verification-queue')->assertStatus(403);
        $this->getJson('/api/v1/ops/task-types')->assertStatus(403);
    }

    public function test_moderator_cannot_reach_ops_but_can_use_staff_queue(): void
    {
        $moderator = $this->makeModerator('iso-m@example.com');

        Sanctum::actingAs($moderator);

        // Ops is superadmin-only.
        $this->getJson('/api/v1/ops/task-types')->assertStatus(403);

        // Staff APIs work through the permission gate.
        $this->getJson('/api/v1/moderator/verification-queue')->assertStatus(200);
        $this->getJson('/api/v1/staff/tasks')->assertStatus(200);
        $this->getJson('/api/v1/staff/campaigns')->assertStatus(200);
    }

    public function test_staff_can_pause_and_cancel_campaign_with_escrow_release(): void
    {
        $admin = User::where('email', 'admin@ebizearn.com')->firstOrFail();
        $biz = $this->makeBusiness('iso-b4@example.com');

        // Fund + launch via the wizard so real escrow is held.
        $wallet = Wallet::where('user_id', $biz->id)->firstOrFail();
        app(\App\Services\Wallet\WalletLedgerService::class)
            ->credit($wallet, 10000, 'campaign_funding', 'Top-up');

        Sanctum::actingAs($biz);
        $draftId = $this->postJson('/api/v1/business/campaigns/wizard/draft', [
            'title' => 'Pausable campaign',
            'category_id' => TaskCategory::firstOrFail()->id,
            'task_type_key' => 'follow',
            'reward_cents' => 20,
            'contributors' => 100,
        ])->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/business/campaigns/{$draftId}/launch", [])->assertStatus(200);

        $this->assertSame(2000, (int) $wallet->fresh()->pending_balance_cents);

        Sanctum::actingAs($admin);

        // Staff sees all campaigns cross-tenant.
        $this->getJson('/api/v1/staff/campaigns')->assertStatus(200);

        // Priority 4 — approval gate: approve pending_review → active first.
        $this->patchJson("/api/v1/staff/campaigns/{$draftId}/status", ['status' => 'active'])
            ->assertStatus(200);
        $this->assertEquals('active', Campaign::findOrFail($draftId)->status);

        // Pause.
        $this->patchJson("/api/v1/staff/campaigns/{$draftId}/status", ['status' => 'paused'])
            ->assertStatus(200);
        $this->assertEquals('paused', Campaign::findOrFail($draftId)->status);

        // Invalid transition: paused -> draft is refused.
        $this->patchJson("/api/v1/staff/campaigns/{$draftId}/status", ['status' => 'draft'])
            ->assertStatus(422);

        // Cancel releases the unspent escrow back to available.
        $this->patchJson("/api/v1/staff/campaigns/{$draftId}/status", ['status' => 'cancelled'])
            ->assertStatus(200);

        $wallet = $wallet->fresh();
        $this->assertSame(0, (int) $wallet->pending_balance_cents);
        $this->assertSame(9700, (int) $wallet->available_balance_cents); // 10000 - 300 fee
        $this->assertEquals('cancelled', Campaign::findOrFail($draftId)->status);
    }

    public function test_staff_cancel_releases_only_that_campaigns_escrow(): void
    {
        $admin = User::where('email', 'admin@ebizearn.com')->firstOrFail();
        $biz = $this->makeBusiness('iso-b6@example.com');

        $wallet = Wallet::where('user_id', $biz->id)->firstOrFail();
        app(\App\Services\Wallet\WalletLedgerService::class)
            ->credit($wallet, 20000, 'campaign_funding', 'Top-up');

        Sanctum::actingAs($biz);

        $launch = function (string $title): int {
            $draftId = $this->postJson('/api/v1/business/campaigns/wizard/draft', [
                'title' => $title,
                'category_id' => TaskCategory::firstOrFail()->id,
                'task_type_key' => 'follow',
                'reward_cents' => 20,
                'contributors' => 100,
            ])->assertStatus(201)->json('data.id');
            $this->postJson("/api/v1/business/campaigns/{$draftId}/launch", [])->assertStatus(200);

            return $draftId;
        };

        $campaignA = $launch('Campaign A');
        $campaignB = $launch('Campaign B');

        // $20 x 100 held per campaign; fees: $3 x 2 debited.
        $this->assertSame(4000, (int) $wallet->fresh()->pending_balance_cents);

        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/staff/campaigns/{$campaignA}/status", ['status' => 'cancelled'])
            ->assertStatus(200);

        // Only A's $20.00 escrow came back; B's $20.00 stays held.
        $wallet = $wallet->fresh();
        $this->assertSame(2000, (int) $wallet->pending_balance_cents);
        $this->assertSame(17400, (int) $wallet->available_balance_cents); // 20000 - 600 fees - 4000 held + 2000 released
        $this->assertEquals('cancelled', Campaign::findOrFail($campaignA)->status);
        // Priority 4 — B was never approved, so it stays in pending_review.
        $this->assertEquals('pending_review', Campaign::findOrFail($campaignB)->status);
    }

    public function test_staff_cannot_delete_campaign_once_money_moved(): void
    {
        $admin = User::where('email', 'admin@ebizearn.com')->firstOrFail();
        $biz = $this->makeBusiness('iso-b5@example.com');
        $campaign = $this->makeCampaign($biz->business);

        Sanctum::actingAs($admin);

        // Active campaigns cannot be deleted — only cancelled.
        $this->deleteJson("/api/v1/staff/campaigns/{$campaign->id}")->assertStatus(422);
        $this->assertNotNull(Campaign::find($campaign->id));

        // A clean draft CAN be deleted.
        $draft = $this->makeCampaign($biz->business);
        $draft->update(['status' => 'draft']);
        $this->deleteJson("/api/v1/staff/campaigns/{$draft->id}")->assertStatus(200);
        $this->assertNull(Campaign::find($draft->id));
    }
}
