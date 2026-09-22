<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Campaign;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskCategory;
use App\Models\TaskSubmission;
use App\Models\TaskType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;
use App\Services\Wallet\WalletBreakdownService;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 7: every wallet-breakdown figure is derived from immutable records
 * (the append-only ledger, withdrawal requests, submission states) — never
 * from the mutable wallet row. Each test drives REAL money flows and then
 * asserts the breakdown matches hand-computed expectations.
 */
class WalletBreakdownTest extends TestCase
{
    use RefreshDatabase;

    protected WalletLedgerService $ledger;
    protected WalletBreakdownService $breakdown;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->ledger = app(WalletLedgerService::class);
        $this->breakdown = app(WalletBreakdownService::class);
    }

    protected function makeUser(string $email, string $role = 'contributor'): User
    {
        $user = User::create([
            'name' => 'U ' . $email,
            'email' => $email,
            'password' => Hash::make('password123'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        Wallet::create(['user_id' => $user->id, 'currency' => 'USD', 'available_balance_cents' => 0]);

        if ($role === 'business') {
            Business::create(['owner_id' => $user->id, 'company_name' => 'Co ' . $email, 'status' => 'active']);
        }

        return $user;
    }

    protected function walletOf(User $user): Wallet
    {
        return Wallet::where('user_id', $user->id)->firstOrFail();
    }

    public function test_fresh_wallet_breaks_down_to_all_zeros(): void
    {
        $user = $this->makeUser('fresh0@example.com');

        $this->assertEquals(
            [
                'available_cents' => 0,
                'pending_cents' => 0,
                'under_review_cents' => 0,
                'rejected_cents' => 0,
                'lifetime_earnings_cents' => 0,
                'total_withdrawn_cents' => 0,
            ],
            $this->breakdown->breakdown($user)
        );
    }

    public function test_credits_and_reversals_derive_available_and_lifetime(): void
    {
        $user = $this->makeUser('earn1@example.com');
        $wallet = $this->walletOf($user);

        $this->ledger->credit($wallet, 20000, 'task_reward', 'Reward A', TaskSubmission::class, 1);
        $this->ledger->credit($wallet, 5000, 'referral_reward', 'Referral L1', TaskSubmission::class, 2);

        $b = $this->breakdown->breakdown($user);
        $this->assertSame(25000, $b['available_cents']);
        $this->assertSame(25000, $b['lifetime_earnings_cents']);
        $this->assertSame(0, $b['pending_cents']);

        // Compensating reversal: available AND lifetime both come back down.
        $original = WalletTransaction::where('wallet_id', $wallet->id)
            ->where('type', 'task_reward')->firstOrFail();
        $this->ledger->reverseCredit($wallet->fresh(), $original, 'test reversal');

        $b = $this->breakdown->breakdown($user);
        $this->assertSame(5000, $b['available_cents']);
        $this->assertSame(5000, $b['lifetime_earnings_cents']);
    }

    public function test_withdrawal_lifecycle_moves_pending_then_withdrawn(): void
    {
        $user = $this->makeUser('wd1@example.com');
        $wallet = $this->walletOf($user);

        $this->ledger->credit($wallet, 20000, 'task_reward', 'Reward', TaskSubmission::class, 1);

        // Request: available -> pending.
        $request = $this->ledger->requestWithdrawal($user, 6000, 'bank_transfer', ['iban' => 'AE0001']);

        $b = $this->breakdown->breakdown($user);
        $this->assertSame(14000, $b['available_cents']);
        $this->assertSame(6000, $b['pending_cents']);
        $this->assertSame(0, $b['total_withdrawn_cents']);

        // Approve (log-only payout): pending -> withdrawn.
        $this->ledger->approveWithdrawal($request);

        $b = $this->breakdown->breakdown($user);
        $this->assertSame(14000, $b['available_cents']);
        $this->assertSame(0, $b['pending_cents']);
        $this->assertSame(6000, $b['total_withdrawn_cents']);

        // A second request that gets rejected returns funds to available and
        // never counts as withdrawn.
        $request2 = $this->ledger->requestWithdrawal($user, 5000, 'bank_transfer', ['iban' => 'AE0001']);
        $this->assertSame(5000, $this->breakdown->breakdown($user)['pending_cents']);
        $this->ledger->rejectWithdrawal($request2, 'test reject');

        $b = $this->breakdown->breakdown($user);
        $this->assertSame(14000, $b['available_cents']);
        $this->assertSame(0, $b['pending_cents']);
        $this->assertSame(6000, $b['total_withdrawn_cents']);
    }

    public function test_escrow_hold_settle_and_restore_derive_pending(): void
    {
        $biz = $this->makeUser('esc1@example.com', 'business');
        $wallet = $this->walletOf($biz);

        $this->ledger->credit($wallet, 50000, 'campaign_funding', 'Top-up', null, null);

        // Escrow hold: available -> pending.
        $this->ledger->hold($wallet, 20000, 'campaign_funding', 'Escrow hold', Campaign::class, 1);

        $b = $this->breakdown->breakdown($biz);
        $this->assertSame(30000, $b['available_cents']);
        $this->assertSame(20000, $b['pending_cents']);

        // Settle 5000 of escrow: pending leaves custody (available unchanged).
        $this->ledger->settleEscrow($wallet, 5000, 'Escrow settlement', Campaign::class, 1);

        $b = $this->breakdown->breakdown($biz);
        $this->assertSame(30000, $b['available_cents']);
        $this->assertSame(15000, $b['pending_cents']);

        // Restore 2000 back into escrow.
        $this->ledger->restoreEscrow($wallet, 2000, 'Escrow restore', Campaign::class, 1);

        $b = $this->breakdown->breakdown($biz);
        $this->assertSame(17000, $b['pending_cents']);

        // Release the rest: pending -> available.
        $this->ledger->releaseHold($wallet, 17000, 'campaign_funding', 'Release', Campaign::class, 1);

        $b = $this->breakdown->breakdown($biz);
        $this->assertSame(47000, $b['available_cents']);
        $this->assertSame(0, $b['pending_cents']);
    }

    public function test_submission_states_derive_under_review_and_rejected(): void
    {
        $user = $this->makeUser('sub1@example.com');
        $biz = $this->makeUser('sub-biz@example.com', 'business');

        $campaign = Campaign::create([
            'uuid' => (string) Str::uuid(),
            'business_id' => $biz->business->id,
            'category_id' => TaskCategory::firstOrFail()->id,
            'title' => 'Sub breakdown campaign',
            'description' => 'x',
            'status' => 'active',
            'total_budget_cents' => 100000,
            'remaining_budget_cents' => 100000,
            'reserved_budget_cents' => 0,
            'reward_per_task_cents' => 100,
            'target_contributors_count' => 100,
        ]);

        $followTypeId = TaskType::where('key', 'follow')->firstOrFail()->id;
        $makeTask = fn (int $reward) => Task::create([
            'uuid' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'category_id' => $campaign->category_id,
            'task_type_id' => $followTypeId,
            'title' => 'T ' . Str::random(6),
            'description' => 'x',
            'platform' => 'instagram',
            'reward_cents' => $reward,
            'slots_total' => 5,
            'slots_filled' => 0,
            'status' => 'available',
        ]);

        $t1 = $makeTask(100);
        $t2 = $makeTask(200);
        $t3 = $makeTask(300);

        TaskSubmission::create([
            'task_id' => $t1->id, 'user_id' => $user->id,
            'status' => 'under_review', 'verification_stage' => 'moderator_review',
        ]);
        TaskSubmission::create([
            'task_id' => $t2->id, 'user_id' => $user->id,
            'status' => 'checking', 'verification_stage' => 'checking',
        ]);
        TaskSubmission::create([
            'task_id' => $t3->id, 'user_id' => $user->id,
            'status' => 'rejected', 'verification_stage' => 'decided',
        ]);

        $b = $this->breakdown->breakdown($user);
        $this->assertSame(300, $b['under_review_cents']);
        $this->assertSame(300, $b['rejected_cents']);
        $this->assertSame(0, $b['available_cents']);
    }

    public function test_breakdown_endpoint_returns_derived_figures(): void
    {
        $user = $this->makeUser('api1@example.com');
        $wallet = $this->walletOf($user);
        $this->ledger->credit($wallet, 12000, 'task_reward', 'Reward', TaskSubmission::class, 9);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/wallet/breakdown');

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertSame(12000, $response->json('data.available_cents'));
        $this->assertSame(12000, $response->json('data.lifetime_earnings_cents'));
    }
}
