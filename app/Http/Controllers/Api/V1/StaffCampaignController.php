<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\TaskSubmission;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Audit\AuditLogger;
use App\Services\Wallet\WalletLedgerService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Phase 9: staff (admin/moderator via manage_task_templates permission)
 * campaign management. Cross-tenant by design — staff can see every
 * campaign, but status changes and deletes follow strict safety rules:
 * - pause/resume any active/paused campaign
 * - cancel releases unspent escrow back to the business wallet
 * - delete is refused once money has moved or submissions exist
 */
class StaffCampaignController extends Controller
{
    public function __construct(
        protected WalletLedgerService $ledger = new WalletLedgerService()
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Campaign::with(['business.owner', 'category'])
            ->withCount('tasks')
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('business_id')) {
            $query->where('business_id', $request->input('business_id'));
        }

        if ($request->filled('search')) {
            $query->where('title', 'like', '%' . $request->input('search') . '%');
        }

        return response()->json([
            'success' => true,
            'data' => $query->paginate($request->input('per_page', 20)),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $campaign = Campaign::where(fn ($q) => $q->where('id', $id)->orWhere('uuid', $id))
            ->with(['business.owner', 'category', 'tasks.taskType'])
            ->withCount('tasks')
            ->firstOrFail();

        $spent = (int) TaskSubmission::whereIn('task_id', $campaign->tasks()->pluck('id'))
            ->where('status', 'approved')
            ->join('tasks', 'tasks.id', '=', 'task_submissions.task_id')
            ->sum('tasks.reward_cents');

        return response()->json([
            'success' => true,
            'data' => array_merge($campaign->toArray(), ['spent_cents' => $spent]),
        ]);
    }

    /**
     * Pause / resume / cancel a campaign.
     * Cancel releases the unspent escrow hold back to the business wallet
     * and cancels the open task pool — never deletes money history.
     */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:active,paused,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $campaign = Campaign::where(fn ($q) => $q->where('id', $id)->orWhere('uuid', $id))->firstOrFail();
        $target = $request->input('status');

        $allowed = [
            'draft' => ['cancelled'],
            // Priority 4 — approval gate: staff approves pending_review
            // campaigns to active (publishes tasks) or cancels them.
            'pending_review' => ['active', 'cancelled'],
            'active' => ['paused', 'cancelled'],
            'paused' => ['active', 'cancelled'],
        ];

        if (!in_array($target, $allowed[$campaign->status] ?? [], true)) {
            return response()->json([
                'success' => false,
                'message' => "Cannot move campaign from '{$campaign->status}' to '{$target}'.",
            ], 422);
        }

        try {
            $campaign = DB::transaction(function () use ($campaign, $target, $request) {
                $locked = Campaign::where('id', $campaign->id)->lockForUpdate()->firstOrFail();

                if ($target === 'cancelled') {
                    $this->releaseUnspentEscrow($locked);
                    // No 'cancelled' state on tasks — pausing the pool stops
                    // new assignments; the cancelled campaign is the truth.
                    $locked->tasks()->where('status', 'available')->update(['status' => 'paused']);
                }

                $locked->update(['status' => $target]);

                AuditLogger::log(
                    $request->user(),
                    'campaign.status_changed',
                    Campaign::class,
                    $locked->id,
                    ['from' => $campaign->status, 'to' => $target]
                );

                return $locked->fresh();
            });
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $campaign]);
    }

    /**
     * Safe delete: only drafts with no spend and no submissions can be
     * removed. Anything that touched money or contributors is cancelled
     * instead of deleted (use updateStatus).
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $campaign = Campaign::where(fn ($q) => $q->where('id', $id)->orWhere('uuid', $id))->firstOrFail();

        if ($campaign->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft campaigns can be deleted. Cancel a live campaign instead — its history is preserved.',
            ], 422);
        }

        $taskIds = $campaign->tasks()->pluck('id');
        $hasSubmissions = $taskIds->isNotEmpty()
            && TaskSubmission::whereIn('task_id', $taskIds)->exists();

        if ($hasSubmissions || (int) $campaign->completed_contributors_count > 0) {
            return response()->json([
                'success' => false,
                'message' => 'This campaign has contributor activity and cannot be deleted.',
            ], 422);
        }

        $campaign->tasks()->delete();
        $campaign->delete();

        AuditLogger::log(
            $request->user(),
            'campaign.deleted',
            Campaign::class,
            $campaign->id,
            ['title' => $campaign->title]
        );

        return response()->json(['success' => true, 'message' => 'Draft campaign deleted.']);
    }

    /**
     * Return the unspent rewards budget to the business wallet's available
     * balance. The releasable amount is derived from THIS campaign's own
     * escrow ledger rows (launch + top-up holds, minus settlements,
     * restores and prior releases) — never from the wallet's aggregate
     * pending balance, which may hold other campaigns' escrow, retention
     * holds and in-flight withdrawals.
     */
    protected function releaseUnspentEscrow(Campaign $campaign): void
    {
        $business = $campaign->business()->first();

        if (!$business) {
            return;
        }

        $wallet = Wallet::where('user_id', $business->owner_id)->lockForUpdate()->first();

        if (!$wallet) {
            return;
        }

        $releasable = min((int) $wallet->pending_balance_cents, $this->campaignOutstandingEscrow($wallet, $campaign));

        if ($releasable > 0) {
            $this->ledger->releaseHold(
                $wallet,
                $releasable,
                'campaign_funding',
                "Escrow released — campaign cancelled: {$campaign->title}",
                Campaign::class,
                $campaign->id
            );
        }
    }

    /**
     * This campaign's outstanding escrow in cents: launch/top-up holds,
     * minus reward settlements, plus reversal restores, minus prior
     * releases. All derived from the campaign's own ledger rows.
     */
    protected function campaignOutstandingEscrow(Wallet $wallet, Campaign $campaign): int
    {
        $base = WalletTransaction::where('wallet_id', $wallet->id)
            ->where('type', 'campaign_funding')
            ->where('reference_type', Campaign::class)
            ->where('reference_id', $campaign->id);

        $held = (int) (clone $base)->where('metadata_json', 'like', '%escrow_hold%')->sum('amount_cents'); // negative
        $released = (int) (clone $base)->where('metadata_json', 'like', '%escrow_release%')->sum('amount_cents'); // positive

        $movement = WalletTransaction::where('wallet_id', $wallet->id)
            ->where('type', 'campaign_funding')
            ->where('metadata_json->campaign_id', $campaign->id);

        $settled = (int) (clone $movement)->where('metadata_json', 'like', '%escrow_settlement%')->sum('amount_cents'); // negative
        $restored = (int) (clone $movement)->where('metadata_json', 'like', '%escrow_restore%')->sum('amount_cents'); // positive

        return max(0, -$held + $settled - $restored - $released);
    }
}
