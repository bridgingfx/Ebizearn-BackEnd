<?php

namespace App\Console\Commands;

use App\Models\Referral;
use App\Models\User;
use App\Services\Referral\ReferralService;
use Illuminate\Console\Command;

/**
 * One-time backfill for the pay-on-verified-join rule (2026-11-12):
 * referees who verified their email BEFORE the rule shipped still hold
 * pending referral rows — qualify them now so their referrers are paid
 * without waiting for a first approved task.
 *
 * Idempotent and safe to re-run: qualifyAndReward only pays rows still
 * 'pending', so a second run pays nothing new.
 *
 * LIMIT: joins whose referral code was dropped before signup (the old
 * /signup/contributor redirect lost ?ref= until this fix) have no
 * referrer recorded and cannot be recovered — there is no attribution
 * data to backfill from.
 */
class QualifyVerifiedReferrals extends Command
{
    protected $signature = 'referrals:qualify-verified';

    protected $description = 'Pay pending referral commissions for referees whose email is already verified (one-time backfill for pay-on-verified-join).';

    public function handle(ReferralService $referrals): int
    {
        $refereeIds = Referral::where('status', 'pending')
            ->distinct()
            ->pluck('referred_user_id');

        $qualified = 0;
        $paid = 0;

        foreach ($refereeIds as $refereeId) {
            $referee = User::find($refereeId);
            if (!$referee || !$referee->email_verified_at) {
                continue;
            }
            $rewards = $referrals->qualifyAndReward($referee, 'backfill_verified');
            if (!empty($rewards)) {
                $qualified++;
                $paid += count($rewards);
            }
        }

        $this->info("Qualified {$qualified} verified referees; paid {$paid} referral level(s).");

        return self::SUCCESS;
    }
}
