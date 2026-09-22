<?php

namespace App\Services\Wallet;

use App\Models\TaskSubmission;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;

/**
 * Phase 7: wallet breakdown.
 *
 * Every figure is computed from immutable records — the append-only ledger
 * (wallet_transactions), withdrawal requests, and submission states. The
 * mutable wallet row is never read for balances:
 *
 * - available:        balance_after_cents of the latest ledger row (every
 *                     movement of spendable funds writes it atomically).
 * - pending:          in-flight withdrawal requests (status 'requested') +
 *                     outstanding escrow holds derived from the escrow-flagged
 *                     ledger rows (hold/release/settle/restore).
 * - under_review:     expected value of submissions still in the pipeline
 *                     (submitted/checking/under_review/action_required).
 * - rejected:         value lost to rejected submissions.
 * - lifetime_earnings: sum of credit rows minus compensating reversal rows.
 * - total_withdrawn:  sum of withdrawal requests in processing/paid.
 */
class WalletBreakdownService
{
    /**
     * Submission statuses that still carry expected (not yet credited) value.
     */
    public const IN_REVIEW_STATUSES = ['submitted', 'checking', 'under_review', 'action_required'];

    /**
     * Reversal types written by WalletLedgerService::reverseCredit(), which
     * are the only rows besides credits that move lifetime earnings.
     */
    public const REVERSAL_TYPES = ['task_reward_reversal', 'referral_reward_reversal'];

    /**
     * @return array<string, int>
     */
    public function breakdown(User $user): array
    {
        $wallet = Wallet::firstOrCreate(
            ['user_id' => $user->id],
            ['currency' => 'USD', 'available_balance_cents' => 0]
        );

        $underReviewCents = (int) TaskSubmission::where('task_submissions.user_id', $user->id)
            ->whereIn('task_submissions.status', self::IN_REVIEW_STATUSES)
            ->join('tasks', 'tasks.id', '=', 'task_submissions.task_id')
            ->sum('tasks.reward_cents');

        $rejectedCents = (int) TaskSubmission::where('task_submissions.user_id', $user->id)
            ->where('task_submissions.status', 'rejected')
            ->join('tasks', 'tasks.id', '=', 'task_submissions.task_id')
            ->sum('tasks.reward_cents');

        return [
            'available_cents' => $this->availableFromLedger($wallet),
            'pending_cents' => $this->pendingFromLedger($wallet),
            'under_review_cents' => $underReviewCents,
            'rejected_cents' => $rejectedCents,
            'lifetime_earnings_cents' => $this->lifetimeFromLedger($wallet),
            'total_withdrawn_cents' => $this->withdrawnFromLedger($wallet),
        ];
    }

    /**
     * Spendable balance: the ledger's own record. Every row that moves
     * spendable funds stamps balance_after_cents in the same atomic
     * transaction, so the latest row IS the ledger-derived balance.
     */
    protected function availableFromLedger(Wallet $wallet): int
    {
        return (int) WalletTransaction::where('wallet_id', $wallet->id)
            ->latest('id')
            ->value('balance_after_cents');
    }

    /**
     * Funds not spendable right now: withdrawal requests still in flight plus
     * escrow that is held but not yet settled or released.
     */
    protected function pendingFromLedger(Wallet $wallet): int
    {
        $inFlightWithdrawals = (int) WithdrawalRequest::where('wallet_id', $wallet->id)
            ->where('status', 'requested')
            ->sum('amount_cents');

        return $inFlightWithdrawals + $this->outstandingEscrow($wallet);
    }

    /**
     * Outstanding escrow from the escrow-flagged ledger rows. The amount
     * column tracks the spendable-funds delta, so the pending delta is its
     * negation for hold/release rows (which move available<->pending) and
     * the amount itself for settle/restore rows (which touch pending only).
     *
     * Retention holds use their own types: retention_hold and
     * retention_release behave like hold/release; retention_hold_cancel
     * removes the held funds from pending entirely (like a settle).
     */
    protected function outstandingEscrow(Wallet $wallet): int
    {
        $rows = WalletTransaction::where('wallet_id', $wallet->id)
            ->where(function ($query) {
                $query->where('metadata_json', 'like', '%escrow_hold%')
                    ->orWhere('metadata_json', 'like', '%escrow_release%')
                    ->orWhere('metadata_json', 'like', '%escrow_settlement%')
                    ->orWhere('metadata_json', 'like', '%escrow_restore%')
                    ->orWhereIn('type', ['retention_hold', 'retention_release', 'retention_hold_cancel']);
            })
            ->get(['type', 'amount_cents', 'metadata_json']);

        $outstanding = 0;

        foreach ($rows as $row) {
            $meta = $row->metadata_json ?? [];
            $amount = (int) $row->amount_cents;

            if ($row->type === 'retention_hold' || $row->type === 'retention_release') {
                $outstanding += -$amount;
            } elseif ($row->type === 'retention_hold_cancel') {
                $outstanding += $amount;
            } elseif (!empty($meta['escrow_hold']) || !empty($meta['escrow_release'])) {
                $outstanding += -$amount;
            } elseif (!empty($meta['escrow_settlement']) || !empty($meta['escrow_restore'])) {
                $outstanding += $amount;
            }
        }

        return $outstanding;
    }

    /**
     * All-time earnings: every credit row adds, every compensating reversal
     * row subtracts — mirroring exactly how the ledger maintains the
     * lifetime column (credit() increments, reverseCredit() decrements,
     * nothing else touches it).
     */
    protected function lifetimeFromLedger(Wallet $wallet): int
    {
        $credits = (int) WalletTransaction::where('wallet_id', $wallet->id)
            ->where('amount_cents', '>', 0)
            ->where('type', '!=', 'withdrawal_reversal')
            ->where(function ($query) {
                // NULL metadata trivially has no escrow flags; NOT LIKE on
                // NULL would filter the row out, so keep NULLs explicitly.
                $query->whereNull('metadata_json')
                    ->orWhere('metadata_json', 'not like', '%escrow_release%');
            })
            ->where(function ($query) {
                $query->whereNull('metadata_json')
                    ->orWhere('metadata_json', 'not like', '%escrow_restore%');
            })
            ->sum('amount_cents');

        $reversals = (int) WalletTransaction::where('wallet_id', $wallet->id)
            ->whereIn('type', self::REVERSAL_TYPES)
            ->sum('amount_cents');

        return $credits + $reversals;
    }

    /**
     * Completed withdrawals: requests that left 'requested' for
     * processing/paid. Rejected requests return funds to available and are
     * excluded.
     */
    protected function withdrawnFromLedger(Wallet $wallet): int
    {
        return (int) WithdrawalRequest::where('wallet_id', $wallet->id)
            ->whereIn('status', ['processing', 'paid'])
            ->sum('amount_cents');
    }
}
