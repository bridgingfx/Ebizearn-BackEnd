<?php

namespace App\Services\Referral;

use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\ReferralRule;
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

            if ($current->status === 'suspended') {
                break; // the chain stops at a suspended account. Note:
                // pending_verification signups still build chains — their
                // rewards only pay out on qualification (verified email).
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
     * $basisCents is the referee's first approved task reward — the basis
     * for percent-mode rules (flat-mode rules ignore it). When a percent
     * rule has no basis available, it falls back to its flat estimate so
     * qualification can never silently pay zero.
     *
     * Idempotent: repeating the call changes nothing and pays nothing new.
     */
    public function qualifyAndReward(User $referee, ?string $trigger = null, ?int $basisCents = null): array
    {
        if (!config('referrals.enabled', true)) {
            return [];
        }

        if (config('referrals.require_email_verified', true) && !$referee->email_verified_at) {
            return [];
        }

        return DB::transaction(function () use ($referee, $trigger, $basisCents) {
            $pending = Referral::where('referred_user_id', $referee->id)
                ->where('status', 'pending')
                ->orderBy('level')
                ->lockForUpdate()
                ->get();

            $paid = [];

            foreach ($pending as $referral) {
                $paid[] = $this->payLevel($referral, $referee, $trigger, $basisCents);
            }

            return $paid;
        });
    }

    /**
     * Reward amount for a level in cents. Flat-mode rules return their fixed
     * amount; percent-mode rules return the percentage of $basisCents (with
     * a flat fallback when no basis is available). Levels with no rule row
     * fall back to config('referrals'), inheriting the previous level's
     * amount for missing levels. A 0 amount marks the level rewarded with no
     * ledger movement.
     */
    public function rewardForLevel(int $level, ?int $basisCents = null): int
    {
        return ReferralRule::forLevel($level)->payoutCents($basisCents);
    }

    /**
     * The admin-visible rule set, one descriptor per level.
     *
     * @return array<int, array>
     */
    public function rules(): array
    {
        $levels = max(1, (int) config('referrals.levels', 3));
        $out = [];

        for ($level = 1; $level <= $levels; $level++) {
            $rule = ReferralRule::forLevel($level);
            $out[$level] = [
                'level' => $level,
                'reward_mode' => $rule->reward_mode,
                'reward_cents' => $rule->reward_cents ?? ReferralRule::configFlatCents($level),
                'percent_bps' => (int) $rule->percent_bps,
                'percent' => round(((int) $rule->percent_bps) / 100, 2),
                'is_enabled' => (bool) $rule->is_enabled,
                'from_database' => $rule->exists,
                'description' => $rule->describe(),
            ];
        }

        return $out;
    }

    /**
     * Pay one referral level. The referral row is already locked by the
     * caller; the unique(referrer_id, referred_user_id, level) key plus the
     * ledger-reference check make this safe under retry and concurrency.
     *
     * The actual payout comes from the admin-controllable rule for the level
     * (flat amount, or percent of the referee's first approved task reward
     * as $basisCents). The referral row's reward_cents estimate recorded at
     * registration is synced to the actual amount paid.
     */
    protected function payLevel(Referral $referral, User $referee, ?string $trigger, ?int $basisCents = null): ReferralReward
    {
        // The actual payout for this level under the current admin rules.
        $payoutCents = $this->rewardForLevel($referral->level, $basisCents);

        try {
            $reward = ReferralReward::firstOrCreate(
                [
                    'referrer_id' => $referral->referrer_id,
                    'referred_user_id' => $referral->referred_user_id,
                    'level' => $referral->level,
                ],
                [
                    'referral_id' => $referral->id,
                    'amount_cents' => $payoutCents,
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

        // A rule change between registration (estimate) and qualification
        // (payout) must not rewrite a reward that was already paid.
        if ($reward->status === ReferralReward::STATUS_REWARDED) {
            $referral->update(['status' => 'rewarded', 'qualified_at' => $referral->qualified_at ?? now()]);

            return $reward;
        }

        $amount = $payoutCents;
        $reward->update(['amount_cents' => $amount]);

        if ($amount > 0) {
            // Retry guard: if a previous attempt credited the ledger but
            // crashed before marking the reward, reuse that entry instead of
            // writing a second one.
            //
            // Phase 6 addition (Worker C): when the previous credit was
            // legitimately REVERSED (reject-after-approve writes a
            // referral_reward_reversal entry against it), the reversal proves
            // the money was taken back — so this re-qualification writes a
            // FRESH credit instead of resurrecting the reversed one. The old
            // credit stays in history; nothing is ever deleted.
            $existingTx = WalletTransaction::where('reference_type', ReferralReward::class)
                ->where('reference_id', $reward->id)
                ->where('type', 'referral_reward')
                ->latest('id')
                ->first();

            $wasReversed = $existingTx && WalletTransaction::where('reference_type', WalletTransaction::class)
                ->where('reference_id', $existingTx->id)
                ->where('type', 'referral_reward_reversal')
                ->exists();

            if (!$existingTx || $wasReversed) {
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

        // Sync the registration-time estimate to the amount actually paid
        // under the current admin rules.
        $referral->update([
            'status' => 'rewarded',
            'reward_cents' => $amount,
            'qualified_at' => now(),
        ]);

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
                'reward_mode' => ReferralRule::forLevel($referral->level)->reward_mode,
                'trigger' => $trigger,
            ]
        );

        return $reward->fresh();
    }
}
