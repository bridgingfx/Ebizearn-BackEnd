<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\ReferralRule;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Referral\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Admin-controllable referral rules (flat + percent).
 *
 * - Sensible defaults are seeded: flat L1 $1.00 / L2 $0.50 / L3 $0.25,
 *   with 10% / 5% / 2% percent values ready per level.
 * - admin/superadmin can view and update rules; every change is audit-logged
 *   and applies only to future qualifications — never rewrites paid rewards.
 * - contributor / business / moderator tokens get 403; guests get 401.
 * - percent mode pays the configured share of the referee's first approved
 *   task reward through the real qualification path.
 */
class ReferralRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function makeUser(string $role, string $email): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($role) . ' User',
            'email' => $email,
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
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

        $this->postJson('/api/v1/auth/register', $payload)->assertStatus(201);

        // Round 2: referral rewards require a verified email, so the test
        // user verifies in setup (mirrors a real user clicking the link).
        $user = User::where('email', $email)->firstOrFail();
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    public function test_sensible_defaults_are_seeded(): void
    {
        $this->assertSame(3, ReferralRule::count());

        $l1 = ReferralRule::where('level', 1)->firstOrFail();
        $this->assertSame('flat', $l1->reward_mode);
        $this->assertSame(100, $l1->reward_cents);
        $this->assertSame(1000, $l1->percent_bps);

        $l2 = ReferralRule::where('level', 2)->firstOrFail();
        $this->assertSame(50, $l2->reward_cents);
        $this->assertSame(500, $l2->percent_bps);

        $l3 = ReferralRule::where('level', 3)->firstOrFail();
        $this->assertSame(25, $l3->reward_cents);
        $this->assertSame(200, $l3->percent_bps);

        // Seeder is idempotent: a re-seed must not duplicate rows.
        $this->seed();
        $this->assertSame(3, ReferralRule::count());
    }

    public function test_admin_can_view_rules(): void
    {
        $admin = $this->makeUser('admin', 'rr-admin@example.com');
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/referral-rules');

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertSame(3, $response->json('data.levels'));
        $this->assertSame(100, $response->json('data.rules.1.reward_cents'));
        $this->assertSame('flat', $response->json('data.rules.1.reward_mode'));
        $this->assertSame(10.0, (float) $response->json('data.rules.1.percent'));
    }

    public function test_admin_can_update_rules_and_changes_are_audited(): void
    {
        $admin = $this->makeUser('admin', 'rr-admin2@example.com');
        Sanctum::actingAs($admin);

        $response = $this->patchJson('/api/v1/admin/referral-rules', [
            'levels' => [
                ['level' => 1, 'reward_mode' => 'flat', 'reward_cents' => 150],
                ['level' => 2, 'reward_mode' => 'percent', 'percent_bps' => 750],
            ],
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $l1 = ReferralRule::where('level', 1)->firstOrFail();
        $this->assertSame(150, $l1->reward_cents);
        $this->assertSame('flat', $l1->reward_mode);

        $l2 = ReferralRule::where('level', 2)->firstOrFail();
        $this->assertSame('percent', $l2->reward_mode);
        $this->assertSame(750, $l2->percent_bps);

        // Untouched level keeps its defaults.
        $l3 = ReferralRule::where('level', 3)->firstOrFail();
        $this->assertSame(25, $l3->reward_cents);

        // Every level change is audit-logged with before/after.
        $logs = AuditLog::where('action', 'referral.rules_updated')->get();
        $this->assertSame(2, $logs->count());
        $this->assertSame($admin->id, $logs->first()->actor_id);
    }

    public function test_rule_update_validation_is_honest(): void
    {
        $admin = $this->makeUser('admin', 'rr-admin3@example.com');
        Sanctum::actingAs($admin);

        // Bad mode.
        $this->patchJson('/api/v1/admin/referral-rules', [
            'levels' => [['level' => 1, 'reward_mode' => 'lottery', 'reward_cents' => 100]],
        ])->assertStatus(422)->assertJsonStructure(['success', 'message', 'errors']);

        // Flat mode without an amount.
        $this->patchJson('/api/v1/admin/referral-rules', [
            'levels' => [['level' => 1, 'reward_mode' => 'flat']],
        ])->assertStatus(422)->assertJsonStructure(['success', 'message', 'errors']);

        // Percent mode without basis points.
        $this->patchJson('/api/v1/admin/referral-rules', [
            'levels' => [['level' => 1, 'reward_mode' => 'percent']],
        ])->assertStatus(422);

        // Level out of range.
        $this->patchJson('/api/v1/admin/referral-rules', [
            'levels' => [['level' => 9, 'reward_mode' => 'flat', 'reward_cents' => 100]],
        ])->assertStatus(422);

        // Percent above 100%.
        $this->patchJson('/api/v1/admin/referral-rules', [
            'levels' => [['level' => 1, 'reward_mode' => 'percent', 'percent_bps' => 10001]],
        ])->assertStatus(422);
    }

    public function test_non_admin_roles_are_forbidden(): void
    {
        foreach (['contributor', 'business', 'moderator'] as $role) {
            Sanctum::actingAs($this->makeUser($role, "rr-{$role}@example.com"));
            $this->getJson('/api/v1/admin/referral-rules')->assertStatus(403);
            $this->patchJson('/api/v1/admin/referral-rules', [
                'levels' => [['level' => 1, 'reward_mode' => 'flat', 'reward_cents' => 1]],
            ])->assertStatus(403);
        }

        // Superadmin has full access.
        Sanctum::actingAs($this->makeUser('superadmin', 'rr-root@example.com'));
        $this->getJson('/api/v1/admin/referral-rules')->assertStatus(200);
    }

    public function test_guests_are_unauthenticated(): void
    {
        // No token at all -> 401 (separate test: Sanctum::actingAs persists
        // within a test method, so the guest case cannot share one).
        $this->getJson('/api/v1/admin/referral-rules')->assertStatus(401);
        $this->patchJson('/api/v1/admin/referral-rules', [
            'levels' => [['level' => 1, 'reward_mode' => 'flat', 'reward_cents' => 1]],
        ])->assertStatus(401);
    }

    public function test_percent_mode_pays_share_of_first_task_reward(): void
    {
        ReferralRule::where('level', 1)->firstOrFail()
            ->update(['reward_mode' => 'percent', 'percent_bps' => 1000]); // 10%

        $a = $this->registerContributor('A', 'pct-a@example.com');
        $b = $this->registerContributor('B', 'pct-b@example.com', $a->referral_code);

        // Referee's first approved task paid $20.00 -> L1 gets 10% = $2.00.
        $rewards = app(ReferralService::class)->qualifyAndReward($b->fresh(), 'first_task_approved', 2000);

        $this->assertCount(1, $rewards);
        $this->assertSame(200, $rewards[0]->amount_cents);
        $this->assertSame(200, $a->wallet->fresh()->available_balance_cents);

        $this->assertDatabaseHas('wallet_transactions', [
            'type' => 'referral_reward',
            'reference_type' => ReferralReward::class,
            'reference_id' => $rewards[0]->id,
            'amount_cents' => 200,
        ]);
    }

    public function test_percent_mode_without_basis_falls_back_to_flat(): void
    {
        ReferralRule::where('level', 1)->firstOrFail()
            ->update(['reward_mode' => 'percent', 'percent_bps' => 1000]);

        $a = $this->registerContributor('A', 'pfb-a@example.com');
        $b = $this->registerContributor('B', 'pfb-b@example.com', $a->referral_code);

        $rewards = app(ReferralService::class)->qualifyAndReward($b->fresh(), 'first_task_approved');

        $this->assertCount(1, $rewards);
        $this->assertSame(100, $rewards[0]->amount_cents); // flat fallback, never silent zero
    }

    public function test_rule_change_does_not_rewrite_rewards_already_paid(): void
    {
        $a = $this->registerContributor('A', 'rw-a@example.com');
        $b = $this->registerContributor('B', 'rw-b@example.com', $a->referral_code);

        app(ReferralService::class)->qualifyAndReward($b->fresh(), 'first_task_approved');

        $reward = ReferralReward::where('referred_user_id', $b->id)->where('level', 1)->firstOrFail();
        $this->assertSame(100, $reward->amount_cents);

        // Admin raises L1 to $5.00 — the paid reward must stay at $1.00.
        ReferralRule::where('level', 1)->firstOrFail()->update(['reward_cents' => 500]);

        $again = app(ReferralService::class)->qualifyAndReward($b->fresh(), 'first_task_approved');
        $this->assertCount(0, $again);
        $this->assertSame(100, $reward->fresh()->amount_cents);
        $this->assertSame(100, $a->wallet->fresh()->available_balance_cents);

        // But a NEW qualification after the change pays the new amount.
        $c = $this->registerContributor('C', 'rw-c@example.com', $a->referral_code);
        $newRewards = app(ReferralService::class)->qualifyAndReward($c->fresh(), 'first_task_approved');
        $this->assertCount(1, $newRewards);
        $this->assertSame(500, $newRewards[0]->amount_cents);
    }

    public function test_contributor_referral_page_shows_rule_descriptions(): void
    {
        $a = $this->registerContributor('A', 'rd-a@example.com');
        Sanctum::actingAs($a);

        $response = $this->getJson('/api/v1/contributor/referrals');
        $response->assertStatus(200);

        $byLevel = $response->json('data.by_level');
        $this->assertSame('flat', $byLevel[1]['reward_mode']);
        $this->assertStringContainsString('$1.00', $byLevel[1]['reward_description']);

        ReferralRule::where('level', 1)->firstOrFail()
            ->update(['reward_mode' => 'percent', 'percent_bps' => 1000]);

        $response = $this->getJson('/api/v1/contributor/referrals');
        $byLevel = $response->json('data.by_level');
        $this->assertSame('percent', $byLevel[1]['reward_mode']);
        $this->assertStringContainsString('10%', $byLevel[1]['reward_description']);
    }
}
