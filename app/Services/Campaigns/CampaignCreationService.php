<?php

namespace App\Services\Campaigns;

use App\Models\Business;
use App\Models\Campaign;
use App\Models\Task;
use App\Models\Wallet;
use App\Services\Idempotency\IdempotencyService;
use App\Services\TaskTypes\RewardBandService;
use App\Services\TaskTypes\RewardBandViolationException;
use App\Services\Wallet\WalletLedgerService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shared campaign creation used by both the business portal
 * (BusinessCampaignController@store) and staff/admin
 * (StaffCampaignController@store).
 *
 * Money-safety is identical for both callers (P0 funding gate):
 * - the reward must sit inside the task type's band (honest 422 otherwise)
 * - the TARGET business owner's wallet must cover rewards + platform fee
 *   before anything persists; escrow hold + fee debit happen atomically
 * - idempotent: a retried create with the same key + identical parameters
 *   returns the original campaign instead of double-charging escrow
 *
 * Funded campaigns park in `pending_review` until staff approves them.
 */
class CampaignCreationService
{
    public function __construct(
        protected WalletLedgerService $ledger = new WalletLedgerService(),
        protected RewardBandService $bands = new RewardBandService()
    ) {}

    /**
     * @param array $validated validated campaign fields (same shape as the
     *                         business campaign store validator)
     * @throws RewardBandViolationException
     * @throws InsufficientCampaignFundsException
     */
    public function create(
        Business $business,
        array $validated,
        int $actorUserId,
        ?string $idempotencyKey = null
    ): Campaign {
        // Phase 4/12: every campaign task carries a type contract — the
        // reward must sit inside the type's band, otherwise an honest 422
        // names the band. There is no untyped bypass.
        $type = $this->bands->resolveType($validated['task_type_key']);
        $this->bands->assertWithinBand($type, (int) $validated['reward_per_task_cents']);

        // Calculate budget & platform fee
        $rewardPerTask = (int) $validated['reward_per_task_cents'];
        $contributorCount = (int) $validated['target_contributors_count'];
        $tasksBudget = $rewardPerTask * $contributorCount;
        $feePercent = config('platform.platformFeePercent', 15);
        $platformFee = (int) round($tasksBudget * ($feePercent / 100));
        $totalBudget = $tasksBudget + $platformFee;

        // FUNDING GATE PRE-CHECK (P0): fail fast with an honest error before
        // touching the database. The atomic hold() inside the transaction
        // below remains the final authority against concurrent races.
        $ownerWallet = Wallet::firstOrCreate(
            ['user_id' => $business->owner_id],
            ['currency' => 'USD', 'available_balance_cents' => 0]
        );

        if ((int) $ownerWallet->available_balance_cents < $totalBudget) {
            throw new InsufficientCampaignFundsException(
                (int) $ownerWallet->available_balance_cents,
                $totalBudget
            );
        }

        try {
            // Idempotent: a retried create (double-click / network retry) with
            // the same key + identical parameters returns the original
            // campaign instead of creating a second one and double-charging
            // the escrow hold.
            return app(IdempotencyService::class)->run(
                $idempotencyKey,
                'campaign.create',
                $actorUserId,
                ['business_id' => $business->id, 'payload_hash' => hash('sha256', json_encode($validated))],
                function () use ($business, $validated, $rewardPerTask, $contributorCount, $tasksBudget, $totalBudget, $platformFee, $type, $actorUserId) {
                    return DB::transaction(function () use ($business, $validated, $rewardPerTask, $contributorCount, $tasksBudget, $totalBudget, $platformFee, $type, $actorUserId) {
                        // Re-lock the wallet inside the transaction: the
                        // pre-check above is advisory; this is authoritative.
                        $lockedWallet = Wallet::where('user_id', $business->owner_id)->lockForUpdate()->firstOrFail();

                        $camp = Campaign::create([
                            'uuid' => (string) Str::uuid(),
                            'business_id' => $business->id,
                            'category_id' => $validated['category_id'],
                            'platform' => $validated['platform'] ?? null,
                            'target_url' => $validated['target_url'] ?? null,
                            'title' => $validated['title'],
                            'objective' => $validated['objective'] ?? null,
                            'description' => $validated['description'],
                            'instructions_markdown' => $validated['instructions_markdown'],
                            // Proof contract is stored as a LIST of requirement names
                            // (e.g. ['screenshot', 'url']) — the format the fraud
                            // screens enforce in FraudAnalysisService::screenProofOrReject.
                            'proof_requirements_json' => $validated['proof_requirements_json'] ?? ['screenshot', 'url'],
                            'status' => 'draft', // goes active only after the funding gate below
                            'total_budget_cents' => $totalBudget,
                            'remaining_budget_cents' => $tasksBudget, // rewards pool only; the platform fee is taken at launch
                            'reserved_budget_cents' => 0,
                            'reward_per_task_cents' => $rewardPerTask,
                            'platform_fee_cents' => $platformFee,
                            'target_contributors_count' => $contributorCount,
                            'target_countries_json' => $validated['target_countries'] ?? ['ALL'],
                            'target_languages_json' => $validated['target_languages'] ?? ['en'],
                            'min_contributor_level' => $validated['min_contributor_level'] ?? 'starter',
                            'retention_hours' => $validated['retention_hours'] ?? 24,
                            'starts_at' => now(),
                        ]);

                        // FUNDING GATE (P0): the business wallet must cover the campaign
                        // budget before the campaign goes active. Rewards are escrow-held
                        // (available -> pending); the platform fee is debited immediately
                        // and is non-refundable. Insufficient funds abort the launch and
                        // the whole transaction (including the draft row) is rolled back,
                        // so a campaign can never sit 'active' with zero backing.
                        $this->ledger->hold(
                            $lockedWallet,
                            $tasksBudget,
                            'campaign_funding',
                            "Escrow hold — campaign rewards: {$camp->title}",
                            Campaign::class,
                            $camp->id
                        );

                        if ($platformFee > 0) {
                            $this->ledger->debit(
                                $lockedWallet,
                                $platformFee,
                                'campaign_funding',
                                "Platform fee — campaign launch: {$camp->title}",
                                Campaign::class,
                                $camp->id,
                                ['is_platform_fee' => true]
                            );
                        }

                        // Create initial active Task pool — carries the type contract
                        // (band-validated above): proof requirements and retention.
                        Task::create([
                            'uuid' => (string) Str::uuid(),
                            'campaign_id' => $camp->id,
                            'category_id' => $camp->category_id,
                            'task_type_id' => $type->id,
                            'platform' => $camp->platform,
                            'title' => $camp->title,
                            'reward_cents' => $rewardPerTask,
                            'proof_required_json' => $type->proof_required_json,
                            'retention_days' => $type->retention_period_days,
                            'estimated_minutes' => 5,
                            'difficulty' => 'easy',
                            'status' => 'available',
                            'slots_total' => $contributorCount,
                            'slots_taken' => 0,
                        ]);

                        // Priority 4 — approval gate: funded campaigns park in
                        // pending_review until staff approves them to active.
                        // Staff-created campaigns (admin/superadmin) skip the
                        // queue — they are trusted and go live immediately.
                        $actor = \App\Models\User::find($actorUserId);
                        $isStaff = $actor && in_array($actor->role, ['admin', 'superadmin', 'moderator'], true);
                        $camp->update(['status' => $isStaff ? 'active' : 'pending_review']);

                        return $camp;
                    });
                }
            );
        } catch (Exception $e) {
            // A lost race against the funding gate surfaces here: map it to
            // the same honest exception as the pre-check instead of a
            // generic error.
            if ($e instanceof InsufficientCampaignFundsException) {
                throw $e;
            }
            if (
                str_contains($e->getMessage(), 'Insufficient available balance')
                || str_contains($e->getMessage(), 'Insufficient wallet balance')
            ) {
                $fresh = Wallet::where('user_id', $business->owner_id)->first();
                throw new InsufficientCampaignFundsException(
                    $fresh ? (int) $fresh->available_balance_cents : 0,
                    $totalBudget
                );
            }
            throw $e;
        }
    }
}
