<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\ReferralReward;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 11: platform-wide referral overview (read-only).
 *
 * Aggregates the three-level referral ledger Worker B built — referral
 * counts and reward totals per level and per status, plus the most recent
 * reward rows. Everything is computed from real records; when the ledger
 * is empty the page shows an honest empty state instead of zeros dressed
 * up as performance.
 */
class AdminReferralController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $perLevel = ReferralReward::selectRaw('level, status, COUNT(*) as rewards_count, COALESCE(SUM(amount_cents), 0) as rewards_cents')
            ->groupBy('level', 'status')
            ->orderBy('level')
            ->get();

        $totals = [
            'referrals_count' => Referral::count(),
            'rewards_count' => ReferralReward::count(),
            'rewarded_cents' => (int) ReferralReward::where('status', ReferralReward::STATUS_REWARDED)->sum('amount_cents'),
            'pending_cents' => (int) ReferralReward::where('status', ReferralReward::STATUS_PENDING)->sum('amount_cents'),
            'reversed_cents' => (int) ReferralReward::where('status', ReferralReward::STATUS_REVERSED)->sum('amount_cents'),
        ];

        $recent = ReferralReward::with([
                'referrer:id,name',
                'referredUser:id,name',
            ])
            ->latest('id')
            ->limit($request->integer('recent_limit', 25))
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'totals' => $totals,
                'per_level' => $perLevel,
                'recent' => $recent,
            ],
        ]);
    }
}
