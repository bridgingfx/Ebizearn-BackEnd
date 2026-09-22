<?php

namespace App\Services\Verification;

use App\Models\AiVerificationResult;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Referral;
use App\Models\TaskAssignment;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\AI\AIProviderInterface;
use App\Services\AI\ManualAIProvider;
use App\Services\AI\MockAIProvider;
use App\Services\Fraud\FraudAnalysisService;
use App\Services\Wallet\WalletLedgerService;
use Exception;
use Illuminate\Support\Facades\DB;

class VerificationService
{
    /**
     * Forward-only status machine for reviewer decisions. Anything not listed
     * here is rejected, with two deliberate exceptions handled in
     * recordDecision(): repeating the same decision is an idempotent no-op,
     * and approved -> rejected reverses the ledger credit instead of
     * double-crediting on a later re-approval.
     */
    private const ALLOWED_TRANSITIONS = [
        'submitted' => ['approved', 'rejected', 'action_required'],
        'under_review' => ['approved', 'rejected', 'action_required'],
        'action_required' => ['approved', 'rejected'],
    ];

    /**
     * AI provider is resolved from config('verification.ai_provider') —
     * never hard-coded — so the pre-check can switch between the mock
     * placeholder and manual review without a code change. A concrete
     * provider may still be injected (e.g. in tests).
     */
    public function __construct(
        protected ?AIProviderInterface $aiProvider = null,
        protected ?FraudAnalysisService $fraudService = null,
        protected ?WalletLedgerService $walletService = null
    ) {
        $this->aiProvider ??= self::resolveAiProvider();
        $this->fraudService ??= new FraudAnalysisService();
        $this->walletService ??= new WalletLedgerService();
    }

    /**
     * Build the AI provider for the configured provider name.
     */
    protected static function resolveAiProvider(): AIProviderInterface
    {
        return match (config('verification.ai_provider', 'mock')) {
            'manual' => new ManualAIProvider(),
            // 'mock' (default) and any unknown value: the labelled placeholder.
            default => new MockAIProvider(),
        };
    }

    /**
     * True when the configured provider only produces simulated results.
     */
    public static function aiResultsAreSimulated(): bool
    {
        return config('verification.ai_provider', 'mock') === 'mock';
    }

    /**
     * Run AI pre-check and fraud analysis for a new submission.
     */
    public function processNewSubmission(TaskSubmission $submission): AiVerificationResult
    {
        // 1. Run AI analysis
        $aiData = $this->aiProvider->analyzeSubmission($submission);

        // 2. Run fraud analysis
        $fraudData = $this->fraudService->evaluateSubmission($submission);

        // Merge fraud risk into AI result
        $finalRisk = max($aiData['risk_score'], $fraudData['fraud_score']);

        return AiVerificationResult::updateOrCreate(
            ['submission_id' => $submission->id],
            [
                'confidence_score' => $aiData['confidence_score'],
                'risk_score' => $finalRisk,
                'duplicate_risk' => $aiData['duplicate_risk'],
                'proof_quality' => $aiData['proof_quality'],
                'content_match' => $aiData['content_match'],
                'policy_match' => $aiData['policy_match'],
                'suggested_decision' => $finalRisk >= 50 ? 'flag' : $aiData['suggested_decision'],
                'analysis_summary' => $aiData['analysis_summary'],
                'raw_payload_json' => array_merge($aiData['raw_payload'], ['fraud_analysis' => $fraudData]),
                // Honesty labelling: mock results are always simulated; the
                // manual provider produces no AI analysis at all.
                'ai_simulated' => self::aiResultsAreSimulated(),
                'ai_label' => self::aiResultsAreSimulated()
                    ? 'Simulated heuristic (pre-launch)'
                    : 'Manual review — no AI analysis',
            ]
        );
    }

