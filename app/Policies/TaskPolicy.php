<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

/**
 * Phase 13 (extended): tenant isolation for business tasks.
 *
 * A task belongs to a campaign, which belongs to exactly one business
 * tenant. Only the owning business's user may view or mutate it;
 * cross-tenant access fails closed (403), never leaking existence.
 */
class TaskPolicy
{
    public function view(User $user, Task $task): bool
    {
        return $this->owns($user, $task);
    }

    public function update(User $user, Task $task): bool
    {
        return $this->owns($user, $task);
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->owns($user, $task);
    }

    private function owns(User $user, Task $task): bool
    {
        $business = $user->business;

        return $business !== null
            && $task->campaign !== null
            && (int) $business->id === (int) $task->campaign->business_id;
    }
}
