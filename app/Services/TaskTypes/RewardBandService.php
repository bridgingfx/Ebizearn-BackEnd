<?php

namespace App\Services\TaskTypes;

use App\Models\AuditLog;
use App\Models\TaskType;
use App\Models\User;
use Exception;

/**
 * Phase 12: reward-band enforcement.
 *
 * Every task reward is validated against its task type's band
 * (reward_band_min_cents..reward_band_max_cents). Out-of-band rewards are
 * rejected with a 422 carrying the honest band limits. Bands are readable
 * by anyone (public task-type list) but writable only through the
 * Super-Admin ops API, and every change is audit-logged.
 */
class RewardBandViolationException extends Exception
{
    public function __construct(
        public readonly TaskType $taskType,
        public readonly int $rewardCents
    ) {
        parent::__construct(sprintf(
            'Reward $%s is outside the allowed band for task type "%s" ($%s – $%s).',
            number_format($rewardCents / 100, 2),
            $taskType->name,
            number_format($taskType->reward_band_min_cents / 100, 2),
            number_format($taskType->reward_band_max_cents / 100, 2)
        ));
    }
}

class RewardBandService
{
    /**
     * @return array{0: int, 1: int} [min_cents, max_cents]
     */
    public function bandFor(TaskType $taskType): array
    {
        return [(int) $taskType->reward_band_min_cents, (int) $taskType->reward_band_max_cents];
    }

    public function withinBand(TaskType $taskType, int $rewardCents): bool
    {
        [$min, $max] = $this->bandFor($taskType);

        return $rewardCents >= $min && $rewardCents <= $max;
    }

    /**
     * @throws RewardBandViolationException
     */
    public function assertWithinBand(TaskType $taskType, int $rewardCents): void
    {
        if (!$this->withinBand($taskType, $rewardCents)) {
            throw new RewardBandViolationException($taskType, $rewardCents);
        }
    }

    /**
     * Super-Admin band update. min must be <= max and both non-negative.
     * Audit-logged: pricing changes must always leave a paper trail.
     */
    public function updateBand(TaskType $taskType, int $minCents, int $maxCents, User $actor): TaskType
    {
        if ($minCents < 0 || $maxCents < 0) {
            throw new Exception('Reward band limits must be non-negative.');
        }

        if ($minCents > $maxCents) {
            throw new Exception('Reward band minimum cannot exceed the maximum.');
        }

        $before = $taskType->only(['reward_band_min_cents', 'reward_band_max_cents']);

        $taskType->update([
            'reward_band_min_cents' => $minCents,
            'reward_band_max_cents' => $maxCents,
        ]);

        AuditLog::create([
            'actor_id' => $actor->id,
            'action' => 'task_type.band_updated',
            'entity_type' => TaskType::class,
            'entity_id' => $taskType->id,
            'before_state_json' => $before,
            'after_state_json' => $taskType->fresh()->only(['reward_band_min_cents', 'reward_band_max_cents']),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);

        return $taskType->fresh();
    }

    /**
     * Resolve an active, allowed task type by key, or fail honestly.
     *
     * @throws Exception
     */
    public function resolveType(string $key): TaskType
    {
        $type = TaskType::where('key', $key)->where('is_active', true)->first();

        if (!$type) {
            throw new Exception("Unknown or inactive task type: {$key}.");
        }

        if (!$type->is_allowed) {
            $note = $type->policy_note ? ' ' . $type->policy_note : '';

            throw new Exception("Task type \"{$type->name}\" is currently disabled.{$note}");
        }

        return $type;
    }
}
