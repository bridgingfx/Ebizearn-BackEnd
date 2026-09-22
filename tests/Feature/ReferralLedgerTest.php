<?php

namespace Tests\Feature;

use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Referral\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 8: three-level affiliate ledger.
 * - chain resolved at registration via ?ref= code (one Referral row per level)
 * - credit only after qualification rules (email verified + first-task
 *   approval trigger)
 * - ledger-backed referral_reward transactions, idempotent: a level can
 *   never double-pay, even on retry
 * - levels and rates configurable (revertible to 1 level)
 */
class ReferralLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function registerContributor(string $name, string $email, ?string $refCode = null): User
    {
        $payload = [
            'name' => $name,
            'email' => $email,
            'password' => 'V3r1fy!Strong',
            'role' => 'contributor',
        ];

        if ($refCode) {
            $payload['referral_code'] = $refCode;
        }

        $response = $this->postJson('/api/v1/auth/register', $payload);
        $response->assertStatus(201);

        // Round 2: referral rewards require a verified email, so the test
        // user verifies in setup (mirrors a real user clicking the link).
        $user = User::where('email', $email)->firstOrFail();
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    public function test_registration_builds_three_level_chain(): void
    {
        $a = $this->registerContributor('A', 'a@example.com');
        $b = $this->registerContributor('B', 'b@example.com', $a->referral_code);
        $c = $this->registerContributor('C', 'c@example.com', $b->referral_code);
        $d = $this->registerContributor('D', 'd@example.com', $c->referral_code);

        // B: single L1 row to A
        $this->assertEquals(
            [['referrer_id' => $a->id, 'level' => 1]],
            Referral::where('referred_user_id', $b->id)->orderBy('level')
                ->get()->map(fn ($r) => ['referrer_id' => $r->referrer_id, 'level' => $r->level])->all()
        );

        // C: L1 -> B, L2 -> A
        $this->assertEquals(
            [['referrer_id' => $b->id, 'level' => 1], ['referrer_id' => $a->id, 'level' => 2]],
            Referral::where('referred_user_id', $c->id)->orderBy('level')
                ->get()->map(fn ($r) => ['referrer_id' => $r->referrer_id, 'level' => $r->level])->all()
        );

        // D: L1 -> C, L2 -> B, L3 -> A
        $this->assertEquals(
            [
                ['referrer_id' => $c->id, 'level' => 1],
                ['referrer_id' => $b->id, 'level' => 2],
                ['referrer_id' => $a->id, 'level' => 3],
            ],
            Referral::where('referred_user_id', $d->id)->orderBy('level')
                ->get()->map(fn ($r) => ['referrer_id' => $r->referrer_id, 'level' => $r->level])->all()
        );

        // Per-level default rewards: L1 $1.00, L2 $0.50, L3 $0.25
        $this->assertEquals(
            [100, 50, 25],
            Referral::where('referred_user_id', $d->id)->orderBy('level')->pluck('reward_cents')->all()
        );

        // All pending — nothing credited at registration.
        $this->assertSame(0, WalletTransaction::where('type', 'referral_reward')->where('reference_type', ReferralReward::class)->count());
    }

    public function test_qualification_pays_each_level_exactly_once(): void
    {
        $service = app(ReferralService::class);

        $a = $this->registerContributor('A', 'a@example.com');
        $b = $this->registerContributor('B', 'b@example.com', $a->referral_code);
        $c = $this->registerContributor('C', 'c@example.com', $b->referral_code);
        $d = $this->registerContributor('D', 'd@example.com', $c->referral_code);

        $rewards = $service->qualifyAndReward($d->fresh(), 'first_task_approved');

        $this->assertCount(3, $rewards);
        $this->assertEquals([100, 50, 25], array_map(fn ($r) => $r->amount_cents, $rewards));

        // Wallet balances: C +100, B +50, A +25
        $this->assertSame(100, $c->wallet->fresh()->available_balance_cents);
        $this->assertSame(50, $b->wallet->fresh()->available_balance_cents);
        $this->assertSame(25, $a->wallet->fresh()->available_balance_cents);

        // Ledger entries are referral_reward, one per level, linked to rewards
        $txs = WalletTransaction::where('type', 'referral_reward')->where('reference_type', ReferralReward::class)->get();
        $this->assertCount(3, $txs);
        foreach ($rewards as $reward) {
            $this->assertNotNull($reward->wallet_transaction_id);
            $tx = $txs->firstWhere('id', $reward->wallet_transaction_id);
            $this->assertNotNull($tx);
            $this->assertSame(ReferralReward::class, $tx->reference_type);
            $this->assertSame($reward->id, $tx->reference_id);
        }

        // Referral rows marked rewarded
        $this->assertSame(0, Referral::where('referred_user_id', $d->id)->where('status', 'pending')->count());

        // Audit trail for every payout
        $this->assertSame(3, \App\Models\AuditLog::where('action', 'referral.rewarded')->count());

        // RETRY: second qualification pays nothing new
        $again = $service->qualifyAndReward($d->fresh(), 'first_task_approved');
        $this->assertCount(0, $again);
        $this->assertCount(3, WalletTransaction::where('type', 'referral_reward')->where('reference_type', ReferralReward::class)->get());
        $this->assertSame(100, $c->wallet->fresh()->available_balance_cents);
        $this->assertSame(50, $b->wallet->fresh()->available_balance_cents);
        $this->assertSame(25, $a->wallet->fresh()->available_balance_cents);
    }

    public function test_unverified_referee_is_not_rewarded(): void
    {
        $service = app(ReferralService::class);

        $a = $this->registerContributor('A', 'a@example.com');
        $b = $this->registerContributor('B', 'b@example.com', $a->referral_code);
        $b->forceFill(['email_verified_at' => null])->save();

        $rewards = $service->qualifyAndReward($b->fresh(), 'first_task_approved');

        $this->assertCount(0, $rewards);
        $this->assertSame(0, WalletTransaction::where('type', 'referral_reward')->where('reference_type', ReferralReward::class)->count());
        $this->assertSame(1, Referral::where('referred_user_id', $b->id)->where('status', 'pending')->count());
    }

    public function test_single_level_config_reverts_to_direct_only(): void
    {
        config(['referrals.levels' => 1]);

        $a = $this->registerContributor('A', 'a@example.com');
        $b = $this->registerContributor('B', 'b@example.com', $a->referral_code);
        $c = $this->registerContributor('C', 'c@example.com', $b->referral_code);

        // C resolves only its direct referrer B
        $this->assertEquals(
            [['referrer_id' => $b->id, 'level' => 1]],
            Referral::where('referred_user_id', $c->id)->orderBy('level')
                ->get()->map(fn ($r) => ['referrer_id' => $r->referrer_id, 'level' => $r->level])->all()
        );

        $rewards = app(ReferralService::class)->qualifyAndReward($c->fresh(), 'first_task_approved');
        $this->assertCount(1, $rewards);
        $this->assertSame(100, $b->wallet->fresh()->available_balance_cents);
        $this->assertSame(0, $a->wallet->fresh()->available_balance_cents);
    }

    public function test_self_referral_code_is_ignored(): void
    {
        $a = $this->registerContributor('A', 'a@example.com');

        // Register B with A's code, then try to force A's referrer to itself
        // via the service — buildChain must never create a self row.
        $a->forceFill(['referrer_id' => $a->id])->save();
        $rows = app(ReferralService::class)->buildChain($a->fresh());

        $this->assertCount(0, $rows);
    }

    public function test_referral_api_returns_real_data(): void
    {
        $a = $this->registerContributor('A', 'a@example.com');
        $b = $this->registerContributor('B', 'b@example.com', $a->referral_code);
        $c = $this->registerContributor('C', 'c@example.com', $b->referral_code);

        app(ReferralService::class)->qualifyAndReward($c->fresh(), 'first_task_approved');

        Sanctum::actingAs($a);

        $index = $this->getJson('/api/v1/contributor/referrals');
        $index->assertStatus(200)
            ->assertJsonPath('data.referral_code', $a->referral_code)
            ->assertJsonPath('data.levels', 3)
            ->assertJsonPath('data.total_earned_cents', 50); // L2 for C

        $tree = $this->getJson('/api/v1/contributor/referrals/tree');
        $tree->assertStatus(200);
        $downline = $tree->json('data.downline');
        $this->assertCount(1, $downline); // B
        $this->assertSame('B', $downline[0]['name']);
        $this->assertCount(1, $downline[0]['downline']); // C under B
        $this->assertSame('C', $downline[0]['downline'][0]['name']);

        $earnings = $this->getJson('/api/v1/contributor/referrals/earnings');
        $earnings->assertStatus(200);
        $this->assertSame(1, $earnings->json('meta.total'));
        $this->assertSame(50, $earnings->json('meta.total_earned_cents'));
    }

    public function test_referral_endpoints_require_contributor_role(): void
    {
        $business = User::factory()->create(['role' => 'business']);
        Sanctum::actingAs($business);

        $this->getJson('/api/v1/contributor/referrals')->assertStatus(403);
        $this->getJson('/api/v1/contributor/referrals/tree')->assertStatus(403);
    }
}
