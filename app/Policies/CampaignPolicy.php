<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;

/**
 * Phase 13: tenant isolation for business campaigns.
 *
 * A campaign belongs to exactly one business tenant. Only the owning
 * business's user may view or mutate it; cross-tenant access must fail
 * closed (403), never leak existence.
 */
class CampaignPolicy
{
    public function view(User $user, Campaign $campaign): bool
    {
        return $this->owns($user, $campaign);
    }

    public function update(User $user, Campaign $campaign): bool
    {
        return $this->owns($user, $campaign);
    }

    public function delete(User $user, Campaign $campaign): bool
    {
        return $this->owns($user, $campaign);
    }

    private function owns(User $user, Campaign $campaign): bool
    {
        $business = $user->business;

        return $business !== null && (int) $business->id === (int) $campaign->business_id;
    }
}
