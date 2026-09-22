<?php

namespace App\Services\Referral;

use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Audit\AuditLogger;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8: three-level affiliate ledger.
 *
 * Chain resolution happens at registration (buildChain): one Referral row
 * per level, walking the referee's referrer chain up to
 * config('referrals.levels'). Payouts happen only after the qualification
 * rules are met (email verified + first task approved, per config) via
 * qualifyAndReward(), which the task-approval flow calls.
 *
 * Double-pay safety: unique(referrer_id, referred_user_id, level) on both
 * referrals and referral_rewards, locked rows inside a transaction, and a
 * pre-credit check for an existing referral_reward ledger entry keyed on
 * (ReferralReward::class, reward id). A retry or concurrent qualification
 * can never pay the same level twice.
 */
class ReferralService
{
    public function __construct(
        protected WalletLedgerService $wallets = new WalletLedgerService()
    ) {}

    /**
     * Resolve and persist the multi-level chain for a freshly registered
     * user. Idempotent: safe to call again for the same referee.
     *
     * @return Referral[] the level rows created or found
     */
    public function buildChain(User $referee): array
    {
        if (!config('referrals.enabled', true)) {
            return [];
        }

        $maxLevels = max(1, (int) config('referrals.levels', 3));
        $rows = [];
        $seen = [$referee->id];

        $current = $referee->referrer;

        for ($level = 1; $level <= $maxLevels && $current; $level++) {
            if (in_array($current->id, $seen, true)) {
                break; // cycle guard — should never happen, never trust it
            }
            $seen[] = $current->id;

            if ($current->status !== 'active') {
                break; // the chain stops at an inactive account
            }

            if ((int) $current->id === (int) $referee->id) {
                break; // self-referral is not rewarded
            }

            $rows[] = Referral::firstOrCreate(
                [
                    'referrer_id' => $current->id,
                    'referred_user_id' => $referee->id,
                    'level' => $level,
                ],
                [
                    'status' => 'pending',
                    'reward_cents' => $this->rewardForLevel($level),
                ]
            );

            $current = $current->referrer;
        }

        return $rows;
    }

    /**
     * Qualify the referee and pay every pending level. Called by the
     * task-approval flow (Worker C wiring) when a referee's first task is
     * approved. Returns the rewards paid (or already paid) in this call.
     *
     * Idempotent: repeating the call changes nothing and pays nothing new.
     */
    public function qualifyAndReward(User $referee, ?string $trigger = null): array
    {
        if (!config('referrals.enabled', true)) {
            return [];
        }

        if (config('referrals.require_email_verified', true) && !$referee->email_verified_at) {
            return [];
        }

        return DB::transaction(function () use ($referee, $trigger) {
            $pending = Referral::where('referred_user_id', $referee->id)
                ->where('status', 'pending')
                ->orderBy('level')
                ->lockForUpdate()
                ->get();

            $paid = [];

            foreach ($pending as $referral) {
                $paid[] = $this->payLevel($referral, $referee, $trigger);
            }

            return $paid;
        });
    }

    /**
     * Reward amount for a level in cents. Missing levels inherit the
     * previous level's amount. A 0 amount marks the level rewarded with no
     * ledger movement.
     */
    public function rewardForLevel(int $level): int
    {
        $rewards = config('referrals.rewards_cents', [1 => 100]);
        $amount = null;

        for ($l = 1; $l <= $level; $l++) {
            if (array_key_exists($l, $rewards)) {
                $amount = (int) $rewards[$l];
            }
        }

        return max(0, (int) $amount);
    }

    /**
     * Pay one referral level. The referral row is already locked by the
     * caller; the unique(referrer_id, referred_user_id, level) key plus the
     * ledger-reference check make this safe under retry and concurrency.
     */
    protected function payLevel(Referral $referral, User $referee, ?string $trigger): ReferralReward
    {
        try {
            $reward = ReferralReward::firstOrCreate(
                [
                    'referrer_id' => $referral->referrer_id,
                    'referred_user_id' => $referral->referred_user_id,
                    'level' => $referral->level,
                ],
                [
                    'referral_id' => $referral->id,
                    'amount_cents' => (int) $referral->reward_cents,
                    'status' => ReferralReward::STATUS_PENDING,
                ]
            );
        } catch (QueryException $e) {
            // Lost a concurrent insert race: the winner's row is the truth.
            $reward = ReferralReward::where('referrer_id', $referral->referrer_id)
                ->where('referred_user_id', $referral->referred_user_id)
                ->where('level', $referral->level)
                ->lockForUpdate()
                ->firstOrFail();
        }

        if ($reward->status === ReferralReward::STATUS_REWARDED) {
            $referral->update(['status' => 'rewarded', 'qualified_at' => $referral->qualified_at ?? now()]);

            return $reward;
        }

        $amount = (int) $reward->amount_cents;

        if ($amount > 0) {
            // Retry guard: if a previous attempt credited the ledger but
            // crashed before marking the reward, reuse that entry instead of
            // writing a second one.
            $existingTx = WalletTransaction::where('reference_type', ReferralReward::class)
                ->where('reference_id', $reward->id)
                ->first();

            if (!$existingTx) {
                $wallet = Wallet::firstOrCreate(
                    ['user_id' => $referral->referrer_id],
                    ['currency' => 'USD', 'available_balance_cents' => 0]
                );

                $existingTx = $this->wallets->credit(
                    $wallet,
                    $amount,
                    'referral_reward',
                    "Referral reward L{$referral->level} — {$referee->name} qualified",
                    ReferralReward::class,
                    $reward->id,
                    [
                        'referral_id' => $referral->id,
                        'level' => $referral->level,
                        'referred_user_id' => $referee->id,
                        'trigger' => $trigger,
                    ]
                );
            }

            $reward->update([
                'wallet_transaction_id' => $existingTx->id,
                'status' => ReferralReward::STATUS_REWARDED,
                'qualified_at' => now(),
            ]);
        } else {
            $reward->update([
                'status' => ReferralReward::STATUS_REWARDED,
                'qualified_at' => now(),
            ]);
        }

        $referral->update(['status' => 'rewarded', 'qualified_at' => now()]);

        AuditLogger::log(
            null,
            'referral.rewarded',
            ReferralReward::class,
            $reward->id,
            [
                'referrer_id' => $referral->referrer_id,
                'referred_user_id' => $referee->id,
                'level' => $referral->level,
                'amount_cents' => $amount,
                'trigger' => $trigger,
            ]
        );

        return $reward->fresh();
    }
}