    /**
     * Admin/Reviewer executes a decision with mandatory reasoning and atomic balance credit.
     */
    public function recordDecision(
        TaskSubmission $submission,
        User $reviewer,
        string $decision, // 'approved', 'rejected', 'action_required'
        string $notes
    ): TaskSubmission {
        if (!in_array($decision, ['approved', 'rejected', 'action_required'], true)) {
            throw new Exception('Invalid decision option.');
        }

        return DB::transaction(function () use ($submission, $reviewer, $decision, $notes) {
            $lockedSubmission = TaskSubmission::where('id', $submission->id)->lockForUpdate()->firstOrFail();
            $from = $lockedSubmission->status;

            // Idempotent: repeating the same decision is a no-op, never a second credit.
            if ($from === $decision) {
                return $lockedSubmission->fresh();
            }

            // Reject-after-approve: reverse the ledger credit instead of silently
            // skipping (which previously allowed approve -> reject -> approve to
            // double-credit).
            if ($from === 'approved' && $decision === 'rejected') {
                return $this->reverseApproval($lockedSubmission, $reviewer, $notes);
            }

            if (!isset(self::ALLOWED_TRANSITIONS[$from]) || !in_array($decision, self::ALLOWED_TRANSITIONS[$from], true)) {
                throw new Exception("Invalid status transition: {$from} -> {$decision}.");
            }

            $beforeState = $lockedSubmission->toArray();

            $lockedSubmission->update([
                'status' => $decision,
                'reviewer_id' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ]);

            // Update assignment
            if ($lockedSubmission->assignment_id) {
                $assignment = TaskAssignment::where('id', $lockedSubmission->assignment_id)->lockForUpdate()->first();
                if ($assignment) {
                    $assignmentStatus = $decision === 'approved' ? 'completed' : ($decision === 'rejected' ? 'cancelled' : 'in_progress');
                    $assignment->update([
                        'status' => $assignmentStatus,
                        'completed_at' => $decision === 'approved' ? now() : null,
                    ]);
                }
            }

            if ($decision === 'approved') {
                $this->applyApproval($lockedSubmission);
            } elseif ($decision === 'rejected') {
                // A rejected submission frees its slot reservation back to the pool.
                $this->releaseReservation($lockedSubmission);
            }

            $this->auditDecision($reviewer, $lockedSubmission, $decision, $beforeState);

            return $lockedSubmission->fresh();
        });
    }

    /**
     * Approve path: credit the contributor, settle the business escrow hold,
     * convert the slot reservation into spend, and check referral qualification.
     * The campaign budget row is locked first so concurrent approvals cannot
     * overspend the pool.
     */
    protected function applyApproval(TaskSubmission $submission): void
    {
        $task = $submission->task;
        $rewardCents = (int) $task->reward_cents;

        $campaign = $task->campaign_id
            ? Campaign::where('id', $task->campaign_id)->lockForUpdate()->first()
            : null;

        // Campaign-level funding guard: the declared pool (unreserved + reserved)
        // must cover this reward. Combined with the escrow hold taken at campaign
        // launch, approvals can never credit from nothing on funded campaigns.
        if ($campaign && ($campaign->remaining_budget_cents + $campaign->reserved_budget_cents) < $rewardCents) {
            throw new Exception('Campaign budget exhausted — fund the campaign before approving this submission.');
        }

        // 1. Credit Contributor Wallet
        $wallet = Wallet::firstOrCreate(
            ['user_id' => $submission->user_id],
            ['currency' => 'USD', 'available_balance_cents' => 0]
        );

        $this->walletService->credit(
            $wallet,
            $rewardCents,
            'task_reward',
            "Reward for completing: {$task->title}",
            TaskSubmission::class,
            $submission->id,
            ['task_id' => $task->id, 'campaign_id' => $task->campaign_id]
        );

        // 2. Settle the business escrow hold for this reward. Soft-settle: on
        // campaigns launched before the funding gate, the hold may not cover
        // the full reward — settle what is held and record the shortfall in
        // metadata for ops follow-up (see the fund endpoint).
        if ($campaign && $campaign->business) {
            $businessWallet = Wallet::firstOrCreate(
                ['user_id' => $campaign->business->owner_id],
                ['currency' => 'USD', 'available_balance_cents' => 0]
            );

            $lockedBusinessWallet = Wallet::where('id', $businessWallet->id)->lockForUpdate()->firstOrFail();
            $settleCents = min($lockedBusinessWallet->pending_balance_cents, $rewardCents);

            if ($settleCents > 0) {
                $this->walletService->settleEscrow(
                    $businessWallet,
                    $settleCents,
                    "Escrow settlement — reward paid for: {$task->title}",
                    TaskSubmission::class,
                    $submission->id,
                    [
                        'task_id' => $task->id,
                        'campaign_id' => $campaign->id,
                        'escrow_shortfall_cents' => $rewardCents - $settleCents,
                    ]
                );
            }
        }

        // 3. Convert the slot reservation into spend (never below zero) and count
        if ($campaign) {
            $campaign->decrement('reserved_budget_cents', min($campaign->reserved_budget_cents, $rewardCents));
            $campaign->increment('completed_contributors_count');
        }

        // 4. Update Contributor Profile Stats
        $profile = $submission->user->profile;
        if ($profile) {
            $profile->increment('completed_tasks_count');
        }

        // 5. Check Referral Qualification (row-locked against concurrent payouts)
        $referral = $this->checkReferralQualification($submission->user, $submission);
        if ($referral) {
            $submission->update(['triggered_referral_id' => $referral->id]);
        }
    }

