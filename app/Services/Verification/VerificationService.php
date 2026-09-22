<?php

namespace App\Services\Verification;

use App\Models\AiVerificationResult;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\TaskAssignment;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\AI\AIProviderInterface;
use App\Services\AI\ManualAIProvider;
use App\Services\AI\MockAIProvider;
use App\Services\Audit\AuditLogger;
use App\Services\Fraud\FraudAnalysisService;
use App\Services\Referral\ReferralService;
use App\Services\Wallet\WalletLedgerService;
use Exception;
use Illuminate\Support\Facades\DB;

class VerificationService
{
    /**
     * Phase 6: forward-only verification state machine.
     *
     * submitted -> checking (system/heuristic + fraud screens) ->
     * under_review (moderator review — REQUIRED; the heuristic NEVER
     * auto-approves) -> approved | rejected | action_required.
     *
     * approved -> rejected is the deliberate reversal path (compensating
     * ledger entries, never deletes). Anything else is rejected.
     */
    private const ALLOWED_TRANSITIONS = [
        'submitted' => ['checking', 'rejected'],
        'checking' => ['under_review', 'rejected'],
        'under_review' => ['approved', 'rejected', 'action_required'],
        'action_required' => ['approved', 'rejected'],
    ];

    /**
     * Mandatory reason codes for every reviewer decision. The API requires
     * one; it is stored on the submission and in the audit log.
     */
    public const REASON_CODES = [
        'approved' => ['verified', 'meets_requirements'],
        'rejected' => [
            'duplicate_proof',
            'fake_submission',
            'wrong_url',
            'missing_requirements',
            'multiple_accounts',
            'policy_violation',
            'other',
        ],
        'action_required' => ['needs_better_proof', 'needs_clarification'],
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
     * Run the system screening stage for a fresh submission:
     * submitted -> checking (AI pre-check + fraud screens) -> under_review.
     *
     * The heuristic NEVER auto-approves — every submission lands in the
     * moderator queue; the screens only attach flags/scores. Idempotent:
     * re-running on an already-screened submission only refreshes the AI
     * result row.
     */
    public function screenSubmission(TaskSubmission $submission): AiVerificationResult
    {
        return DB::transaction(function () use ($submission) {
            $locked = TaskSubmission::where('id', $submission->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'submitted') {
                $locked->update(['status' => 'checking', 'verification_stage' => 'checking']);
            }

            $result = $this->processNewSubmission($locked);

            if (in_array($locked->status, ['submitted', 'checking'], true)) {
                $locked->update(['status' => 'under_review', 'verification_stage' => 'moderator_review']);
            }

            AuditLog::create([
                'actor_id' => null,
                'action' => 'submission.screened',
                'entity_type' => TaskSubmission::class,
                'entity_id' => $locked->id,
                'before_state_json' => ['status' => 'submitted'],
                'after_state_json' => [
                    'status' => $locked->fresh()->status,
                    'suggested_decision' => $result->suggested_decision,
                    'risk_score' => $result->risk_score,
                    'ai_simulated' => $result->ai_simulated,
                ],
                'created_at' => now(),
            ]);

            return $result;
        });
    }

    /**
     * Admin/Reviewer executes a decision with a mandatory reason code and
     * atomic balance credit.
     */
    public function recordDecision(
        TaskSubmission $submission,
        User $reviewer,
        string $decision, // 'approved', 'rejected', 'action_required'
        string $reasonCode,
        string $notes
    ): TaskSubmission {
        if (!in_array($decision, ['approved', 'rejected', 'action_required'], true)) {
            throw new Exception('Invalid decision option.');
        }

        $validCodes = self::REASON_CODES[$decision] ?? [];
        if (!in_array($reasonCode, $validCodes, true)) {
            throw new Exception(
                "Invalid reason code '{$reasonCode}' for decision '{$decision}'. " .
                'Valid codes: ' . implode(', ', $validCodes) . '.'
            );
        }

        return DB::transaction(function () use ($submission, $reviewer, $decision, $reasonCode, $notes) {
            $lockedSubmission = TaskSubmission::where('id', $submission->id)->lockForUpdate()->firstOrFail();
            $from = $lockedSubmission->status;

            // Idempotent: repeating the same decision is a no-op, never a second credit.
            if ($from === $decision) {
                return $lockedSubmission->fresh();
            }

            // Legacy/unscreened rows (e.g. created before the checking stage
            // existed): run the screens first, still forward-only.
            if ($from === 'submitted') {
                $lockedSubmission->update(['status' => 'checking', 'verification_stage' => 'checking']);
                $this->processNewSubmission($lockedSubmission);
                $lockedSubmission->update(['status' => 'under_review', 'verification_stage' => 'moderator_review']);
                $from = 'under_review';
            }

            // Reject-after-approve: reverse the ledger credit instead of silently
            // skipping (which previously allowed approve -> reject -> approve to
            // double-credit).
            if ($from === 'approved' && $decision === 'rejected') {
                return $this->reverseApproval($lockedSubmission, $reviewer, $reasonCode, $notes);
            }

            if (!isset(self::ALLOWED_TRANSITIONS[$from]) || !in_array($decision, self::ALLOWED_TRANSITIONS[$from], true)) {
                throw new Exception("Invalid status transition: {$from} -> {$decision}.");
            }

            $beforeState = $lockedSubmission->toArray();

            $lockedSubmission->update([
                'status' => $decision,
                'verification_stage' => 'decided',
                'reviewer_id' => $reviewer->id,
                'reviewed_at' => now(),
                'review_reason_code' => $reasonCode,
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

        // 1b. Retention: approved rewards enter PENDING, not available, when
        // the task carries a retention period (task-level override, else the
        // task type's retention_period_days). The retention:release command
        // moves matured holds to available. Legacy tasks (retention 0) keep
        // the direct-to-available behaviour.
        $retentionDays = (int) ($task->retention_days ?? $task->taskType?->retention_period_days ?? 0);

        if ($retentionDays > 0) {
            $this->walletService->hold(
                $wallet,
                $rewardCents,
                'retention_hold',
                "Retention hold ({$retentionDays}d) — submission #{$submission->id}",
                TaskSubmission::class,
                $submission->id,
                [
                    'release_at' => now()->addDays($retentionDays)->toIso8601String(),
                    'retention_days' => $retentionDays,
                ],
                "retention-hold-{$submission->id}"
            );
        }

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

        // 5. Affiliate qualification (Phase 8, three-level ledger): on the
        // contributor's FIRST approved task, pay every pending referral level
        // (L1/L2/L3) via ReferralService::qualifyAndReward(). It pays only
        // 'pending' rows, locks them, and is idempotent — a retry or a
        // concurrent first-approval can never double-pay.
        $triggeredRewardIds = $this->payReferralRewards($submission);
        if (!empty($triggeredRewardIds)) {
            $submission->update(['triggered_referral_reward_ids_json' => $triggeredRewardIds]);

            // Back-compat observability: keep the L1 referral row id on the
            // legacy column.
            $l1 = Referral::where('referred_user_id', $submission->user_id)
                ->where('level', 1)
                ->first();
            if ($l1) {
                $submission->update(['triggered_referral_id' => $l1->id]);
            }
        }
    }

    /**
     * Pay the referee's pending referral levels on their FIRST task approval.
     *
     * The pending referral rows are locked BEFORE calling qualifyAndReward,
     * so attribution is exact: the ReferralReward ids recorded on the
     * submission are precisely the levels THIS approval paid. A concurrent
     * first-approval blocks on the same locks and finds no pending rows, so
     * it pays (and records) nothing.
     *
     * @return int[] ReferralReward ids paid by this approval
     */
    protected function payReferralRewards(TaskSubmission $submission): array
    {
        $contributor = $submission->user;

        if (!config('referrals.require_first_task_approved', true)) {
            return [];
        }

        // First-approval gate: the submission was already flipped to
        // 'approved' by the caller, so exactly one approved row means this is
        // the contributor's first.
        $approvedCount = TaskSubmission::where('user_id', $contributor->id)
            ->where('status', 'approved')
            ->count();

        if ($approvedCount !== 1) {
            return [];
        }

        return DB::transaction(function () use ($contributor, $submission) {
            $pendingIds = Referral::where('referred_user_id', $contributor->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            if (empty($pendingIds)) {
                return [];
            }

            // Percent-mode referral rules pay a share of the referee's first
            // approved task reward — pass it as the payout basis.
            $basisCents = $submission->task ? (int) $submission->task->reward_cents : null;

            (new ReferralService($this->walletService))->qualifyAndReward($contributor, 'first_task_approved', $basisCents);

            // Rows that flipped pending -> rewarded while we held the locks
            // were paid by this approval — no more, no less.
            $flipped = Referral::whereIn('id', $pendingIds)
                ->where('status', 'rewarded')
                ->pluck('id')
                ->all();

            if (empty($flipped)) {
                return [];
            }

            return ReferralReward::whereIn('referral_id', $flipped)
                ->pluck('id')
                ->all();
        });
    }

    /**
     * Reject-after-approve path: unwind everything the approval did —
     * reverse the contributor's task_reward credit, restore the campaign pool,
     * roll back stats, and reverse the multi-level referral rewards if this
     * approval paid them (compensating ledger entries, never deletes).
     */
    protected function reverseApproval(TaskSubmission $submission, User $reviewer, string $reasonCode, string $notes): TaskSubmission
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
                // A retained reward never reached available: cancel any
                // outstanding retention hold, which fully unwinds the
                // credit+hold (pending and lifetime back to pre-approval).
                // Only when NO hold is outstanding (legacy task with no
                // retention, or retention already matured and released) do
                // we reverse the credit from available as before.
                $holds = WalletTransaction::where('wallet_id', $wallet->id)
                    ->where('type', 'retention_hold')
                    ->where('reference_type', TaskSubmission::class)
                    ->where('reference_id', $submission->id)
                    ->get();

                $retained = false;
                $cancelledCents = 0;

                foreach ($holds as $holdTx) {
                    $result = $this->walletService->cancelRetentionHold(
                        $wallet,
                        $holdTx,
                        "Submission #{$submission->id} rejected after approval"
                    );

                    if ($result->type === 'retention_hold_cancel') {
                        $retained = true;

                        if ($result->wasRecentlyCreated) {
                            $cancelledCents += (int) ($result->metadata_json['cancelled_amount_cents'] ?? 0);
                        }
                    }
                }

                if ($retained) {
                    // Return the unwound funds to the campaign escrow pool
                    // (fresh cancellation only — cancelRetentionHold is
                    // idempotent, so a retried reversal adds nothing).
                    if ($cancelledCents > 0 && $campaign && $campaign->business) {
                        $businessWallet = Wallet::firstOrCreate(
                            ['user_id' => $campaign->business->owner_id],
                            ['currency' => 'USD', 'available_balance_cents' => 0]
                        );

                        $this->walletService->restoreEscrow(
                            $businessWallet,
                            $cancelledCents,
                            "Escrow restored — retention cancelled for submission #{$submission->id}",
                            TaskSubmission::class,
                            $submission->id,
                            ['campaign_id' => $campaign->id]
                        );
                    }
                } else {
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

        // 4. Reverse the multi-level referral rewards THIS approval paid.
        // Compensating ledger entries only — rows are never deleted.
        // - reward row: 'rewarded' -> 'reversed' (idempotent: a retried
        //   reversal skips non-rewarded rows, so it can never double-reverse);
        // - referral row: back to 'pending' with qualified_at cleared, so a
        //   future qualifying approval pays the level again as a FRESH
        //   ledger credit (see ReferralService::payLevel's re-payment guard).
        $triggeredRewardIds = $submission->triggered_referral_reward_ids_json ?? [];

        foreach (ReferralReward::whereIn('id', $triggeredRewardIds)->lockForUpdate()->get() as $reward) {
            if ($reward->status !== ReferralReward::STATUS_REWARDED) {
                continue;
            }

            $referrerWallet = Wallet::firstOrCreate(
                ['user_id' => $reward->referrer_id],
                ['currency' => 'USD', 'available_balance_cents' => 0]
            );

            $originalTx = $reward->wallet_transaction_id
                ? WalletTransaction::where('id', $reward->wallet_transaction_id)->first()
                : null;

            $originalTx ??= WalletTransaction::where('reference_type', ReferralReward::class)
                ->where('reference_id', $reward->id)
                ->where('type', 'referral_reward')
                ->latest('id')
                ->first();

            if ($originalTx) {
                $this->walletService->reverseCredit(
                    $referrerWallet,
                    $originalTx,
                    "Referral L{$reward->level} reversed — submission #{$submission->id} rejected after approval"
                );
            }

            $reward->update(['status' => ReferralReward::STATUS_REVERSED]);

            Referral::where('id', $reward->referral_id)
                ->update(['status' => 'pending', 'qualified_at' => null]);

            AuditLogger::log(
                null,
                'referral.reversed',
                ReferralReward::class,
                $reward->id,
                [
                    'referrer_id' => $reward->referrer_id,
                    'referred_user_id' => $reward->referred_user_id,
                    'level' => $reward->level,
                    'amount_cents' => $reward->amount_cents,
                    'trigger' => "submission #{$submission->id} rejected after approval",
                ]
            );
        }

        $submission->update([
            'status' => 'rejected',
            'verification_stage' => 'decided',
            'reviewer_id' => $reviewer->id,
            'reviewed_at' => now(),
            'review_reason_code' => $reasonCode,
            'review_notes' => $notes,
            'triggered_referral_id' => null,
            'triggered_referral_reward_ids_json' => [],
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
}
