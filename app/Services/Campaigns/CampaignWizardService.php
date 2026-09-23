<?php

namespace App\Services\Campaigns;

use App\Models\Business;
use App\Models\Campaign;
use App\Models\Task;
use App\Models\Wallet;
use App\Services\TaskTypes\RewardBandService;
use App\Services\Wallet\WalletLedgerService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 9: campaign wizard API.
 *
 * draft -> (preview) -> launch, with the launch step performing an ATOMIC
 * budget reservation through the Phase-1 escrow funding gate:
 *
 * - preview(): honest budget math, no persistence —
 *   total = reward × contributors + platform_fee_percent% platform fee.
 * - createDraft(): persists a 'draft' campaign — NO money moves.
 * - launch(): locks the campaign row and the owner's wallet, re-checks the
 *   funding gate inside one transaction, escrow-holds the rewards budget,
 *   debits the platform fee, creates the task pool, and flips draft->active.
 *   A concurrent second launch finds status != draft and gets a 409, so the
 *   budget can never be reserved twice.
 */
class CampaignWizardService
{
    public function __construct(
        protected WalletLedgerService $ledger = new WalletLedgerService(),
        protected RewardBandService $bands = new RewardBandService()
    ) {}

    /**
     * Honest budget preview. No persistence, no side effects.
     *
     * @param array{reward_cents: int, contributors: int} $params
     * @return array{reward_cents: int, contributors: int, tasks_budget_cents: int, platform_fee_percent: float, platform_fee_cents: int, total_budget_cents: int}
     */
    public function preview(array $params): array
    {
        $reward = (int) ($params['reward_cents'] ?? 0);
        $contributors = (int) ($params['contributors'] ?? 0);

        if ($reward <= 0 || $contributors <= 0) {
            throw new Exception('Preview needs a positive reward and contributor count.');
        }

        $tasksBudget = $reward * $contributors;
        $feePercent = (float) config('platform.platformFeePercent', 15);
        $platformFee = (int) round($tasksBudget * ($feePercent / 100));

        return [
            'reward_cents' => $reward,
            'contributors' => $contributors,
            'tasks_budget_cents' => $tasksBudget,
            'platform_fee_percent' => $feePercent == (int) $feePercent ? (int) $feePercent : $feePercent,
            'platform_fee_cents' => $platformFee,
            'total_due_cents' => $tasksBudget + $platformFee,
        ];
    }

    /**
     * Persist a draft campaign. No money moves until launch().
     */
    public function createDraft(Business $business, array $data): Campaign
    {
        $type = $this->bands->resolveType($data['task_type_key']);
        $this->bands->assertWithinBand($type, (int) $data['reward_cents']);

        $preview = $this->preview([
            'reward_cents' => (int) $data['reward_cents'],
            'contributors' => (int) $data['contributors'],
        ]);

        $campaign = Campaign::create([
            'uuid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'category_id' => $data['category_id'],
            'platform' => $data['platform'] ?? null,
            'title' => $data['title'],
            'objective' => $data['objective'] ?? null,
            'description' => $data['description'] ?? $data['title'],
            'instructions_markdown' => $data['instructions'] ?? '',
            'proof_requirements_json' => $data['proof_requirements'] ?? $type->proof_required_json,
            'status' => 'draft',
            'total_budget_cents' => $preview['total_due_cents'],
            'remaining_budget_cents' => $preview['tasks_budget_cents'],
            'reserved_budget_cents' => 0,
            'reward_per_task_cents' => $preview['reward_cents'],
            'platform_fee_cents' => $preview['platform_fee_cents'],
            'target_contributors_count' => $preview['contributors'],
            'target_countries_json' => $data['countries'] ?? ['ALL'],
            'target_languages_json' => $data['languages'] ?? ['en'],
            'min_contributor_level' => $data['min_contributor_level'] ?? 'starter',
            'retention_hours' => (int) ($type->retention_period_days ?? 0) * 24,
            'starts_at' => now(),
        ]);

        // Stash the wizard answers on the draft so launch() can materialize
        // the task pool from them (no schema change needed).
        $this->stashWizardAnswers($campaign, [
            'task_type_id' => $type->id,
            'task_type_key' => $type->key,
            'task_title' => $data['task_title'] ?? null,
            'platform' => $data['platform'] ?? null,
            'country_code' => $data['country_code'] ?? null,
            'instructions' => $data['instructions'] ?? null,
            'proof_required' => $data['proof_requirements'] ?? $type->proof_required_json,
            'retention_days' => $data['retention_days'] ?? $type->retention_period_days,
            'estimated_minutes' => $data['estimated_minutes'] ?? 5,
            'difficulty' => $data['difficulty'] ?? 'easy',
        ]);

        return $campaign->fresh();
    }

