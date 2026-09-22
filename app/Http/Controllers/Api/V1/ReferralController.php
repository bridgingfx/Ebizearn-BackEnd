<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 8: contributor-facing affiliate endpoints. All data is real —
 * derived from the referral chain rows and the referral_reward ledger.
 */
class ReferralController extends Controller
{
    /**
     * Referral code/link, per-level stats, and the direct referral list.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $referrals = Referral::with('referredUser:id,name,email,created_at')
            ->where('referrer_id', $user->id)
            ->orderBy('level')
            ->latest('id')
            ->get();

        $levels = (int) config('referrals.levels', 3);
        $byLevel = [];

        for ($l = 1; $l <= $levels; $l++) {
            $levelRows = $referrals->where('level', $l);
            $byLevel[$l] = [
                'total' => $levelRows->count(),
                'rewarded' => $levelRows->where('status', 'rewarded')->count(),
                'reward_cents' => app(\App\Services\Referral\ReferralService::class)->rewardForLevel($l),
            ];
        }

        $totalEarnedCents = (int) WalletTransaction::where('wallet_id', $user->wallet?->id)
            ->where('type', 'referral_reward')
            ->sum('amount_cents');

        return response()->json([
            'success' => true,
            'data' => [
                'referral_code' => $user->referral_code,
                'referral_link' => rtrim((string) config('platform.frontendUrl'), '/') . '/signup/contributor?ref=' . $user->referral_code,
                'levels' => $levels,
                'total_referred' => $referrals->unique('referred_user_id')->count(),
                'by_level' => $byLevel,
                'total_earned_cents' => $totalEarnedCents,
                'referrals' => $referrals->map(fn (Referral $r) => [
                    'id' => $r->id,
                    'level' => $r->level,
                    'status' => $r->status,
                    'reward_cents' => $r->reward_cents,
                    'qualified_at' => $r->qualified_at,
                    'referred_user' => $r->referredUser ? [
                        'id' => $r->referredUser->id,
                        'name' => $r->referredUser->name,
                        'joined_at' => $r->referredUser->created_at,
                    ] : null,
                ])->values(),
            ],
        ]);
    }

    /**
     * The contributor's downline tree, up to the configured chain depth.
     */
    public function tree(Request $request): JsonResponse
    {
        $user = $request->user();
        $maxLevels = (int) config('referrals.levels', 3);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => ['id' => $user->id, 'name' => $user->name],
                'downline' => $this->buildTree($user->id, 1, $maxLevels),
            ],
        ]);
    }

    /**
     * Ledger-backed referral earnings (real payouts only).
     */
    public function earnings(Request $request): JsonResponse
    {
        $user = $request->user();

        $rewards = ReferralReward::with('referredUser:id,name')
            ->where('referrer_id', $user->id)
            ->where('status', ReferralReward::STATUS_REWARDED)
            ->latest('qualified_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $rewards->items(),
            'meta' => [
                'current_page' => $rewards->currentPage(),
                'last_page' => $rewards->lastPage(),
                'total' => $rewards->total(),
                'total_earned_cents' => (int) $rewards->getCollection()->sum('amount_cents'),
            ],
        ]);
    }

    /**
     * @return array<int, array>
     */
    protected function buildTree(int $referrerId, int $level, int $maxLevels): array
    {
        if ($level > $maxLevels) {
            return [];
        }

        $rows = Referral::with('referredUser:id,name,created_at')
            ->where('referrer_id', $referrerId)
            ->where('level', 1) // L1 edges only; deeper levels are reached recursively
            ->get();

        return $rows->map(function (Referral $row) use ($level, $maxLevels) {
            $referee = $row->referredUser;

            return [
                'user_id' => $row->referred_user_id,
                'name' => $referee?->name,
                'level' => $level,
                'status' => $row->status,
                'reward_cents' => $row->reward_cents,
                'joined_at' => $referee?->created_at,
                'downline' => $referee ? $this->buildTree($referee->id, $level + 1, $maxLevels) : [],
            ];
        })->values()->all();
    }
}
