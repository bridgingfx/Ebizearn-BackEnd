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
use App\Services\Wallet\WalletBreakdownService;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Task-reward retention (Phase 4/12): approved rewards enter PENDING when
 * the task carries a retention period, and move to available only after
 * the retention matures (retention:release). Legacy tasks with no
 * retention keep the direct-to-available behaviour. Reversals unwind
 * outstanding holds without resurrecting them.
 */
class RetentionFlowTest extends TestCase
{
    use RefreshDatabase;

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
            'name' => 'Con ' . $email,
            'email' => $email,
            'password' => Hash::make('password123'),
            'role' => 'contributor',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        Wallet::create(['user_id' => $user->id, 'currency' => 'USD', 'available_balance_cents' => 0]);

        return $user;
    }

    protected function makeCampaign(Business $business): Campaign
    {
        return Campaign::create([
            'uuid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'category_id' => TaskCategory::firstOrFail()->id,
            'title' => 'Retention campaign ' . Str::random(6),
            'description' => 'Retention test campaign.',
            'status' => 'active',
            'total_budget_cents' => 100000,
            'reward_per_task_cents' => 20,
            'target_contributors_count' => 10,
            'remaining_budget_cents' => 100000,
            'reserved_budget_cents' => 0,
        ]);
    }

    protected function makeTask(Campaign $campaign, array $overrides = []): Task
    {
        $typeKey = $overrides['task_type_key'] ?? 'follow'; // follow: 7d retention
        unset($overrides['task_type_key'], $overrides['retention_days_null']);

        $attrs = array_merge([
            'uuid' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'category_id' => $campaign->category_id,
            'task_type_id' => TaskType::where('key', $typeKey)->firstOrFail()->id,
            'title' => 'Retention task ' . Str::random(6),
            'description' => 'x',
            'platform' => 'instagram',
            'reward_cents' => 20,
            'slots_total' => 10,
            'slots_filled' => 0,
            'status' => 'available',
        ], $overrides);

        return Task::create($attrs);
    }

    protected function startAndSubmit(User $contributor, Task $task): int
    {
        Sanctum::actingAs($contributor);

        $assignment = TaskAssignment::create([
            'uuid' => (string) Str::uuid(),
            'task_id' => $task->id,
            'user_id' => $contributor->id,
            'status' => 'in_progress',
        ]);

        $this->postJson("/api/v1/tasks/{$task->id}/submit", [
            'proof_url' => 'https://instagram.com/p/retention-proof-' . Str::random(6),
            'proof_screenshot' => 'data:image/png;base64,' . base64_encode('fake-screenshot-' . Str::random(16)),
            'text_answer' => 'done',
        ])->assertStatus(201);

        return TaskSubmission::where('task_id', $task->id)
            ->where('user_id', $contributor->id)
            ->firstOrFail()->id;
    }

    protected function approveAsAdmin(int $submissionId): void
    {
        $admin = User::where('email', 'admin@ebizearn.com')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/submissions/{$submissionId}/decision", [
            'decision' => 'approved',
            'reason_code' => 'verified',
            'notes' => 'Verified.',
        ])->assertStatus(200);
    }

    protected function rejectAsAdmin(int $submissionId): void
    {
        $admin = User::where('email', 'admin@ebizearn.com')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/submissions/{$submissionId}/decision", [
            'decision' => 'rejected',
            'reason_code' => 'fake_submission',
            'notes' => 'Re-examined; fabricated.',
        ])->assertStatus(200);
    }

    public function test_approval_with_retention_holds_reward_in_pending(): void
    {
        $this->seed();

        $biz = $this->makeBusiness('ret-biz@example.com');
        $contributor = $this->makeContributor('ret-con@example.com');
        $task = $this->makeTask($this->makeCampaign($biz->business)); // follow: 7d retention

        $submissionId = $this->startAndSubmit($contributor, $task);
        $this->approveAsAdmin($submissionId);

        $wallet = $contributor->wallet->fresh();

        // Reward is NOT spendable yet.
        $this->assertSame(0, (int) $wallet->available_balance_cents);
        $this->assertSame(20, (int) $wallet->pending_balance_cents);

        // The hold is a real ledger row with a release date.
        $hold = WalletTransaction::where('wallet_id', $wallet->id)
            ->where('type', 'retention_hold')
            ->firstOrFail();
        $this->assertSame(-20, (int) $hold->amount_cents);
        $this->assertNotEmpty($hold->metadata_json['release_at']);
        $this->assertSame(7, (int) $hold->metadata_json['retention_days']);

        // Breakdown agrees: pending shows the held reward.
        $breakdown = (new WalletBreakdownService())->breakdown($contributor);
        $this->assertSame(0, $breakdown['available_cents']);
        $this->assertSame(20, $breakdown['pending_cents']);
    }

    public function test_legacy_task_without_retention_credits_directly(): void
    {
        $this->seed();

        $biz = $this->makeBusiness('ret-biz2@example.com');
        $contributor = $this->makeContributor('ret-con2@example.com');
        $task = $this->makeTask($this->makeCampaign($biz->business), [
            'task_type_id' => null,
            'retention_days' => null,
        ]);

        $submissionId = $this->startAndSubmit($contributor, $task);
        $this->approveAsAdmin($submissionId);

        $wallet = $contributor->wallet->fresh();
        $this->assertSame(20, (int) $wallet->available_balance_cents);
        $this->assertSame(0, (int) $wallet->pending_balance_cents);
        $this->assertSame(0, WalletTransaction::where('wallet_id', $wallet->id)->where('type', 'retention_hold')->count());
    }

    public function test_retention_release_moves_matured_hold_to_available_and_is_idempotent(): void
    {
        $this->seed();

        $biz = $this->makeBusiness('ret-biz3@example.com');
        $contributor = $this->makeContributor('ret-con3@example.com');
        $task = $this->makeTask($this->makeCampaign($biz->business));

        $submissionId = $this->startAndSubmit($contributor, $task);
        $this->approveAsAdmin($submissionId);

        $wallet = $contributor->wallet->fresh();
        $hold = WalletTransaction::where('wallet_id', $wallet->id)->where('type', 'retention_hold')->firstOrFail();

        // Mature the hold by backdating its release date.
        $meta = $hold->metadata_json;
        $meta['release_at'] = now()->subDay()->toIso8601String();
        $hold->update(['metadata_json' => $meta]);

        $this->artisan('retention:release')->assertSuccessful();

        $wallet = $wallet->fresh();
        $this->assertSame(20, (int) $wallet->available_balance_cents);
        $this->assertSame(0, (int) $wallet->pending_balance_cents);

        $release = WalletTransaction::where('wallet_id', $wallet->id)
            ->where('type', 'retention_release')
            ->firstOrFail();
        $this->assertSame(20, (int) $release->amount_cents);

        // Second run releases nothing new.
        $this->artisan('retention:release')->assertSuccessful();
        $this->assertSame(1, WalletTransaction::where('wallet_id', $wallet->id)->where('type', 'retention_release')->count());
        $this->assertSame(20, (int) $wallet->fresh()->available_balance_cents);
    }

    public function test_retention_release_skips_unmatured_holds(): void
    {
        $this->seed();

        $biz = $this->makeBusiness('ret-biz4@example.com');
        $contributor = $this->makeContributor('ret-con4@example.com');
        $task = $this->makeTask($this->makeCampaign($biz->business));

        $submissionId = $this->startAndSubmit($contributor, $task);
        $this->approveAsAdmin($submissionId);

        $this->artisan('retention:release')->assertSuccessful();

        $wallet = $contributor->wallet->fresh();
        $this->assertSame(0, (int) $wallet->available_balance_cents);
        $this->assertSame(20, (int) $wallet->pending_balance_cents);
        $this->assertSame(0, WalletTransaction::where('wallet_id', $wallet->id)->where('type', 'retention_release')->count());
    }

    public function test_reject_after_approve_cancels_outstanding_retention_hold(): void
    {
        $this->seed();

        $biz = $this->makeBusiness('ret-biz5@example.com');
        $contributor = $this->makeContributor('ret-con5@example.com');
        $campaign = $this->makeCampaign($biz->business);
        $task = $this->makeTask($campaign);

        // Fund the business so the escrow-restore path is exercised.
        $businessWallet = Wallet::where('user_id', $biz->id)->firstOrFail();
        (new WalletLedgerService())->credit($businessWallet, 10000, 'campaign_funding', 'Top-up');

        $submissionId = $this->startAndSubmit($contributor, $task);
        $this->approveAsAdmin($submissionId);

        $wallet = $contributor->wallet->fresh();
        $this->assertSame(20, (int) $wallet->pending_balance_cents);

        $this->rejectAsAdmin($submissionId);

        $wallet = $wallet->fresh();

        // Pending is unwound; available never moved.
        $this->assertSame(0, (int) $wallet->available_balance_cents);
        $this->assertSame(0, (int) $wallet->pending_balance_cents);

        // A compensating cancel row exists; the reward was NOT reversed from
        // available (it never got there) and cannot be resurrected by a
        // later retention:release run.
        $cancel = WalletTransaction::where('wallet_id', $wallet->id)
            ->where('type', 'retention_hold_cancel')
            ->firstOrFail();
        $this->assertSame(-20, (int) $cancel->amount_cents);
        $this->assertSame(0, WalletTransaction::where('wallet_id', $wallet->id)->where('type', 'task_reward_reversal')->count());

        $this->artisan('retention:release')->assertSuccessful();
        $this->assertSame(0, (int) $wallet->fresh()->available_balance_cents);
        $this->assertSame(0, WalletTransaction::where('wallet_id', $wallet->id)->where('type', 'retention_release')->count());

        // Retrying the rejection is a no-op: no second cancel row.
        $this->rejectAsAdmin($submissionId);
        $this->assertSame(1, WalletTransaction::where('wallet_id', $wallet->id)->where('type', 'retention_hold_cancel')->count());
    }
}
