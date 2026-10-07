<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ContributorRankTier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Super Admin only: configure contributor rank tiers.
 * Controls promotion thresholds (tasks + earnings) and the bonus %
 * each rank earns on top of task rewards.
 */
class RankTierController extends Controller
{
    public function index(): JsonResponse
    {
        $tiers = ContributorRankTier::orderBy('sort_order')->get();
        return response()->json(['success' => true, 'data' => $tiers]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tier = ContributorRankTier::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'display_name' => 'sometimes|required|string|max:50',
            'required_tasks' => 'sometimes|required|integer|min:0|max:1000000',
            'required_earnings_cents' => 'sometimes|required|integer|min:0|max:100000000',
            'bonus_percent' => 'sometimes|required|numeric|min:0|max:100',
            'is_active' => 'sometimes|required|boolean',
            'sort_order' => 'sometimes|required|integer|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tier->update($validator->validated());

        return response()->json([
            'success' => true,
            'message' => "Rank '{$tier->display_name}' updated.",
            'data' => $tier->fresh(),
        ]);
    }
}
