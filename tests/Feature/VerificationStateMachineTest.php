<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Campaign;
use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskCategory;
use App\Models\TaskSubmission;
use App\Models\TaskType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Verification\VerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 6: verification state machine + fraud screens + referral reversal.
 * - submit: submitted -> checking -> under_review (moderator_review);
 *   the heuristic NEVER auto-approves
 * - hard screens: missing proof, wrong URL domain, duplicate proof hash
 *   (per campaign)
 * - decisions are forward-only with mandatory reason codes
 * - first task approval pays L1/L2/L3; reject-after-approve reverses all
 *   levels with compensating ledger entries; a later re-approval pays
 *   fresh credits (never double-pay, never double-reverse)
 */
class VerificationStateMachineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    // ------------------------------------------------------------------
    // Builders
    // ------------------------------------------------------------------

    protected function makeContributor(string $email): User
    {
        $user = User::create([
            'name' => 'Contributor ' . $email,
            'email' => $email,
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => 'contributor',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        Wallet::create(['user_id' => $user->id, 'currency' => 'USD', 'available_balance_cents' => 0]);

        return $user;
    }

    protected function makeBusiness(string $email): User
    {
        $user = User::create([
            'name' => 'Biz ' . $email,
            'email' => $email,
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => 'business',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        Business::create(['owner_id' => $user->id, 'company_name' => 'Co ' . $email, 'status' => 'active']);
        Wallet::create(['user_id' => $user->id, 'currency' => 'USD', 'available_balance_cents' => 0]);

        return $user;
    }

    protected function makeCampaign(Business $business): Campaign
    {
        return Campaign::create([
            'uuid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'category_id' => TaskCategory::firstOrFail()->id,
            'title' => 'Verify campaign ' . Str::random(6),
            'description' => 'verification test',
            'status' => 'active',
            'total_budget_cents' => 100000,
            'remaining_budget_cents' => 100000,
            'reserved_budget_cents' => 0,
            'reward_per_task_cents' => 15,
            'target_contributors_count' => 100,
        ]);
    }

    protected function makeTask(Campaign $campaign, array $overrides = []): Task
    {
        $typeKey = $overrides['task_type_key'] ?? 'follow';
        unset($overrides['task_type_key']);

        return Task::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'category_id' => $campaign->category_id,
            'task_type_id' => TaskType::where('key', $typeKey)->firstOrFail()->id,
            'title' => 'Follow task ' . Str::random(6),
            'description' => 'follow test',
            'platform' => 'instagram',
            'country_code' => 'AE',
            'instructions' => 'Follow and keep the follow.',
            'reward_cents' => 15,
            'slots_total' => 10,
            'slots_filled' => 0,
            'status' => 'available',
            'estimated_minutes' => 2,
            'difficulty' => 'easy',
        ], $overrides));
    }

    /**
     * Start + submit a task as the contributor. Returns the submission id.
     */
    protected function startAndSubmit(User $contributor, Task $task, array $proof = []): int
    {
        Sanctum::actingAs($contributor);

        $this->postJson("/api/v1/tasks/{$task->id}/start", [])->assertStatus(201);

        $response = $this->postJson("/api/v1/tasks/{$task->id}/submit", array_merge([
            'proof_screenshot' => base64_encode('screenshot-bytes-' . Str::random(24)),
            'text_answer' => 'done',
        ], $proof));

        $response->assertStatus(201)->assertJson(['success' => true]);

        return (int) $response->json('data.submission.id');
    }

    protected function approveAsAdmin(int $submissionId, string $reasonCode = 'verified'): void
    {
        $admin = User::where('email', 'admin@ebizearn.com')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/submissions/{$submissionId}/decision", [
            'decision' => 'approved',
            'reason_code' => $reasonCode,
            'notes' => 'Proof verified thoroughly against the campaign criteria.',
        ])->assertStatus(200);
    }

    protected function rejectAsAdmin(int $submissionId, string $reasonCode = 'fake_submission'): void
    {
        $admin = User::where('email', 'admin@ebizearn.com')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/submissions/{$submissionId}/decision", [
            'decision' => 'rejected',
            'reason_code' => $reasonCode,
            'notes' => 'Re-examined the proof; it does not meet the campaign criteria.',
        ])->assertStatus(200);
    }

    protected function registerContributor(string $name, string $email, ?string $refCode = null): User
    {
        $payload = ['name' => $name, 'email' => $email, 'password' => 'V3r1fy!Strong', 'role' => 'contributor', 'phone_country_code' => '+971', 'phone_number' => '501234567'];
        if ($refCode) {
            $payload['referral_code'] = $refCode;
        }
        $this->postJson('/api/v1/auth/register', $payload)->assertStatus(201);

        // Round 2: task submit is email-gated — verify in setup.
        $user = User::where('email', $email)->firstOrFail();
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    // ------------------------------------------------------------------
    // State machine + screens
    // ------------------------------------------------------------------

    public function test_submit_runs_screening_stages_and_lands_in_moderator_queue(): void
    {
        $contributor = $this->makeContributor('stage1@example.com');
        $task = $this->makeTask($this->makeCampaign($this->makeBusiness('stage-biz@example.com')->business));

        $submissionId = $this->startAndSubmit($contributor, $task);
        $submission = TaskSubmission::findOrFail($submissionId);

        $this->assertEquals('under_review', $submission->status);
        $this->assertEquals('moderator_review', $submission->verification_stage);
        $this->assertNotEmpty($submission->proof_hash);
        $this->assertNotEmpty($submission->device_fingerprint);
        $this->assertNotNull($submission->aiResult);

        // The screening step is audited.
        $this->assertTrue(
            AuditLog::where('action', 'submission.screened')->where('entity_id', $submission->id)->exists()
        );
    }

    public function test_heuristic_never_auto_approves(): void
    {
        $contributor = $this->makeContributor('stage2@example.com');
        $task = $this->makeTask($this->makeCampaign($this->makeBusiness('stage-biz2@example.com')->business));

        $submissionId = $this->startAndSubmit($contributor, $task);
        $submission = TaskSubmission::findOrFail($submissionId);

        // Even a clean screening never approves — only a reviewer decides.
        $this->assertNotEquals('approved', $submission->status);
        $this->assertEquals('under_review', $submission->status);
        $this->assertSame(0, (int) $contributor->wallet->fresh()->available_balance_cents);
    }

    public function test_missing_required_proof_is_rejected(): void
    {
        // survey requires text + url proof.
        $contributor = $this->makeContributor('stage3@example.com');
        $task = $this->makeTask(
            $this->makeCampaign($this->makeBusiness('stage-biz3@example.com')->business),
            ['task_type_key' => 'survey', 'reward_cents' => 100]
        );

        Sanctum::actingAs($contributor);
        $this->postJson("/api/v1/tasks/{$task->id}/start", [])->assertStatus(201);

        $response = $this->postJson("/api/v1/tasks/{$task->id}/submit", [
            'text_answer' => 'my answers',
            // proof_url missing — required by the survey type
        ]);

        $response->assertStatus(422);
        $this->assertEquals('missing_requirements', $response->json('reason_code'));
        $this->assertSame(0, TaskSubmission::where('task_id', $task->id)->count());
    }

    public function test_wrong_url_domain_is_rejected(): void
    {
        $contributor = $this->makeContributor('stage4@example.com');
        $task = $this->makeTask(
            $this->makeCampaign($this->makeBusiness('stage-biz4@example.com')->business),
            ['platform' => 'instagram']
        );

        Sanctum::actingAs($contributor);
        $this->postJson("/api/v1/tasks/{$task->id}/start", [])->assertStatus(201);

        $response = $this->postJson("/api/v1/tasks/{$task->id}/submit", [
            'proof_screenshot' => base64_encode('bytes-' . Str::random(16)),
            'proof_url' => 'https://facebook.com/some-proof',
        ]);

        $response->assertStatus(422);
        $this->assertEquals('wrong_url', $response->json('reason_code'));
    }

    public function test_duplicate_proof_hash_rejected_per_campaign_but_allowed_across_campaigns(): void
    {
        $biz = $this->makeBusiness('stage-biz5@example.com');
        $campaignA = $this->makeCampaign($biz->business);
        $campaignB = $this->makeCampaign($biz->business);
        $taskA = $this->makeTask($campaignA);
        $taskB = $this->makeTask($campaignB);

        $c1 = $this->makeContributor('dup1@example.com');
        $c2 = $this->makeContributor('dup2@example.com');

        $sameBytes = base64_encode('identical-screenshot-bytes');

        $this->startAndSubmit($c1, $taskA, ['proof_screenshot' => $sameBytes]);

        // Same campaign, same proof bytes -> 409 duplicate_proof.
        Sanctum::actingAs($c2);
        $this->postJson("/api/v1/tasks/{$taskA->id}/start", [])->assertStatus(201);
        $response = $this->postJson("/api/v1/tasks/{$taskA->id}/submit", [
            'proof_screenshot' => $sameBytes,
        ]);
        $response->assertStatus(409);
        $this->assertEquals('duplicate_proof', $response->json('reason_code'));

        // Different campaign, same bytes -> allowed (201).
        $this->startAndSubmit($c2, $taskB, ['proof_screenshot' => $sameBytes]);
        $this->assertSame(1, TaskSubmission::where('task_id', $taskB->id)->count());
    }

    public function test_decisions_require_valid_reason_codes(): void
    {
        $contributor = $this->makeContributor('stage6@example.com');
        $task = $this->makeTask($this->makeCampaign($this->makeBusiness('stage-biz6@example.com')->business));
        $submissionId = $this->startAndSubmit($contributor, $task);

        $admin = User::where('email', 'admin@ebizearn.com')->firstOrFail();
        Sanctum::actingAs($admin);

        // Missing code -> 422.
        $this->postJson("/api/v1/admin/submissions/{$submissionId}/decision", [
            'decision' => 'approved',
            'notes' => 'Looks fine to me, honestly.',
        ])->assertStatus(422);

        // Code from another decision's catalog -> 422 with the valid list.
        $response = $this->postJson("/api/v1/admin/submissions/{$submissionId}/decision", [
            'decision' => 'approved',
            'reason_code' => 'fake_submission',
            'notes' => 'Looks fine to me, honestly.',
        ]);
        $response->assertStatus(422);
        $this->assertEquals(['verified', 'meets_requirements'], $response->json('valid_reason_codes'));

        // Valid code -> 200, and the code is stored.
        $this->postJson("/api/v1/admin/submissions/{$submissionId}/decision", [
            'decision' => 'approved',
            'reason_code' => 'meets_requirements',
            'notes' => 'Proof verified thoroughly against the campaign criteria.',
        ])->assertStatus(200);
        $this->assertEquals('meets_requirements', TaskSubmission::findOrFail($submissionId)->review_reason_code);
    }

    public function test_status_transitions_are_forward_only(): void
    {
        $service = app(VerificationService::class);
        $admin = User::where('email', 'admin@ebizearn.com')->firstOrFail();
        $contributor = $this->makeContributor('stage7@example.com');
        $task = $this->makeTask($this->makeCampaign($this->makeBusiness('stage-biz7@example.com')->business));
        $submissionId = $this->startAndSubmit($contributor, $task);
        $submission = TaskSubmission::findOrFail($submissionId);

        // approved -> rejected is allowed (reject-after-approve path)...
        $service->recordDecision($submission->fresh(), $admin, 'approved', 'verified', 'Proof verified thoroughly.');
        $this->assertEquals('approved', $submission->fresh()->status);

        // ...but rejected -> approved is NOT (no resurrection).
        $rejected = $submission->fresh();
        $service->recordDecision($rejected, $admin, 'rejected', 'fake_submission', 'Re-examined; does not meet criteria.');
        $this->assertEquals('rejected', $rejected->fresh()->status);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid status transition');
        $service->recordDecision($rejected->fresh(), $admin, 'approved', 'verified', 'Trying to resurrect.');
    }

    public function test_action_required_returns_submission_to_contributor(): void
    {
        $contributor = $this->makeContributor('stage8@example.com');
        $task = $this->makeTask($this->makeCampaign($this->makeBusiness('stage-biz8@example.com')->business));
        $submissionId = $this->startAndSubmit($contributor, $task);

        $admin = User::where('email', 'admin@ebizearn.com')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/submissions/{$submissionId}/decision", [
            'decision' => 'action_required',
            'reason_code' => 'needs_better_proof',
            'notes' => 'Please upload a clearer screenshot showing the full profile.',
        ])->assertStatus(200);

        $submission = TaskSubmission::findOrFail($submissionId);
        $this->assertEquals('action_required', $submission->status);
        $this->assertEquals('needs_better_proof', $submission->review_reason_code);
    }

    // ------------------------------------------------------------------
    // Referral: first approval pays L1/L2/L3, reversal, re-approval
    // ------------------------------------------------------------------

    public function test_first_approval_pays_three_levels_and_records_exact_reward_ids(): void
    {
        $a = $this->registerContributor('RA', 'ra@example.com');
        $b = $this->registerContributor('RB', 'rb@example.com', $a->referral_code);
        $c = $this->registerContributor('RC', 'rc@example.com', $b->referral_code);
        $d = $this->registerContributor('RD', 'rd@example.com', $c->referral_code);

        $task = $this->makeTask($this->makeCampaign($this->makeBusiness('ref-biz@example.com')->business));
        $submissionId = $this->startAndSubmit($d, $task);

        $this->approveAsAdmin($submissionId);

        // L1 $1.00 -> C, L2 $0.50 -> B, L3 $0.25 -> A.
        $this->assertSame(100, $c->wallet->fresh()->available_balance_cents);
        $this->assertSame(50, $b->wallet->fresh()->available_balance_cents);
        $this->assertSame(25, $a->wallet->fresh()->available_balance_cents);

        $submission = TaskSubmission::findOrFail($submissionId);
        $rewardIds = $submission->triggered_referral_reward_ids_json ?? [];
        $this->assertCount(3, $rewardIds);

        $rewards = ReferralReward::whereIn('id', $rewardIds)->orderBy('level')->get();
        $this->assertEquals([1, 2, 3], $rewards->pluck('level')->all());
        $this->assertEquals(
            [$c->id, $b->id, $a->id],
            $rewards->pluck('referrer_id')->all()
        );
        $this->assertTrue($rewards->every(fn ($r) => $r->status === ReferralReward::STATUS_REWARDED));

        // A second approval for the same contributor pays nothing new.
        $task2 = $this->makeTask($task->campaign);
        $submissionId2 = $this->startAndSubmit($d, $task2);
        $this->approveAsAdmin($submissionId2);

        // Scoped to the chain's wallets: the seeder seeds an unrelated
        // demo referral_reward transaction in another wallet.
        $chainWalletIds = [$a->wallet->id, $b->wallet->id, $c->wallet->id];
        $this->assertCount(3, WalletTransaction::where('type', 'referral_reward')->whereIn('wallet_id', $chainWalletIds)->get());
        $this->assertSame(100, $c->wallet->fresh()->available_balance_cents);
        $this->assertSame([], TaskSubmission::findOrFail($submissionId2)->triggered_referral_reward_ids_json ?? []);
    }

    public function test_reject_after_approve_reverses_all_levels_with_compensating_entries(): void
    {
        $a = $this->registerContributor('XA', 'xa@example.com');
        $b = $this->registerContributor('XB', 'xb@example.com', $a->referral_code);
        $c = $this->registerContributor('XC', 'xc@example.com', $b->referral_code);
        $d = $this->registerContributor('XD', 'xd@example.com', $c->referral_code);

        $task = $this->makeTask($this->makeCampaign($this->makeBusiness('rev-biz@example.com')->business));
        $submissionId = $this->startAndSubmit($d, $task);
        $this->approveAsAdmin($submissionId);

        $this->assertSame(100, $c->wallet->fresh()->available_balance_cents);

        // Reject after approve -> all three levels reversed.
        $this->rejectAsAdmin($submissionId);

        $submission = TaskSubmission::findOrFail($submissionId);
        $this->assertEquals('rejected', $submission->status);

        // Balances restored.
        $this->assertSame(0, $c->wallet->fresh()->available_balance_cents);
        $this->assertSame(0, $b->wallet->fresh()->available_balance_cents);
        $this->assertSame(0, $a->wallet->fresh()->available_balance_cents);

        // Compensating entries exist, one per level; nothing deleted.
        $reversals = WalletTransaction::where('type', 'referral_reward_reversal')->get();
        $this->assertCount(3, $reversals);
        $this->assertSame([-100, -50, -25], $reversals->sortBy('amount_cents')->pluck('amount_cents')->values()->all());

        // Reward rows are 'reversed', referral rows reopened to 'pending'.
        $this->assertSame(0, ReferralReward::where('status', ReferralReward::STATUS_REWARDED)->count());
        $this->assertSame(3, ReferralReward::where('status', ReferralReward::STATUS_REVERSED)->count());
        $this->assertSame(3, Referral::where('referred_user_id', $d->id)->where('status', 'pending')->count());

        // The contributor's own task reward was clawed back too.
        $this->assertSame(0, $d->wallet->fresh()->available_balance_cents);

        // Retry the rejection decision: no double reversal.
        $admin = User::where('email', 'admin@ebizearn.com')->firstOrFail();
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/submissions/{$submissionId}/decision", [
            'decision' => 'rejected',
            'reason_code' => 'fake_submission',
            'notes' => 'Duplicate rejection attempt, should be a safe no-op.',
        ])->assertStatus(200);
        $this->assertCount(3, WalletTransaction::where('type', 'referral_reward_reversal')->get());
    }

    public function test_reapproval_after_reversal_pays_fresh_credits_never_resurrects(): void
    {
        $a = $this->registerContributor('YA', 'ya@example.com');
        $b = $this->registerContributor('YB', 'yb@example.com', $a->referral_code);
        $c = $this->registerContributor('YC', 'yc@example.com', $b->referral_code);
        $d = $this->registerContributor('YD', 'yd@example.com', $c->referral_code);

        $campaign = $this->makeCampaign($this->makeBusiness('reap-biz@example.com')->business);

        $submissionId = $this->startAndSubmit($d, $this->makeTask($campaign));
        $this->approveAsAdmin($submissionId);
        $chainWalletIds = [$a->wallet->id, $b->wallet->id, $c->wallet->id];
        $firstTxIds = WalletTransaction::where('type', 'referral_reward')->whereIn('wallet_id', $chainWalletIds)->pluck('id')->all();
        $this->assertCount(3, $firstTxIds);

        $this->rejectAsAdmin($submissionId);

        // Contributor earns a genuine re-approval on a new task.
        $submissionId2 = $this->startAndSubmit($d, $this->makeTask($campaign));
        $this->approveAsAdmin($submissionId2);

        // Three FRESH referral_reward credits — new ledger rows, and the
        // referrers are whole again.
        $allTxIds = WalletTransaction::where('type', 'referral_reward')->whereIn('wallet_id', $chainWalletIds)->pluck('id')->all();
        $this->assertCount(6, $allTxIds);
        $this->assertEmpty(array_intersect($firstTxIds, array_diff($allTxIds, $firstTxIds)));
        $this->assertSame(100, $c->wallet->fresh()->available_balance_cents);
        $this->assertSame(50, $b->wallet->fresh()->available_balance_cents);
        $this->assertSame(25, $a->wallet->fresh()->available_balance_cents);

        // Still exactly one reward row per level (unique key intact), now
        // rewarded again for the new cycle.
        $this->assertSame(3, ReferralReward::count());
        $this->assertSame(3, ReferralReward::where('status', ReferralReward::STATUS_REWARDED)->count());
    }
}