    /**
     * Round 3: update a saved draft in place (resume-editing). Re-runs the
     * type band check and budget math, refreshes the row and the stashed
     * wizard answers. No money moves.
     */
    public function updateDraft(Campaign $campaign, array $data): Campaign
    {
        $type = $this->bands->resolveType($data['task_type_key']);
        $this->bands->assertWithinBand($type, (int) $data['reward_cents']);

        $preview = $this->preview([
            'reward_cents' => (int) $data['reward_cents'],
            'contributors' => (int) $data['contributors'],
        ]);

        $campaign->update([
            'category_id' => $data['category_id'],
            'platform' => $data['platform'] ?? null,
            'title' => $data['title'],
            'objective' => $data['objective'] ?? null,
            'description' => $data['description'] ?? $data['title'],
            'instructions_markdown' => $data['instructions'] ?? '',
            'proof_requirements_json' => $data['proof_requirements'] ?? $type->proof_required_json,
            'total_budget_cents' => $preview['total_due_cents'],
            'remaining_budget_cents' => $preview['tasks_budget_cents'],
            'reward_per_task_cents' => $preview['reward_cents'],
            'platform_fee_cents' => $preview['platform_fee_cents'],
            'target_contributors_count' => $preview['contributors'],
            'target_countries_json' => $data['countries'] ?? ['ALL'],
            'target_languages_json' => $data['languages'] ?? ['en'],
            'min_contributor_level' => $data['min_contributor_level'] ?? 'starter',
            'retention_hours' => (int) ($data['retention_days'] ?? $type->retention_period_days ?? 0) * 24,
        ]);

        $this->stashWizardAnswers($campaign, [
            'task_type_id' => $type->id,
            'task_type_key' => $type->key,
            'task_title' => $data['task_title'] ?? null,
            'platform' => $data['platform'] ?? null,
            'country_code' => $data['country_code'] ?? null,
            'instructions' => $data['instructions'] ?? null,
            'proof_required' => $data['proof_requirements'] ?? $type->proof_required_json,
            'retention_days' => $data['retention_days'] ?? $type->retention_period_days,
            'estimated_minutes' => $data['estimated_minutes'] ?? 5,
            'difficulty' => $data['difficulty'] ?? 'easy',
        ]);

        return $campaign->fresh();
    }

    /**
     * Atomically fund and launch a draft campaign.
     *
     * @throws Exception with a 409-style message when already launched, or
     *                   the funding gate's insufficient-funds message.
     */
    public function launch(Campaign $campaign): Campaign
    {
        return DB::transaction(function () use ($campaign) {
            $locked = Campaign::where('id', $campaign->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'draft') {
                throw new Exception("Campaign is already {$locked->status} — it cannot be launched twice.", 409);
            }

            $business = $locked->business()->firstOrFail();

            $ownerWallet = Wallet::firstOrCreate(
                ['user_id' => $business->owner_id],
                ['currency' => 'USD', 'available_balance_cents' => 0]
            );

            $tasksBudget = (int) $locked->remaining_budget_cents;
            $platformFee = (int) $locked->platform_fee_cents;

            // FUNDING GATE (Phase 1, reused): rewards are escrow-held,
            // the platform fee is debited. The hold is the atomic authority —
            // a lost race against a concurrent launch or spend surfaces as
            // insufficient funds and rolls everything back.
            $this->ledger->hold(
                $ownerWallet,
                $tasksBudget,
                'campaign_funding',
                "Escrow hold — campaign rewards: {$locked->title}",
                Campaign::class,
                $locked->id
            );

            if ($platformFee > 0) {
                $this->ledger->debit(
                    $ownerWallet,
                    $platformFee,
                    'campaign_funding',
                    "Platform fee — campaign launch: {$locked->title}",
                    Campaign::class,
                    $locked->id,
                    ['is_platform_fee' => true]
                );
            }

            // Materialize the task pool from the draft's wizard answers.
            $wizard = $this->draftWizardAnswers($locked);

            Task::create([
                'uuid' => (string) Str::uuid(),
                'campaign_id' => $locked->id,
                'category_id' => $locked->category_id,
                'task_type_id' => $wizard['task_type_id'],
                'title' => $wizard['task_title'] ?? $locked->title,
                'platform' => $locked->platform ?? $wizard['platform'],
                'country_code' => $wizard['country_code'],
                'instructions' => $wizard['instructions'],
                'proof_required_json' => $wizard['proof_required'],
                'retention_days' => $wizard['retention_days'],
                'company_name' => $business->company_name,
                'reward_cents' => (int) $locked->reward_per_task_cents,
                'estimated_minutes' => $wizard['estimated_minutes'] ?? 5,
                'difficulty' => $wizard['difficulty'] ?? 'easy',
                'status' => 'available',
                'slots_total' => (int) $locked->target_contributors_count,
                'slots_taken' => 0,
            ]);

            // Priority 4 — admin approval gate: funded campaigns park in
            // `pending_review`. Tasks are materialized but stay invisible
            // until staff approves the campaign to `active` (the
            // contributor feed only lists tasks of active campaigns).
            $locked->update(['status' => 'pending_review']);

            return $locked->fresh();
        });
    }

    /**
     * The wizard answers travel with the draft. They are stored in
     * proof_requirements_json under a reserved `wizard` key so no schema
     * change is needed for the draft payload.
     */
    public function stashWizardAnswers(Campaign $campaign, array $answers): void
    {
        $proof = $campaign->proof_requirements_json ?? [];
        $proof['wizard'] = $answers;
        $campaign->update(['proof_requirements_json' => $proof]);
    }

    /**
     * @return array{task_type_id: ?int, task_title: ?string, platform: ?string, country_code: ?string, instructions: ?string, proof_required: ?array, retention_days: ?int, estimated_minutes: ?int, difficulty: ?string}
     */
    protected function draftWizardAnswers(Campaign $campaign): array
    {
        $wizard = ($campaign->proof_requirements_json ?? [])['wizard'] ?? [];

        return [
            'task_type_id' => $wizard['task_type_id'] ?? null,
            'task_title' => $wizard['task_title'] ?? null,
            'platform' => $wizard['platform'] ?? null,
            'country_code' => $wizard['country_code'] ?? null,
            'instructions' => $wizard['instructions'] ?? $campaign->instructions_markdown,
            'proof_required' => $wizard['proof_required'] ?? null,
            'retention_days' => $wizard['retention_days'] ?? null,
            'estimated_minutes' => $wizard['estimated_minutes'] ?? 5,
            'difficulty' => $wizard['difficulty'] ?? 'easy',
        ];
    }
}