    /**
     * Reject-after-approve path: unwind everything the approval did —
     * reverse the contributor's task_reward credit, restore the campaign pool,
     * roll back stats, and reverse a referral reward if this approval paid one.
     */
    protected function reverseApproval(TaskSubmission $submission, User $reviewer, string $notes): TaskSubmission
    {
        $beforeState = $submission->toArray();
        $task = $submission->task;
        $rewardCents = $task ? (int) $task->reward_cents : 0;

        if ($task) {
            // 1. Reverse the contributor's task_reward credit.
            $wallet = Wallet::firstOrCreate(
                ['user_id' => $submission->user_id],
                ['currency' => 'USD', 'available_balance_cents' => 0]
            );

            $original = WalletTransaction::where('wallet_id', $wallet->id)
                ->where('type', 'task_reward')
                ->where('reference_type', TaskSubmission::class)
                ->where('reference_id', $submission->id)
                ->latest('id')
                ->first();

            $campaign = $task->campaign_id
                ? Campaign::where('id', $task->campaign_id)->lockForUpdate()->first()
                : null;

            if ($original) {
                $reversal = $this->walletService->reverseCredit(
                    $wallet,
                    $original,
                    "Submission #{$submission->id} rejected after approval"
                );

                // Return the clawed-back funds to the campaign escrow pool so
                // the pending hold keeps covering the restored budget below.
                // (Only on a fresh reversal — reverseCredit is idempotent.)
                if ($reversal->wasRecentlyCreated && $campaign && $campaign->business) {
                    $reversedCents = (int) ($reversal->metadata_json['reversed_amount_cents'] ?? 0);

                    if ($reversedCents > 0) {
                        $businessWallet = Wallet::firstOrCreate(
                            ['user_id' => $campaign->business->owner_id],
                            ['currency' => 'USD', 'available_balance_cents' => 0]
                        );

                        $this->walletService->restoreEscrow(
                            $businessWallet,
                            $reversedCents,
                            "Escrow restored — reversal of submission #{$submission->id}",
                            TaskSubmission::class,
                            $submission->id,
                            ['campaign_id' => $campaign->id]
                        );
                    }
                }
            }

            // 2. Restore the campaign budget pool.
            if ($campaign) {
                $campaign->increment('remaining_budget_cents', $rewardCents);
                $campaign->decrement('completed_contributors_count', min($campaign->completed_contributors_count, 1));
            }

            // 3. Roll back contributor stats.
            $profile = $submission->user->profile;
            if ($profile) {
                $profile->decrement('completed_tasks_count', min($profile->completed_tasks_count, 1));
            }
        }

        // 4. Reverse the referral reward if THIS approval triggered it.
        if ($submission->triggered_referral_id) {
            $referral = Referral::where('id', $submission->triggered_referral_id)->lockForUpdate()->first();

            if ($referral && $referral->status === 'rewarded') {
                $referrerWallet = Wallet::firstOrCreate(
                    ['user_id' => $referral->referrer_id],
                    ['currency' => 'USD', 'available_balance_cents' => 0]
                );

                $referralCredit = WalletTransaction::where('wallet_id', $referrerWallet->id)
                    ->where('type', 'referral_reward')
                    ->where('reference_type', Referral::class)
                    ->where('reference_id', $referral->id)
                    ->latest('id')
                    ->first();

                if ($referralCredit) {
                    $this->walletService->reverseCredit(
                        $referrerWallet,
                        $referralCredit,
                        "Referral #{$referral->id} unqualified — submission #{$submission->id} rejected after approval"
                    );
                }

                $referral->update(['status' => 'pending', 'qualified_at' => null]);
            }
        }

        $submission->update([
            'status' => 'rejected',
            'reviewer_id' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => $notes,
            'triggered_referral_id' => null,
        ]);

        if ($submission->assignment_id) {
            TaskAssignment::where('id', $submission->assignment_id)
                ->update(['status' => 'cancelled', 'completed_at' => null]);
        }

        $this->auditDecision($reviewer, $submission, 'rejected', $beforeState, 'submission.rejected_after_approval_reversed');

        return $submission->fresh();
    }

