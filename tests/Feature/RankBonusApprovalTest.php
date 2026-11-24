<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Campaign;
use App\Models\Profile;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskCategory;
use App\Models\TaskSubmission;
use App\Models\TaskType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Bug 1 regression (bug report 2026-11-20, CRITICAL): approving a
 * submission for an Explorer+ contributor must record the decision and
 * credit the rank bonus. Before the fix, `rank_bonus` was missing from
 * the wallet_transactions.type ENUM, so the approval threw (SQLSTATE
 * 01000 / error 1265 on MySQL, CHECK constraint on SQLite), the
 * transaction rolled back and nothing was applied.
 */
class RankBonusApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected User $moderator;
    protected User $contributor;
    protected Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->moderator = User::create([
            'name' => 'Mod One',
            'email' => 'mod1@example.com',
            'password' => Hash::make('V3r1fy!Strong'),
        ])->forceFill(['role' => 'moderator', 'status' => 'active', 'email_verified_at' => now()]);
        $this->moderator->save();

        $this->contributor = User::create([
            'name' => 'Contrib One',
            'email' => 'contrib1@example.com',
            'password' => Hash::make('V3r1fy!Strong'),
        ])->forceFill(['role' => 'contributor', 'status' => 'active', 'email_verified_at' => now()]);
        $this->contributor->save();
        Wallet::create(['user_id' => $this->contributor->id, 'currency' => 'USD']);
        // Explorer rank: +5% bonus (seeded tier) — contributor_level is a
        // system field, set explicitly, never via mass assignment.
        Profile::create(['user_id' => $this->contributor->id, 'country_code' => 'GE'])
            ->forceFill(['contributor_level' => 'explorer'])->save();

        $bizUser = User::create([
            'name' => 'Biz One',
            'email' => 'biz1@example.com',
            'password' => Hash::make('V3r1fy!Strong'),
        ])->forceFill(['role' => 'business', 'status' => 'active', 'email_verified_at' => now()]);
        $bizUser->save();
        $business = Business::create(['owner_id' => $bizUser->id, 'company_name' => 'Co One', 'status' => 'active']);
        Wallet::create(['user_id' => $bizUser->id, 'currency' => 'USD', 'available_balance_cents' => 100000]);

        $campaign = Campaign::create([
            'uuid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'category_id' => TaskCategory::firstOrFail()->id,
            'title' => 'Rank Bonus Campaign',
            'description' => 'desc',
            'status' => 'active',
            'total_budget_cents' => 10000,
            'remaining_budget_cents' => 10000,
            'reward_per_task_cents' => 100,
            'target_contributors_count' => 10,
        ]);

        $this->task = Task::create([
            'uuid' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'category_id' => $campaign->category_id,
            'task_type_id' => TaskType::where('key', 'follow')->firstOrFail()->id,
            'title' => 'Rank Bonus Task',
            'status' => 'available',
            'reward_cents' => 100,
            'slots_total' => 10,
            'slots_taken' => 0,
        ]);
    }

    public function test_approving_explorer_submission_credits_rank_bonus(): void
    {
        $assignment = TaskAssignment::create([
            'uuid' => (string) Str::uuid(),
            'task_id' => $this->task->id,
            'user_id' => $this->contributor->id,
            'status' => 'submitted',
        ]);
        $sub = TaskSubmission::create([
            'uuid' => (string) Str::uuid(),
            'assignment_id' => $assignment->id,
            'task_id' => $this->task->id,
            'user_id' => $this->contributor->id,
            'status' => 'under_review',
            'proof_hash' => hash('sha256', Str::random(16)),
        ]);

        $token = $this->moderator->createToken('test')->plainTextToken;
        $resp = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson("/api/v1/moderator/submissions/{$sub->id}/decision", [
                'decision' => 'approved',
                'reason_code' => 'verified',
                'notes' => 'Proof verified manually.',
            ]);
        $resp->assertStatus(200);

        $this->assertEquals('approved', $sub->fresh()->status);
        // 5% of the 100-cent reward.
        $this->assertEquals(5, $sub->fresh()->bonus_cents);
        $this->assertDatabaseHas('wallet_transactions', [
            'type' => 'rank_bonus',
            'reference_type' => TaskSubmission::class,
            'reference_id' => $sub->id,
            'amount_cents' => 5,
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'type' => 'task_reward',
            'reference_type' => TaskSubmission::class,
            'reference_id' => $sub->id,
            'amount_cents' => 100,
        ]);
        // 'follow' carries a 7-day retention hold: reward + bonus sit in
        // pending until the retention command releases them.
        $wallet = Wallet::where('user_id', $this->contributor->id)->firstOrFail();
        $this->assertEquals(105, $wallet->pending_balance_cents);
        $this->assertEquals(105, $wallet->lifetime_earnings_cents);
    }
}
