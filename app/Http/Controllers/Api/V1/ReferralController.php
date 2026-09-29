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
        $referralService = app(\App\Services\Referral\ReferralService::class);

        for ($l = 1; $l <= $levels; $l++) {
            $levelRows = $referrals->where('level', $l);
            $rule = \App\Models\ReferralRule::forLevel($l);
            $byLevel[$l] = [
                'total' => $levelRows->count(),
                'rewarded' => $levelRows->where('status', 'rewarded')->count(),
                'reward_cents' => $referralService->rewardForLevel($l),
                'reward_mode' => $rule->reward_mode,
                'reward_description' => $rule->describe(),
            ];
        }

        // Ledger-backed total: referral_reward credits minus their
        // compensating referral_reward_reversal rows (reversed rewards must
        // not stay in the contributor's earnings figure).
        $walletId = $user->wallet?->id;
        $totalEarnedCents = $walletId
            ? (int) WalletTransaction::where('wallet_id', $walletId)
                ->whereIn('type', ['referral_reward', 'referral_reward_reversal'])
                ->sum('amount_cents')
            : 0;

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

        // The all-time total is a separate aggregate over every rewarded
        // row — the page collection would undercount on page 2+.
        $totalEarnedCents = (int) ReferralReward::where('referrer_id', $user->id)
            ->where('status', ReferralReward::STATUS_REWARDED)
            ->sum('amount_cents');

        return response()->json([
            'success' => true,
            'data' => $rewards->items(),
            'meta' => [
                'current_page' => $rewards->currentPage(),
                'last_page' => $rewards->lastPage(),
                'total' => $rewards->total(),
                'total_earned_cents' => $totalEarnedCents,
            ],
        ]);
    }

    /**
     * Downline tree built breadth-first: one query per level (never one
     * query per node), then the nesting is assembled in memory from the
     * direct-referral edge rows.
     *
     * @return array<int, array>
     */
    protected function buildTree(int $referrerId, int $level, int $maxLevels): array
    {
        if ($maxLevels < 1) {
            return [];
        }

        // Level N holds every user reached at depth N under the root.
        $byLevel = [];
        $referrerIds = [$referrerId];

        for ($depth = 1; $depth <= $maxLevels; $depth++) {
            // level=1 rows are the direct-referral edges (deeper chain rows
            // for the same referee carry higher levels).
            $rows = Referral::with('referredUser:id,name,created_at')
                ->where('level', 1)
                ->whereIn('referrer_id', $referrerIds)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            $byLevel[$depth] = $rows;
            $referrerIds = $rows->pluck('referred_user_id')->unique()->all();
        }

        $build = function (int $depth, int $parentId) use (&$build, $byLevel): array {
            $rows = $byLevel[$depth] ?? collect();

            return $rows->where('referrer_id', $parentId)
                ->map(fn (Referral $row) => [
                    'user_id' => $row->referred_user_id,
                    'name' => $row->referredUser?->name,
                    'level' => $depth,
                    'status' => $row->status,
                    'reward_cents' => $row->reward_cents,
                    'joined_at' => $row->referredUser?->created_at,
                    'downline' => $build($depth + 1, $row->referred_user_id),
                ])->values()->all();
        };

        return $build($level, $referrerId);
    }
}
