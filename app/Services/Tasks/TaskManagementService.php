<?php

namespace App\Services\Tasks;

use App\Models\Business;
use App\Models\Campaign;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskSubmission;
use App\Services\TaskTypes\RewardBandService;
use App\Services\TaskTypes\RewardBandViolationException;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shared task create/update/delete logic for the admin/moderator and
 * business task APIs.
 *
 * Money safety: a task's slots are capped by the campaign's FUNDED pool
 * (remaining + reserved). No money moves at task creation — budget moves
 * remaining -> reserved at assignment time and reserved -> spent at
 * approval (existing, tested flows). The pool check runs under a campaign
 * row lock so concurrent creations cannot oversell it; the assignment-time
 * guard remains the final authority.
 */
class TaskManagementService
{
    public function __construct(
        protected RewardBandService $bands = new RewardBandService()
    ) {}

    /**
     * @param array $data validated task fields (see controllers)
     * @throws RewardBandViolationException|Exception
     */
    public function createTask(array $data, ?Business $scopeBusiness = null): Task
    {
        return DB::transaction(function () use ($data, $scopeBusiness) {
            $campaign = Campaign::where('id', $data['campaign_id'])->lockForUpdate()->firstOrFail();

            if ($scopeBusiness && (int) $campaign->business_id !== (int) $scopeBusiness->id) {
                throw new Exception('This campaign does not belong to your business.', 403);
            }

            $type = $this->bands->resolveType($data['task_type_key']);
            $this->bands->assertWithinBand($type, (int) $data['reward_cents']);

            $this->assertPoolCovers($campaign, (int) $data['reward_cents'], (int) $data['slots_total']);

            return Task::create([
                'uuid' => (string) Str::uuid(),
                'campaign_id' => $campaign->id,
                'category_id' => $data['category_id'] ?? $campaign->category_id,
                'task_type_id' => $type->id,
                'title' => $data['title'],
                'platform' => $data['platform'] ?? null,
                'country_code' => $data['country_code'] ?? null,
                'instructions' => $data['instructions'] ?? $campaign->instructions_markdown,
                'proof_required_json' => $data['proof_required'] ?? $type->proof_required_json,
                'retention_days' => $data['retention_days'] ?? $type->retention_period_days,
                'fraud_rules_json' => $data['fraud_rules'] ?? $type->fraud_rules_json,
                'company_name' => $data['company_name'] ?? $campaign->business->company_name,
                'company_logo_url' => $data['company_logo_url'] ?? null,
                'reward_cents' => (int) $data['reward_cents'],
                'estimated_minutes' => $data['estimated_minutes'] ?? 5,
                'difficulty' => $data['difficulty'] ?? 'easy',
                'status' => 'available',
                'slots_total' => (int) $data['slots_total'],
                'slots_taken' => 0,
            ]);
        });
    }

    /**
     * Update mutable task fields. Reward/slot changes re-check the pool;
     * slots can never drop below slots already taken. The campaign can
     * NEVER be reassigned — campaign_id is stripped defensively even though
     * controllers validate it as sometimes-present.
     */
    public function updateTask(Task $task, array $data): Task
    {
        return DB::transaction(function () use ($task, $data) {
            $locked = Task::where('id', $task->id)->lockForUpdate()->firstOrFail();
            $campaign = Campaign::where('id', $locked->campaign_id)->lockForUpdate()->firstOrFail();

            // A task never moves between campaigns.
            unset($data['campaign_id']);

            // Same input mapping as creation: API field names -> json columns.
            if (array_key_exists('proof_required', $data)) {
                $data['proof_required_json'] = $data['proof_required'];
                unset($data['proof_required']);
            }
            if (array_key_exists('fraud_rules', $data)) {
                $data['fraud_rules_json'] = $data['fraud_rules'];
                unset($data['fraud_rules']);
            }

            if (array_key_exists('reward_cents', $data) || array_key_exists('slots_total', $data)) {
                $reward = (int) ($data['reward_cents'] ?? $locked->reward_cents);
                $slots = (int) ($data['slots_total'] ?? $locked->slots_total);

                if ($slots < $locked->slots_taken) {
                    throw new Exception(
                        "Slots cannot drop below {$locked->slots_taken} — that many are already taken.",
                        422
                    );
                }

                $type = $locked->taskType;
                if ($type) {
                    $this->bands->assertWithinBand($type, $reward);
                }

                // Pool check on the DELTA only: previously committed slots
                // were already covered when the task was created.
                $oldCost = (int) $locked->reward_cents * (int) $locked->slots_total;
                $newCost = $reward * $slots;
                $pool = (int) $campaign->remaining_budget_cents + (int) $campaign->reserved_budget_cents;

                if ($newCost - $oldCost > $pool) {
                    throw new Exception(
                        'The campaign\'s funded pool cannot cover the new reward × slots. ' .
                        'Top up the campaign first.',
                        422
                    );
                }

                $data['reward_cents'] = $reward;
                $data['slots_total'] = $slots;
            }

            if (array_key_exists('task_type_key', $data)) {
                $type = $this->bands->resolveType($data['task_type_key']);
                $this->bands->assertWithinBand($type, (int) ($data['reward_cents'] ?? $locked->reward_cents));
                $data['task_type_id'] = $type->id;
                unset($data['task_type_key']);
            }

            $locked->update($data);

            return $locked->fresh();
        });
    }

    /**
     * Soft-delete a task. Refused when participants are active — pausing is
     * the honest alternative, because reservations hold campaign budget.
     */
    public function deleteTask(Task $task): void
    {
        if ($task->slots_taken > 0) {
            throw new Exception('This task has active participants — pause it instead of deleting.', 422);
        }

        $activeWork = TaskAssignment::where('task_id', $task->id)
            ->whereIn('status', ['reserved', 'in_progress', 'submitted'])
            ->exists()
            || TaskSubmission::where('task_id', $task->id)->exists();

        if ($activeWork) {
            throw new Exception('This task has assignments or submissions — pause it instead of deleting.', 422);
        }

        $task->delete();
    }

    /**
     * The campaign's funded pool must cover the task's maximum possible
     * cost (reward × slots). Checked under the caller's campaign row lock.
     *
     * @throws Exception
     */
    protected function assertPoolCovers(Campaign $campaign, int $rewardCents, int $slots): void
    {
        $maxCost = $rewardCents * $slots;
        $pool = (int) $campaign->remaining_budget_cents + (int) $campaign->reserved_budget_cents;

        if ($maxCost > $pool) {
            throw new Exception(
                'The campaign\'s funded pool ($' . number_format($pool / 100, 2) . ') cannot cover ' .
                $slots . ' slots at $' . number_format($rewardCents / 100, 2) . ' ' .
                '($' . number_format($maxCost / 100, 2) . '). Top up the campaign first.',
                422
            );
        }
    }
}
