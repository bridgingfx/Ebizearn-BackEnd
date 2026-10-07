<?php

namespace App\Services;

use App\Models\ContributorRankTier;
use App\Models\TaskSubmission;
use App\Models\User;

/**
 * Contributor rank engine: bonus calculation + automatic promotion.
 *
 * Ranks (low → high): starter → explorer → trusted → pro → elite.
 * Each rank carries a configurable bonus % paid on top of every task
 * reward (default +5% per rank). Contributors auto-promote when they
 * meet BOTH the task count and earnings thresholds of the next tier.
 */
class ContributorRankService
{
    /**
     * Bonus amount (cents) for a task reward at the user's current rank.
     */
    public function bonusFor(User $user, int $rewardCents): int
    {
        $level = $user->profile?->contributor_level ?? 'starter';
        $tier = ContributorRankTier::forLevel($level);
        if (!$tier || !$tier->is_active) {
            return 0;
        }
        $pct = (float) $tier->bonus_percent;
        if ($pct <= 0 || $rewardCents <= 0) {
            return 0;
        }
        return (int) round($rewardCents * $pct / 100);
    }

    /**
     * Total payout (reward + rank bonus) in cents.
     */
    public function payoutFor(User $user, int $rewardCents): int
    {
        return $rewardCents + $this->bonusFor($user, $rewardCents);
    }

    /**
     * Check and apply promotion. Returns the new level or null if unchanged.
     * Call after every approved submission.
     */
    public function maybePromote(User $user): ?string
    {
        if ($user->role !== 'contributor') {
            return null;
        }
        $profile = $user->profile;
        if (!$profile) {
            return null;
        }

        $tiers = ContributorRankTier::ordered();
        if ($tiers->isEmpty()) {
            return null;
        }

        // Find current tier position.
        $currentLevel = $profile->contributor_level ?? 'starter';
        $currentIdx = $tiers->search(fn ($t) => $t->level === $currentLevel);
        if ($currentIdx === false) {
            $currentIdx = -1;
        }

        // Contributor stats.
        $approvedCount = TaskSubmission::where('user_id', $user->id)
            ->where('status', 'approved')
            ->count();
        $totalEarned = TaskSubmission::where('user_id', $user->id)
            ->where('status', 'approved')
            ->with('task')
            ->get()
            ->sum(fn ($s) => ($s->task->reward_cents ?? 0) + ($s->bonus_cents ?? 0));

        // Promote through every tier whose thresholds are met.
        $newLevel = null;
        for ($i = $currentIdx + 1; $i < $tiers->count(); $i++) {
            $tier = $tiers[$i];
            $tasksOk = $tier->required_tasks <= 0 || $approvedCount >= $tier->required_tasks;
            $earnOk = $tier->required_earnings_cents <= 0 || $totalEarned >= $tier->required_earnings_cents;
            if ($tasksOk && $earnOk) {
                $newLevel = $tier->level;
            } else {
                break;
            }
        }

        if ($newLevel && $newLevel !== $currentLevel) {
            $profile->update(['contributor_level' => $newLevel]);
            return $newLevel;
        }
        return null;
    }

    /**
     * Progress toward the next rank (for UI display).
     */
    public function progressToNext(User $user): array
    {
        $profile = $user->profile;
        $currentLevel = $profile?->contributor_level ?? 'starter';
        $tiers = ContributorRankTier::ordered();
        $currentIdx = $tiers->search(fn ($t) => $t->level === $currentLevel);

        if ($currentIdx === false || $currentIdx >= $tiers->count() - 1) {
            return ['next_level' => null, 'next_name' => null, 'tasks_done' => 0, 'tasks_needed' => 0, 'earned_cents' => 0, 'earnings_needed_cents' => 0];
        }

        $next = $tiers[$currentIdx + 1];
        $approvedCount = TaskSubmission::where('user_id', $user->id)->where('status', 'approved')->count();
        $totalEarned = TaskSubmission::where('user_id', $user->id)
            ->where('status', 'approved')->with('task')->get()
            ->sum(fn ($s) => ($s->task->reward_cents ?? 0) + ($s->bonus_cents ?? 0));

        return [
            'next_level' => $next->level,
            'next_name' => $next->display_name,
            'next_bonus_percent' => (float) $next->bonus_percent,
            'tasks_done' => $approvedCount,
            'tasks_needed' => $next->required_tasks,
            'earned_cents' => $totalEarned,
            'earnings_needed_cents' => $next->required_earnings_cents,
        ];
    }
}
