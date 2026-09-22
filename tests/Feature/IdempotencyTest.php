<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\IdempotencyKey;
use App\Models\TaskCategory;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Double-submit / race safety for every money-moving endpoint.
 *
 * Withdrawals, campaign creation, campaign funding and campaign launch all
 * accept an Idempotency-Key header (or idempotency_key body field): a retry
 * with the same key + identical parameters returns the original result
 * instead of moving money twice; the same key with different parameters is
 * rejected.
 */
class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function contributorWithBalance(int $cents): User
    {
        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Idem Contributor',
            'email' => 'idem-contrib-' . Str::random(6) . '@example.com',
            'password' => bcrypt('password123'),
            'role' => 'contributor',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $wallet = Wallet::firstOrCreate(
            ['user_id' => $user->id],
            ['currency' => 'USD', 'available_balance_cents' => 0]
        );
        app(\App\Services\Wallet\WalletLedgerService::class)
            ->credit($wallet, $cents, 'task_reward', 'Test credit for idempotency');

        return $user;
    }

    protected function fundedBusiness(int $cents): User
    {
        $business = User::where('email', 'brand@acme.com')->firstOrFail();

        $wallet = Wallet::firstOrCreate(
            ['user_id' => $business->id],
            ['currency' => 'USD', 'available_balance_cents' => 0]
        );
        $wallet->update(['available_balance_cents' => $cents, 'pending_balance_cents' => 0]);

        return $business;
    }

    protected function campaignPayload(): array
    {
        $category = TaskCategory::firstOrFail();

        return [
            'title' => 'Idempotency Test Campaign',
            'description' => 'Double-submit must not double-charge.',
            'category_id' => $category->id,
            'task_type_key' => 'survey', // band 20–200¢
            'reward_per_task_cents' => 100,
            'target_contributors_count' => 5,
            'instructions_markdown' => 'Do the thing.',
        ];
    }

    public function test_withdrawal_double_submit_with_same_key_creates_one_request(): void
    {
        $user = $this->contributorWithBalance(10000); // $100 available
        Sanctum::actingAs($user);

        $payload = [
            'amount_cents' => 6000, // above the seeded $50 minimum
            'payout_method' => 'bank_transfer',
            'payout_details' => ['account' => '123'],
        ];
        $headers = ['Idempotency-Key' => 'idem-withdraw-1'];

        $first = $this->withHeaders($headers)->postJson('/api/v1/wallet/withdraw', $payload);
        $first->assertStatus(201);

        $second = $this->withHeaders($headers)->postJson('/api/v1/wallet/withdraw', $payload);
        $second->assertStatus(201);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));

        // Exactly one withdrawal and one ledger row — no double debit.
        $this->assertSame(1, WithdrawalRequest::where('user_id', $user->id)->count());
        $this->assertSame(
            1,
            WalletTransaction::where('wallet_id', $user->wallet->id)->where('type', 'withdrawal')->count()
        );
        $this->assertSame(4000, $user->wallet->fresh()->available_balance_cents);

        $this->assertDatabaseHas('idempotency_keys', [
            'idempotency_key' => 'idem-withdraw-1',
            'action' => 'wallet.withdraw',
        ]);
    }

    public function test_withdrawal_key_reused_with_different_params_is_rejected(): void
    {
        $user = $this->contributorWithBalance(20000);
        Sanctum::actingAs($user);

        $base = [
            'payout_method' => 'bank_transfer',
            'payout_details' => ['account' => '123'],
        ];

        $this->withHeaders(['Idempotency-Key' => 'idem-withdraw-2'])
            ->postJson('/api/v1/wallet/withdraw', $base + ['amount_cents' => 6000])
            ->assertStatus(201);

        // Same key, different amount -> honest rejection, no second debit.
        $response = $this->withHeaders(['Idempotency-Key' => 'idem-withdraw-2'])
            ->postJson('/api/v1/wallet/withdraw', $base + ['amount_cents' => 7000]);

        $response->assertStatus(400);
        $this->assertStringContainsString('different parameters', (string) $response->json('message'));
        $this->assertSame(1, WithdrawalRequest::where('user_id', $user->id)->count());
    }

    public function test_campaign_create_double_submit_creates_one_campaign(): void
    {
        $business = $this->fundedBusiness(10000);
        Sanctum::actingAs($business);

        $headers = ['Idempotency-Key' => 'idem-campaign-1'];

        $first = $this->withHeaders($headers)->postJson('/api/v1/business/campaigns', $this->campaignPayload());
        $first->assertStatus(201);

        $second = $this->withHeaders($headers)->postJson('/api/v1/business/campaigns', $this->campaignPayload());
        $second->assertStatus(200); // replay of the existing record
        $this->assertSame($first->json('data.id'), $second->json('data.id'));

        // One campaign, one escrow hold — the wallet was charged exactly once.
        $this->assertSame(1, Campaign::where('title', 'Idempotency Test Campaign')->count());
        $this->assertSame(
            1,
            WalletTransaction::where('wallet_id', $business->wallet->id)
                ->where('type', 'campaign_funding')
                ->where('amount_cents', -500)
                ->count()
        );

        $wallet = $business->wallet->fresh();
        $this->assertSame(10000 - 500 - 75, $wallet->available_balance_cents);
        $this->assertSame(500, $wallet->pending_balance_cents);
    }

    public function test_campaign_fund_double_submit_holds_escrow_once(): void
    {
        $business = $this->fundedBusiness(10000);
        Sanctum::actingAs($business);

        $campaign = Campaign::create([
            'uuid' => (string) Str::uuid(),
            'business_id' => $business->business->id,
            'category_id' => TaskCategory::firstOrFail()->id,
            'title' => 'Fund Idempotency Campaign',
            'description' => 'Top-up twice, hold once.',
            'instructions_markdown' => 'Do the thing.',
            'status' => 'draft',
            'total_budget_cents' => 0,
            'remaining_budget_cents' => 0,
            'reserved_budget_cents' => 0,
            'reward_per_task_cents' => 100,
            'platform_fee_cents' => 0,
            'target_contributors_count' => 5,
        ]);

        $headers = ['Idempotency-Key' => 'idem-fund-1'];
        $payload = ['amount_cents' => 2000];

        $first = $this->withHeaders($headers)->postJson("/api/v1/business/campaigns/{$campaign->id}/fund", $payload);
        $first->assertStatus(200);

        $second = $this->withHeaders($headers)->postJson("/api/v1/business/campaigns/{$campaign->id}/fund", $payload);
        $second->assertStatus(200);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));

        // The escrow hold was applied exactly once.
        $this->assertSame(2000, $campaign->fresh()->remaining_budget_cents);
        $this->assertSame(
            1,
            WalletTransaction::where('wallet_id', $business->wallet->id)
                ->where('type', 'campaign_funding')
                ->where('amount_cents', -2000)
                ->count()
        );
        $this->assertSame(8000, $business->wallet->fresh()->available_balance_cents);
    }

    public function test_campaign_launch_double_submit_launches_once(): void
    {
        $business = $this->fundedBusiness(10000);
        Sanctum::actingAs($business);

        $category = TaskCategory::firstOrFail();
        $draft = $this->postJson('/api/v1/business/campaigns/wizard/draft', [
            'title' => 'Launch Idempotency Campaign',
            'task_title' => 'Do the survey',
            'objective' => 'Test',
            'description' => 'Launch twice, fund once.',
            'category_id' => $category->id,
            'task_type_key' => 'survey',
            'platform' => 'web',
            'country_code' => 'AE',
            'instructions' => 'Complete the survey.',
            'proof_requirements' => ['screenshot'],
            'reward_cents' => 100,
            'contributors' => 5,
            'retention_days' => 0,
            'estimated_minutes' => 3,
            'difficulty' => 'easy',
            'countries' => ['AE'],
            'languages' => ['en'],
        ])->assertStatus(201)->json('data.id');

        $headers = ['Idempotency-Key' => 'idem-launch-1'];

        $first = $this->withHeaders($headers)->postJson("/api/v1/business/campaigns/{$draft}/launch", []);
        $first->assertStatus(200);

        $second = $this->withHeaders($headers)->postJson("/api/v1/business/campaigns/{$draft}/launch", []);
        $second->assertStatus(200);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));

        // One escrow hold for the 500¢ task budget (fee 75¢ debited once).
        $this->assertSame(
            1,
            WalletTransaction::where('wallet_id', $business->wallet->id)
                ->where('type', 'campaign_funding')
                ->where('amount_cents', -500)
                ->count()
        );
        $this->assertSame('pending_review', Campaign::find($draft)->fresh()->status);
    }

    public function test_payout_approve_double_submit_processes_once(): void
    {
        $user = $this->contributorWithBalance(10000);
        Sanctum::actingAs($user);

        $withdrawalId = $this->postJson('/api/v1/wallet/withdraw', [
            'amount_cents' => 6000,
            'payout_method' => 'bank_transfer',
            'payout_details' => ['account' => '123'],
        ])->assertStatus(201)->json('data.id');

        $admin = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Payout Admin',
            'email' => 'idem-payout-admin@example.com',
            'password' => bcrypt('password123'),
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($admin);

        $headers = ['Idempotency-Key' => 'idem-payout-1'];
        $payload = ['action' => 'approve'];

        $first = $this->withHeaders($headers)->postJson("/api/v1/admin/payouts/{$withdrawalId}/process", $payload);
        $first->assertStatus(200);

        $second = $this->withHeaders($headers)->postJson("/api/v1/admin/payouts/{$withdrawalId}/process", $payload);
        $second->assertStatus(200);

        // The pending balance was moved to withdrawn exactly once.
        $wallet = $user->wallet->fresh();
        $this->assertSame(4000, $wallet->available_balance_cents);
        $this->assertSame(0, $wallet->pending_balance_cents);
        $this->assertSame(6000, $wallet->total_withdrawn_cents);
        $this->assertSame('processing', WithdrawalRequest::find($withdrawalId)->status);

        $this->assertDatabaseHas('idempotency_keys', [
            'idempotency_key' => 'idem-payout-1',
            'action' => 'wallet.withdrawal.approve',
        ]);
    }
}
