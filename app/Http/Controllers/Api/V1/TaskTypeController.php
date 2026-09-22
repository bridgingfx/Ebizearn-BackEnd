<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TaskType;
use Illuminate\Http\JsonResponse;

/**
 * Phase 4: public task-type catalog — names, proof contracts, retention
 * defaults, allowed platforms, and the enforceable reward bands (Phase 12).
 * Bands are public so contributors and businesses see the honest pricing.
 */
class TaskTypeController extends Controller
{
    public function index(): JsonResponse
    {
        $types = TaskType::where('is_active', true)
            ->orderBy('id')
            ->get()
            ->map(fn (TaskType $t) => [
                'key' => $t->key,
                'name' => $t->name,
                'description' => $t->description,
                'is_allowed' => (bool) $t->is_allowed,
                'policy_note' => $t->policy_note,
                'proof_required' => $t->proof_required_json ?? [],
                'retention_period_days' => (int) $t->retention_period_days,
                'reward_band_min_cents' => (int) $t->reward_band_min_cents,
                'reward_band_max_cents' => (int) $t->reward_band_max_cents,
                'allowed_platforms' => $t->allowed_platforms_json ?? [],
            ]);

        return response()->json(['success' => true, 'data' => $types]);
    }
}
