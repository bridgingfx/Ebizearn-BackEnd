<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\ReferralRule;
use App\Services\Audit\AuditLogger;
use App\Services\Referral\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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

    /**
     * View the current admin-controllable referral rules (one per level).
     *
     * Each level carries a reward_mode: 'flat' pays reward_cents per
     * qualified referee; 'percent' pays percent_bps basis points of the
     * referee's first approved task reward. Owner-approved defaults:
     * flat L1 $1.00 / L2 $0.50 / L3 $0.25.
     */
    public function rules(): JsonResponse
    {
        $service = app(ReferralService::class);

        return response()->json([
            'success' => true,
            'data' => [
                'program_enabled' => (bool) config('referrals.enabled', true),
                'levels' => (int) config('referrals.levels', 3),
                'qualification' => [
                    'require_email_verified' => (bool) config('referrals.require_email_verified', true),
                    'require_first_task_approved' => (bool) config('referrals.require_first_task_approved', true),
                ],
                'rules' => $service->rules(),
                // Lets the UI show an editable form or a read-only view.
                'can_edit' => (bool) request()->user()?->hasPermission(Permission::MANAGE_REFERRAL_RULES),
            ],
        ]);
    }

    /**
     * Update referral rules. Accepts a `levels` array of per-level objects;
     * only the levels present are touched, and every change is audit-logged.
     *
     * A rule change only affects FUTURE qualifications — rewards already
     * paid keep the amount they were paid at.
     */
    public function updateRules(Request $request): JsonResponse
    {
        $maxLevels = max(1, (int) config('referrals.levels', 3));

        $validator = Validator::make($request->all(), [
            'levels' => 'required|array|min:1',
            'levels.*.level' => "required|integer|min:1|max:{$maxLevels}",
            'levels.*.reward_mode' => 'required|in:flat,percent',
            'levels.*.reward_cents' => 'nullable|integer|min:0|max:1000000',
            'levels.*.percent_bps' => 'nullable|integer|min:0|max:10000',
            'levels.*.is_enabled' => 'sometimes|boolean',
        ]);

        $validator->after(function ($validator) use ($request) {
            foreach ((array) $request->input('levels', []) as $i => $input) {
                $mode = $input['reward_mode'] ?? null;

                if ($mode === ReferralRule::MODE_FLAT && !array_key_exists('reward_cents', $input)) {
                    $validator->errors()->add("levels.{$i}.reward_cents", 'The flat reward amount in cents is required for flat mode.');
                }

                if ($mode === ReferralRule::MODE_PERCENT && !array_key_exists('percent_bps', $input)) {
                    $validator->errors()->add("levels.{$i}.percent_bps", 'The percent in basis points is required for percent mode (1000 = 10%).');
                }
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $updated = [];

        foreach ($request->input('levels') as $input) {
            $level = (int) $input['level'];

            $before = ReferralRule::where('level', $level)->first();

            $rule = ReferralRule::updateOrCreate(
                ['level' => $level],
                [
                    'reward_mode' => $input['reward_mode'],
                    'reward_cents' => $input['reward_mode'] === ReferralRule::MODE_FLAT
                        ? (int) ($input['reward_cents'] ?? 0)
                        : ($before?->reward_cents ?? ReferralRule::configFlatCents($level)),
                    'percent_bps' => $input['reward_mode'] === ReferralRule::MODE_PERCENT
                        ? (int) ($input['percent_bps'] ?? 0)
                        : ($before?->percent_bps ?? 0),
                    'is_enabled' => array_key_exists('is_enabled', $input)
                        ? (bool) $input['is_enabled']
                        : true,
                ]
            );

            AuditLogger::log(
                $request->user(),
                'referral.rules_updated',
                ReferralRule::class,
                $rule->id,
                [
                    'level' => $level,
                    'reward_mode' => $rule->reward_mode,
                    'reward_cents' => $rule->reward_cents,
                    'percent_bps' => $rule->percent_bps,
                    'is_enabled' => $rule->is_enabled,
                ],
                $before?->only(['reward_mode', 'reward_cents', 'percent_bps', 'is_enabled']),
                $rule->only(['reward_mode', 'reward_cents', 'percent_bps', 'is_enabled'])
            );

            $updated[$level] = [
                'level' => $rule->level,
                'reward_mode' => $rule->reward_mode,
                'reward_cents' => $rule->reward_cents,
                'percent_bps' => $rule->percent_bps,
                'is_enabled' => $rule->is_enabled,
                'description' => $rule->describe(),
            ];
        }

        ksort($updated);

        return response()->json([
            'success' => true,
            'message' => 'Referral rules updated.',
            'data' => ['rules' => array_values($updated)],
        ]);
    }
}
