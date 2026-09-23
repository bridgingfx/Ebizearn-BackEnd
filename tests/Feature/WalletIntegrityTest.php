<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\ReferralReward;
use App\Models\TaskCategory;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Wallet & rewards integrity: end-to-end proof that every financial action
 * creates a ledger record, balances always reconcile against the ledger,
 * and nothing can double-credit.
 *
 * Full real-API flow: business creates + funds a campaign -> staff approves
 * -> contributor (referred) starts + submits -> moderator approves ->
 * task_reward + referral_reward ledger rows -> balances + breakdown
 * reconcile -> repeated approval is a no-op -> withdrawal threshold enforced.
 */
class WalletIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /**
     * Authenticate subsequent requests as $user. (Bearer tokens cannot
     * switch users mid-test: the sanctum guard caches the first request's
     * user, so Sanctum::actingAs is the only reliable switch.)
     */
    protected function asUser(User $user): void
    {
        Sanctum::actingAs($user);
    }

    protected function registerContributor(string $name, string $email, ?string $refCode = null): User
    {
        $payload = ['name' => $name, 'email' => $email, 'password' => 'V3r1fy!Strong', 'role' => 'contributor', 'phone_country_code' => '+971', 'phone_number' => '501234567'];
        if ($refCode) {
            $payload['referral_code'] = $refCode;
        }
        $this->postJson('/api/v1/auth/register', $payload)->assertStatus(201);

        // Round 2: wallet/task write paths are email-gated, so the test
        // user verifies in setup (mirrors a real user clicking the link).
        $user = User::where('email', $email)->firstOrFail();
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    public function test_every_financial_action_writes_a_ledger_row_and_balances_reconcile(): void
    {
        // 1. Referrer A, referee B ------------------------------------------
        $a = $this->registerContributor('Int A', 'int-a@example.com');
        $b = $this->registerContributor('Int B', 'int-b@example.com', $a->referral_code);

        // 2. Funded business -------------------------------------------------
        $business = User::where('email', 'brand@acme.com')->firstOrFail();
        $bizWallet = Wallet::firstOrCreate(['user_id' => $business->id], ['currency' => 'USD']);
        $bizWallet->update(['available_balance_cents' => 10000, 'pending_balance_cents' => 0]);
        $this->asUser($business);

        // 3. Campaign create + fund (escrow hold + platform fee) -------------
        $category = TaskCategory::firstOrFail();
        $create = $this->postJson('/api/v1/business/campaigns', [
            'title' => 'Integrity Test Campaign',
            'description' => 'Every money move must leave a ledger row.',
            'category_id' => $category->id,
            'task_type_key' => 'survey',
            'reward_per_task_cents' => 100,
            'target_contributors_count' => 5,
            'instructions_markdown' => 'Do the thing.',
        ]);
        $create->assertStatus(201);
        $campaignId = $create->json('data.id');

        // Escrow hold (available -> pending) + non-refundable platform fee.
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $bizWallet->id, 'type' => 'campaign_funding', 'amount_cents' => -500,
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $bizWallet->id, 'type' => 'campaign_funding', 'amount_cents' => -75,
        ]);
        $bizWallet = $bizWallet->fresh();
        $this->assertSame(9425, $bizWallet->available_balance_cents);
        $this->assertSame(500, $bizWallet->pending_balance_cents);

        // 4. Staff approves the campaign ------------------------------------
        $moderator = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Int Mod', 'email' => 'int-mod@example.com',
            'password' => Hash::make('V3r1fy!Strong'), 'role' => 'moderator',
            'status' => 'active', 'email_verified_at' => now(),
        ]);
        $this->asUser($moderator);

        $this->patchJson("/api/v1/staff/campaigns/{$campaignId}/status", ['status' => 'active'])
            ->assertStatus(200);

        $taskId = Campaign::find($campaignId)->tasks()->firstOrFail()->id;
        $this->asUser($b);

        // 5. Contributor starts + submits ------------------------------------
        $this->postJson("/api/v1/tasks/{$taskId}/start")->assertStatus(201);
        $submit = $this->postJson("/api/v1/tasks/{$taskId}/submit", [
            'text_answer' => 'Completed thoughtfully. Confirmation code ABC123.',
            'proof_url' => 'https://example.com/survey/complete/abc123',
        ]);
        $submit->assertStatus(201);
        $submissionId = $submit->json('data.submission.id');
        $this->assertNotEmpty($submissionId);

        // Nothing credited before approval: no task_reward for B, no
        // referral_reward for A. (Scoped per wallet: the seeder itself
        // creates demo ledger rows.)
        $this->assertSame(0, WalletTransaction::where('wallet_id', $b->wallet->id)->where('type', 'task_reward')->count());
        $this->assertSame(0, WalletTransaction::where('wallet_id', $a->wallet->id)->where('type', 'referral_reward')->count());

        // 6. Moderator approves ----------------------------------------------
        $this->asUser($moderator);
        $this->postJson("/api/v1/moderator/submissions/{$submissionId}/decision", [
            'decision' => 'approved',
            'reason_code' => 'verified',
            'notes' => 'Proof verified manually.',
        ])->assertStatus(200);

        $bWallet = $b->wallet->fresh();
        $aWallet = $a->wallet->fresh();

        // Exactly one task_reward row (+100), linked to the submission.
        $taskReward = WalletTransaction::where('wallet_id', $bWallet->id)
            ->where('type', 'task_reward')->get();
        $this->assertCount(1, $taskReward);
        $this->assertSame(100, $taskReward->first()->amount_cents);
        $this->assertSame(TaskSubmission::class, $taskReward->first()->reference_type);
        $this->assertSame((int) $submissionId, $taskReward->first()->reference_id);

        // Exactly one referral_reward row (+100, L1) for the referrer.
        $refReward = WalletTransaction::where('wallet_id', $aWallet->id)
            ->where('type', 'referral_reward')->get();
        $this->assertCount(1, $refReward);
        $this->assertSame(100, $refReward->first()->amount_cents);
        $this->assertSame(ReferralReward::class, $refReward->first()->reference_type);

        // Business escrow settled for the paid reward.
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $bizWallet->id,
            'type' => 'campaign_funding',
            'amount_cents' => -100,
        ]);

        // 7. Balances reconcile against the ledger ---------------------------
        $this->assertSame(100, $bWallet->available_balance_cents);
        $this->assertSame(100, $bWallet->lifetime_earnings_cents);
        $this->assertSame(100, $aWallet->available_balance_cents);
        $this->assertSame(100, $aWallet->lifetime_earnings_cents);

        // Ledger-derived available == latest ledger row's stamped balance.
        $latestB = WalletTransaction::where('wallet_id', $bWallet->id)->latest('id')->firstOrFail();
        $this->assertSame($bWallet->available_balance_cents, (int) $latestB->balance_after_cents);

        // Breakdown endpoint computes the same figures from real records.
        $this->asUser($b);
        $breakdown = $this->getJson('/api/v1/wallet/breakdown')
            ->assertStatus(200)->json('data');
        $this->assertSame(100, $breakdown['available_cents']);
        $this->assertSame(100, $breakdown['lifetime_earnings_cents']);
        $this->assertSame(0, $breakdown['under_review_cents']);

        // 8. Repeating the approval is a no-op — never a second credit -------
        $this->asUser($moderator);
        $this->postJson("/api/v1/moderator/submissions/{$submissionId}/decision", [
            'decision' => 'approved',
            'reason_code' => 'verified',
            'notes' => 'Proof verified manually.',
        ])->assertStatus(200);

        $this->assertSame(1, WalletTransaction::where('wallet_id', $bWallet->id)->where('type', 'task_reward')->count());
        $this->assertSame(1, WalletTransaction::where('wallet_id', $aWallet->id)->where('type', 'referral_reward')->count());
        $this->assertSame(100, $b->wallet->fresh()->available_balance_cents);

        // 9. Withdrawal threshold is enforced from the active rule ------------
        $this->asUser($b);
        $this->postJson('/api/v1/wallet/withdraw', [
            'amount_cents' => 100, // below the seeded $50 minimum
            'payout_method' => 'bank_transfer',
            'payout_details' => ['account' => '123'],
        ])->assertStatus(422);

        $this->assertSame(100, $b->wallet->fresh()->available_balance_cents);
        $this->assertSame(0, \App\Models\WithdrawalRequest::where('user_id', $b->id)->count());
    }
}
