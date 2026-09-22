<?php

namespace App\Http\Controllers\Api\V1\Ops;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\TaskType;
use App\Services\TaskTypes\RewardBandService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Phase 4/12 (ops): Super-Admin task-type management.
 *
 * Hidden /ops prefix, superadmin only (route group). Reward bands, the
 * per-type allowed flag, proof contracts, retention and fraud rules are all
 * editable here; every change is written to the append-only audit log.
 */
class OpsTaskTypeController extends Controller
{
    public function __construct(
        protected RewardBandService $bands = new RewardBandService()
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => TaskType::orderBy('id')->get(),
        ]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $type = TaskType::where('key', $key)->firstOrFail();

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'is_allowed' => 'sometimes|boolean',
            'policy_note' => 'nullable|string|max:1000',
            'reward_band_min_cents' => 'sometimes|integer|min:0',
            'reward_band_max_cents' => 'sometimes|integer|min:0',
            'proof_required' => 'nullable|array',
            'retention_period_days' => 'sometimes|integer|min:0|max:365',
            'fraud_rules' => 'nullable|array',
            'allowed_platforms' => 'nullable|array',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $before = $type->toArray();

        try {
            // Bands go through the dedicated service so min<=max is enforced
            // and the pricing change gets its own audit entry.
            if (array_key_exists('reward_band_min_cents', $data) || array_key_exists('reward_band_max_cents', $data)) {
                $min = $data['reward_band_min_cents'] ?? $type->reward_band_min_cents;
                $max = $data['reward_band_max_cents'] ?? $type->reward_band_max_cents;
                $this->bands->updateBand($type, (int) $min, (int) $max, $request->user());
                unset($data['reward_band_min_cents'], $data['reward_band_max_cents']);
            }

            $mapped = [];
            foreach ([
                'name' => 'name',
                'description' => 'description',
                'is_allowed' => 'is_allowed',
                'policy_note' => 'policy_note',
                'proof_required' => 'proof_required_json',
                'retention_period_days' => 'retention_period_days',
                'fraud_rules' => 'fraud_rules_json',
                'allowed_platforms' => 'allowed_platforms_json',
                'is_active' => 'is_active',
            ] as $in => $column) {
                if (array_key_exists($in, $data)) {
                    $mapped[$column] = $data[$in];
                }
            }

            if (!empty($mapped)) {
                $type->update($mapped);

                AuditLog::create([
                    'actor_id' => $request->user()->id,
                    'action' => 'task_type.updated',
                    'entity_type' => TaskType::class,
                    'entity_id' => $type->id,
                    'before_state_json' => $before,
                    'after_state_json' => $type->fresh()->toArray(),
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'created_at' => now(),
                ]);
            }
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $type->fresh()]);
    }
}