    /**
     * Return a rejected submission's slot reservation to the campaign pool.
     */
    protected function releaseReservation(TaskSubmission $submission): void
    {
        $task = $submission->task;

        if (!$task || !$task->campaign_id) {
            return;
        }

        $campaign = Campaign::where('id', $task->campaign_id)->lockForUpdate()->first();

        if (!$campaign) {
            return;
        }

        $restore = min($campaign->reserved_budget_cents, (int) $task->reward_cents);

        if ($restore > 0) {
            $campaign->decrement('reserved_budget_cents', $restore);
            $campaign->increment('remaining_budget_cents', $restore);
        }
    }

    protected function auditDecision(
        User $reviewer,
        TaskSubmission $submission,
        string $decision,
        array $beforeState,
        ?string $actionOverride = null
    ): void {
        AuditLog::create([
            'actor_id' => $reviewer->id,
            'action' => $actionOverride ?? "submission.{$decision}",
            'entity_type' => TaskSubmission::class,
            'entity_id' => $submission->id,
            'before_state_json' => $beforeState,
            'after_state_json' => $submission->fresh()->toArray(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);
    }

    /**
     * Qualify referral reward upon legitimate task completion.
     * The pending referral row is locked so concurrent approvals of the same
     * contributor's submissions cannot double-pay the referrer.
     *
     * @return Referral|null the referral that was paid, if any
     */
    protected function checkReferralQualification(User $contributor, TaskSubmission $submission): ?Referral
    {
        $referral = Referral::where('referred_user_id', $contributor->id)
            ->where('status', 'pending')
            ->lockForUpdate()
            ->first();

        if (!$referral) {
            return null;
        }

        $referral->update([
            'status' => 'qualified',
            'qualified_at' => now(),
        ]);

        // Credit referrer wallet
        $referrerWallet = Wallet::firstOrCreate(
            ['user_id' => $referral->referrer_id],
            ['currency' => 'USD', 'available_balance_cents' => 0]
        );

        $this->walletService->credit(
            $referrerWallet,
            (int) $referral->reward_cents,
            'referral_reward',
            "Referral bonus for friend completing first verified task",
            Referral::class,
            $referral->id,
            ['triggered_by_submission_id' => $submission->id, 'referred_user_id' => $contributor->id]
        );

        $referral->update(['status' => 'rewarded']);

        return $referral;
    }
}
