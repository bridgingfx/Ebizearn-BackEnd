<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Campaign;
use App\Models\TaskCategory;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 9: campaign wizard (preview -> draft -> launch).
 * - preview is honest: reward x contributors + 15% platform fee, no money moves
 * - draft creates a draft campaign; still no money moves
 * - launch is atomic: escrow hold + fee debit + task pool, exactly once
 *   (double launch -> 409, never a second hold)
 * - unfunded launch -> 422; cross-business launch -> 403
 */
class CampaignWizardTest extends TestCase
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

    protected function fundBusiness(User $biz, int $cents): void
    {
        $wallet = Wallet::where('user_id', $biz->id)->firstOrFail();
        app(WalletLedgerService::class)->credit($wallet, $cents, 'campaign_funding', 'Test top-up');
    }

    protected function draftPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Wizard campaign ' . Str::random(6),
            'category_id' => TaskCategory::firstOrFail()->id,
            'task_type_key' => 'follow',
            'platform' => 'instagram',
            'instructions' => 'Follow and keep the follow for 7 days.',
            'reward_cents' => 20,
            'contributors' => 100,
        ], $overrides);
    }

    public function test_preview_is_honest_and_moves_no_money(): void
    {
        $biz = $this->makeBusiness('wiz1@example.com');
        $this->fundBusiness($biz, 10000);
        Sanctum::actingAs($biz);

        $campaignsBefore = Campaign::count();

        $response = $this->postJson('/api/v1/business/campaigns/wizard/preview', [
            'reward_cents' => 20,
            'contributors' => 100,
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $data = $response->json('data');
        $this->assertSame(2000, $data['tasks_budget_cents']);
        $this->assertSame(300, $data['platform_fee_cents']);
        $this->assertSame(2300, $data['total_due_cents']);
        $this->assertSame(15, $data['platform_fee_percent']);

        // Preview moves nothing.
        $this->assertSame($campaignsBefore, Campaign::count());
        $this->assertSame(10000, Wallet::where('user_id', $biz->id)->firstOrFail()->available_balance_cents);
    }

    public function test_preview_enforces_reward_bands(): void
    {
        $biz = $this->makeBusiness('wiz2@example.com');
        Sanctum::actingAs($biz);

        // follow band is $0.10–$0.20; $0.50 is outside it.
        $response = $this->postJson('/api/v1/business/campaigns/wizard/preview', [
            'reward_cents' => 50,
            'contributors' => 10,
            'task_type_key' => 'follow',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('0.10', $response->json('message'));
    }

    public function test_draft_creates_draft_campaign_and_moves_no_money(): void
    {
        $biz = $this->makeBusiness('wiz3@example.com');
        $this->fundBusiness($biz, 10000);
        Sanctum::actingAs($biz);

        $response = $this->postJson('/api/v1/business/campaigns/wizard/draft', $this->draftPayload());

        $response->assertStatus(201)->assertJson(['success' => true]);

        $campaign = Campaign::findOrFail($response->json('data.id'));
        $this->assertEquals('draft', $campaign->status);
        $this->assertSame(2300, (int) $campaign->total_budget_cents);
        $this->assertSame(2000, (int) $campaign->remaining_budget_cents);
        $this->assertEquals('follow', $campaign->proof_requirements_json['wizard']['task_type_key'] ?? null);

        // Draft moves no money.
        $wallet = Wallet::where('user_id', $biz->id)->firstOrFail();
        $this->assertSame(10000, (int) $wallet->available_balance_cents);
        $this->assertSame(0, (int) $wallet->pending_balance_cents);
    }

    public function test_launch_reserves_budget_once_and_creates_task_pool(): void
    {
        $biz = $this->makeBusiness('wiz4@example.com');
        $this->fundBusiness($biz, 10000);
        Sanctum::actingAs($biz);

        $draftId = $this->postJson('/api/v1/business/campaigns/wizard/draft', $this->draftPayload())
            ->assertStatus(201)->json('data.id');

        $response = $this->postJson("/api/v1/business/campaigns/{$draftId}/launch", []);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $campaign = Campaign::findOrFail($draftId);
        $this->assertEquals('active', $campaign->status);

        // Task pool: 100 slots at $0.20.
        $task = $campaign->tasks()->firstOrFail();
        $this->assertSame(100, (int) $task->slots_total);
        $this->assertSame(20, (int) $task->reward_cents);
        $this->assertEquals('follow', $task->taskType->key);

        // Budget: $20.00 escrow-held (pending) + $3.00 fee debited.
        $wallet = Wallet::where('user_id', $biz->id)->firstOrFail();
        $this->assertSame(7700, (int) $wallet->available_balance_cents);
        $this->assertSame(2000, (int) $wallet->pending_balance_cents);

        // Double launch -> 409, and the budget is NOT held twice.
        $this->postJson("/api/v1/business/campaigns/{$draftId}/launch", [])
            ->assertStatus(409);

        $wallet = Wallet::where('user_id', $biz->id)->firstOrFail();
        $this->assertSame(7700, (int) $wallet->available_balance_cents);
        $this->assertSame(2000, (int) $wallet->pending_balance_cents);
        $this->assertSame(1, $campaign->tasks()->count());
    }

    public function test_launch_without_funds_is_rejected(): void
    {
        $biz = $this->makeBusiness('wiz5@example.com');
        Sanctum::actingAs($biz);

        $draftId = $this->postJson('/api/v1/business/campaigns/wizard/draft', $this->draftPayload())
            ->assertStatus(201)->json('data.id');

        $this->postJson("/api/v1/business/campaigns/{$draftId}/launch", [])
            ->assertStatus(422);

        $this->assertEquals('draft', Campaign::findOrFail($draftId)->status);
    }

    public function test_cross_business_launch_is_forbidden(): void
    {
        $bizA = $this->makeBusiness('wiz6a@example.com');
        $bizB = $this->makeBusiness('wiz6b@example.com');
        $this->fundBusiness($bizA, 10000);
        $this->fundBusiness($bizB, 10000);

        Sanctum::actingAs($bizA);
        $draftId = $this->postJson('/api/v1/business/campaigns/wizard/draft', $this->draftPayload())
            ->assertStatus(201)->json('data.id');

        // Business B cannot launch A's draft.
        Sanctum::actingAs($bizB);
        $this->postJson("/api/v1/business/campaigns/{$draftId}/launch", [])
            ->assertStatus(403);

        $this->assertEquals('draft', Campaign::findOrFail($draftId)->status);
        $this->assertSame(10000, (int) Wallet::where('user_id', $bizB->id)->firstOrFail()->available_balance_cents);
    }

    public function test_draft_enforces_reward_bands(): void
    {
        $biz = $this->makeBusiness('wiz7@example.com');
        Sanctum::actingAs($biz);
        $countBefore = Campaign::count();

        $response = $this->postJson('/api/v1/business/campaigns/wizard/draft', $this->draftPayload([
            'task_type_key' => 'follow',
            'reward_cents' => 500, // outside the $0.10–$0.20 band
        ]));

        $response->assertStatus(422);
        // The seeder seeds demo campaigns, so assert none were ADDED.
        $this->assertSame($countBefore, Campaign::count());
    }
}
