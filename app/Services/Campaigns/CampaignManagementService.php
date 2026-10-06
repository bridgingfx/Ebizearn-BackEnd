<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\TaskAssignment;
use App\Models\TaskSubmission;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletLedgerService;
use Exception;
use Illuminate\Support\Facades\DB;

/**
 * Shared campaign edit / delete / escrow-release logic for the staff and
 * business campaign APIs.
 *
 * Money safety:
 * - Editing touches copy and targeting only — never reward, budget or
 *   contributor count (those are backed by the escrow hold).
 * - Delete is refused once any contributor has touched the campaign
 *   (assignment or submission). Otherwise the campaign's outstanding escrow
 *   is released back to the business wallet first, then the campaign and
 *   its tasks are soft-deleted. Ledger rows are never removed.
 */
class CampaignManagementService
{
    /** Fields an edit may change. */
    public const EDITABLE = ['title', 'objective', 'description', 'instructions_markdown', 'platform', 'min_contributor_level'];

    public function __construct(
        protected WalletLedgerService $ledger = new WalletLedgerService()
    ) {}

    public static function editRules(): array
    {
        return [
            'title' => 'sometimes|string|max:255',
            'objective' => 'nullable|string|max:255',
            'description' => 'sometimes|string',
            'instructions_markdown' => 'sometimes|string',
            'platform' => 'nullable|string|max:64',
            'min_contributor_level' => 'sometimes|in:starter,explorer,trusted,pro,elite',
        ];
    }

    /**
     * @throws Exception when the campaign is closed
     */
    public function updateDetails(Campaign $campaign, array $data): Campaign
    {
        if (in_array($campaign->status, ['completed', 'cancelled'], true)) {
            throw new Exception("A {$campaign->status} campaign can no longer be edited.", 422);
        }

        $data = array_intersect_key($data, array_flip(self::EDITABLE));

        return DB::transaction(function () use ($campaign, $data) {
            $locked = Campaign::where('id', $campaign->id)->lockForUpdate()->firstOrFail();
            $oldTitle = $locked->title;

            $locked->update($data);

            // Keep the contributor-facing task pool in step: tasks that still
            // carry the campaign's title / platform follow the edit.
            if (array_key_exists('title', $data) && $data['title'] !== $oldTitle) {
                $locked->tasks()->where('title', $oldTitle)->update(['title' => $data['title']]);
            }
            if (array_key_exists('platform', $data)) {
                $locked->tasks()->update(['platform' => $data['platform']]);
            }

            return $locked->fresh();
        });
    }

    /** True once any contributor reserved, started or submitted a task. */
    public function hasContributorActivity(Campaign $campaign): bool
    {
        $taskIds = $campaign->tasks()->withTrashed()->pluck('id');

        if ((int) $campaign->completed_contributors_count > 0) {
            return true;
        }

        return $taskIds->isNotEmpty() && (
            TaskSubmission::whereIn('task_id', $taskIds)->exists()
            || TaskAssignment::whereIn('task_id', $taskIds)->exists()
        );
    }

    /**
     * Release outstanding escrow, then soft-delete the campaign and its tasks.
     *
     * @return int cents released back to the business wallet
     * @throws Exception when contributors have touched the campaign
     */
    public function deleteSafely(Campaign $campaign): int
    {
        if ($this->hasContributorActivity($campaign)) {
            throw new Exception(
                'Contributors have already worked on this campaign, so it cannot be deleted. Cancel it instead — its history is preserved.',
                422
            );
        }

        return DB::transaction(function () use ($campaign) {
            $locked = Campaign::where('id', $campaign->id)->lockForUpdate()->firstOrFail();

            $released = $this->releaseUnspentEscrow($locked, 'deleted');

            $locked->tasks()->delete();
            $locked->delete();

            return $released;
        });
    }

    /**
     * Return the unspent rewards budget to the business wallet's available
     * balance. The releasable amount is derived from THIS campaign's own
     * escrow ledger rows (launch + top-up holds, minus settlements,
     * restores and prior releases) — never from the wallet's aggregate
     * pending balance, which may hold other campaigns' escrow, retention
     * holds and in-flight withdrawals. Call inside a transaction.
     *
     * @return int cents released
     */
    public function releaseUnspentEscrow(Campaign $campaign, string $reason = 'cancelled'): int
    {
        $business = $campaign->business()->first();

        if (!$business) {
            return 0;
        }

        $wallet = Wallet::where('user_id', $business->owner_id)->lockForUpdate()->first();

        if (!$wallet) {
            return 0;
        }

        $releasable = min((int) $wallet->pending_balance_cents, $this->campaignOutstandingEscrow($wallet, $campaign));

        if ($releasable > 0) {
            $this->ledger->releaseHold(
                $wallet,
                $releasable,
                'campaign_funding',
                "Escrow released — campaign {$reason}: {$campaign->title}",
                Campaign::class,
                $campaign->id
            );
        }

        return max(0, $releasable);
    }

    /**
     * This campaign's outstanding escrow in cents: launch/top-up holds,
     * minus reward settlements, plus reversal restores, minus prior
     * releases. All derived from the campaign's own ledger rows.
     */
    public function campaignOutstandingEscrow(Wallet $wallet, Campaign $campaign): int
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
