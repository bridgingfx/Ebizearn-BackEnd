<?php

namespace App\Services\Contributors;

use App\Models\Profile;
use App\Models\TaskSubmission;
use App\Models\User;

/**
 * Earned contributor tiers.
 *
 * Tiers are EARNED from real activity — never purchased, never assigned by
 * hand (staff can still see them, not set them). The tier is recomputed from
 * the contributor's actual submission history every time a submission is
 * approved or a prior approval is reversed:
 *
 *   tier = highest tier whose thresholds are met
 *
 * Thresholds (approved tasks + approval rate over decided submissions):
 *   explorer:  >= 5 approved, rate >= 60%
 *   trusted:   >= 20 approved, rate >= 75%
 *   pro:       >= 50 approved, rate >= 85%
 *   elite:     >= 150 approved, rate >= 90%
 *   otherwise: starter
 *
 * The same pass also reconciles the profile's completed_tasks_count and
 * approval_rate so they always reflect reality instead of going stale.
 */
class ContributorTierService
{
    public const TIERS = ['starter', 'explorer', 'trusted', 'pro', 'elite'];

    /**
     * [tier => [min_approved, min_rate_percent]]
     */
    public const THRESHOLDS = [
        'explorer' => [5, 60.0],
        'trusted' => [20, 75.0],
        'pro' => [50, 85.0],
        'elite' => [150, 90.0],
    ];

    /**
     * Recompute and persist the contributor's tier from real submission
     * history. Returns the tier assigned.
     */
    public function recalculateFor(User $user): string
    {
        $approved = TaskSubmission::where('user_id', $user->id)
            ->where('status', 'approved')
            ->count();

        $rejected = TaskSubmission::where('user_id', $user->id)
            ->where('status', 'rejected')
            ->count();

        $decided = $approved + $rejected;
        $rate = $decided > 0 ? round($approved / $decided * 100, 2) : 100.0;

        $tier = 'starter';
        foreach (self::THRESHOLDS as $candidate => [$minApproved, $minRate]) {
            if ($approved >= $minApproved && $rate >= $minRate) {
                $tier = $candidate;
            }
        }

        $profile = $user->profile;
        if ($profile instanceof Profile) {
            $profile->update([
                'contributor_level' => $tier,
                'completed_tasks_count' => $approved,
                'approval_rate' => $rate,
            ]);
        }

        return $tier;
    }

    /**
     * Read-only tier evaluation without persisting (for previews / tests).
     */
    public function evaluate(int $approved, float $approvalRate): string
    {
        $tier = 'starter';
        foreach (self::THRESHOLDS as $candidate => [$minApproved, $minRate]) {
            if ($approved >= $minApproved && $approvalRate >= $minRate) {
                $tier = $candidate;
            }
        }

        return $tier;
    }
}
