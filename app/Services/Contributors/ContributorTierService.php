<?php

namespace App\Services\Contributors;

use App\Models\ContributorRankTier;
use App\Models\Profile;
use App\Models\TaskSubmission;
use App\Models\User;

/**
 * Contributor levels — the ONE place a contributor's level is decided.
 *
 * Levels go up with completed (approved) tasks. Admin sets how many
 * approved tasks each level needs on the Contributor Ranks page
 * (contributor_rank_tiers.required_tasks); the level is the highest active
 * tier whose requirement is met. It is recomputed whenever a submission is
 * approved or an approval is reversed (a reversal can move a contributor
 * back down).
 *
 * Staff can also set a level by hand and lock it (profiles.level_locked):
 * while locked, task completions do not change the level.
 *
 * Stats (completed_tasks_count, approval_rate) are reconciled on every
 * pass. Fields are assigned directly — they are not mass-assignable.
 */
class ContributorTierService
{
    public const TIERS = ['starter', 'explorer', 'trusted', 'pro', 'elite'];

    /**
     * Recompute and persist the contributor's level + stats from real
     * submission history. Returns the level the contributor now has.
     */
    public function recalculateFor(User $user): string
    {
        $approved = TaskSubmission::where('user_id', $user->id)->where('status', 'approved')->count();
        $rejected = TaskSubmission::where('user_id', $user->id)->where('status', 'rejected')->count();
        $decided = $approved + $rejected;
        $rate = $decided > 0 ? round($approved / $decided * 100, 2) : 100.0;

        $profile = $user->profile;
        if (!$profile instanceof Profile) {
            return $this->evaluate($approved);
        }

        $level = $profile->level_locked ? ($profile->contributor_level ?: 'starter') : $this->evaluate($approved);

        $profile->contributor_level = $level;
        $profile->completed_tasks_count = $approved;
        $profile->approval_rate = $rate;
        $profile->save();

        return $level;
    }

    /** Level for a number of approved tasks, from the admin-set thresholds. */
    public function evaluate(int $approved): string
    {
        $level = 'starter';
        foreach (ContributorRankTier::ordered() as $tier) {
            if ($approved >= (int) $tier->required_tasks) {
                $level = $tier->level;
            } else {
                break;
            }
        }

        return $level;
    }

    /**
     * Progress toward the next level (for the contributor's UI).
     *
     * @return array{level: string, next_level: ?string, next_name: ?string, tasks_done: int, tasks_needed: int}
     */
    public function progress(User $user): array
    {
        $approved = TaskSubmission::where('user_id', $user->id)->where('status', 'approved')->count();
        $current = $user->profile?->contributor_level ?: 'starter';
        $tiers = ContributorRankTier::ordered()->values();
        $idx = $tiers->search(fn ($t) => $t->level === $current);
        $next = $idx === false ? $tiers->first(fn ($t) => (int) $t->required_tasks > $approved) : $tiers->get($idx + 1);

        return [
            'level' => $current,
            'locked' => (bool) $user->profile?->level_locked,
            'next_level' => $next?->level,
            'next_name' => $next?->display_name,
            'tasks_done' => $approved,
            'tasks_needed' => (int) ($next?->required_tasks ?? 0),
        ];
    }
}
