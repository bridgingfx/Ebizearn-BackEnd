<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Campaign;
use App\Models\Task;
use App\Models\TaskSubmission;
use App\Models\Wallet;
use App\Services\Idempotency\IdempotencyService;
use App\Services\TaskTypes\RewardBandService;
use App\Services\TaskTypes\RewardBandViolationException;
use App\Services\Wallet\WalletLedgerService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BusinessCampaignController extends Controller
{
    public function __construct(
        protected WalletLedgerService $ledger = new WalletLedgerService()
    ) {}

    /**
     * Get Business Dashboard Overview.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $business = $user->business;

        if (!$business) {
            return response()->json([
                'success' => false,
                'message' => 'No business account associated with this user.',
            ], 404);
        }

        $campaigns = Campaign::where('business_id', $business->id)->get();

        $activeCount = $campaigns->where('status', 'active')->count();
        $totalBudget = $campaigns->sum('total_budget_cents');
        $remainingBudget = $campaigns->sum('remaining_budget_cents');
        $spentBudget = $totalBudget - $remainingBudget;
        $verifiedTasks = $campaigns->sum('completed_contributors_count');
        $avgCostCents = $verifiedTasks > 0 ? (int) round($spentBudget / $verifiedTasks) : 0;

        // Recent submissions for this business
        $recentSubmissions = TaskSubmission::whereHas('task.campaign', function ($q) use ($business) {
            $q->where('business_id', $business->id);
        })->with(['task', 'user.profile', 'aiResult'])->latest()->take(6)->get();

        return response()->json([
            'success' => true,
            'data' => [
                'business' => $business,
                'metrics' => [
                    'active_campaigns' => $activeCount,
                    'total_campaigns' => $campaigns->count(),
                    'verified_tasks' => $verifiedTasks,
                    'total_budget_cents' => $totalBudget,
                    'spent_budget_cents' => $spentBudget,
                    'remaining_budget_cents' => $remainingBudget,
                    'average_cost_cents' => $avgCostCents,
                ],
                'recent_submissions' => $recentSubmissions,
                'active_campaigns_list' => $campaigns->where('status', 'active')->values(),
            ],
        ]);
    }

    /**
     * List all campaigns for the authenticated business.
     */
    public function index(Request $request): JsonResponse
    {
        $business = $request->user()->business;
        if (!$business) {
            return response()->json(['success' => false, 'message' => 'Business profile not found.'], 404);
        }

        $campaigns = Campaign::with(['category', 'tasks'])
            ->where('business_id', $business->id)
            ->latest()
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $campaigns->items(),
            'meta' => [
                'current_page' => $campaigns->currentPage(),
                'last_page' => $campaigns->lastPage(),
                'total' => $campaigns->total(),
            ],
        ]);
    }

    /**
     * Create a new campaign from the 6-step wizard.
     */
    public function store(Request $request): JsonResponse
    {
        $business = $request->user()->business;
        if (!$business) {
            return response()->json(['success' => false, 'message' => 'Business profile not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'objective' => 'nullable|string|max:255',
            'description' => 'required|string',
            'category_id' => 'required|exists:task_categories,id',
            'platform' => 'nullable|string|max:64',
            'reward_per_task_cents' => 'required|integer|min:20', // Min $0.20
            'task_type_key' => 'required|string|exists:task_types,key',
            'target_contributors_count' => 'required|integer|min:5',
            'instructions_markdown' => 'required|string',
            'proof_requirements_json' => 'nullable|array',
            'target_countries' => 'nullable|array',
            'target_languages' => 'nullable|array',
            'min_contributor_level' => 'nullable|in:starter,explorer,trusted,pro,elite',
            'retention_hours' => 'nullable|integer|min:0',
            'idempotency_key' => 'nullable|string|max:128',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Phase 4/12: every campaign task carries a type contract — the
        // reward must sit inside the type's band, otherwise an honest 422
        // names the band. There is no untyped bypass.
        try {
            $bands = new RewardBandService();
            $type = $bands->resolveType($request->input('task_type_key'));
            $bands->assertWithinBand($type, (int) $request->input('reward_per_task_cents'));
        } catch (RewardBandViolationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $validated = $validator->validated();

        // Calculate budget & platform fee
        $rewardPerTask = (int) $validated['reward_per_task_cents'];
        $contributorCount = (int) $validated['target_contributors_count'];
        $tasksBudget = $rewardPerTask * $contributorCount;
        $feePercent = config('platform.platformFeePercent', 15);
        $platformFee = (int) round($tasksBudget * ($feePercent / 100));
        $totalBudget = $tasksBudget + $platformFee;

        // FUNDING GATE PRE-CHECK (P0): fail fast with an honest 422 before
        // touching the database. The atomic hold() inside the transaction
        // below remains the final authority against concurrent races.
        $ownerWallet = Wallet::firstOrCreate(
            ['user_id' => $business->owner_id],
            ['currency' => 'USD', 'available_balance_cents' => 0]
        );

        if ((int) $ownerWallet->available_balance_cents < $totalBudget) {
            return $this->insufficientFundingResponse(
                (int) $ownerWallet->available_balance_cents,
                $totalBudget
            );
        }

        try {
            // Idempotent: a retried create (double-click / network retry) with
            // the same key + identical parameters returns the original
            // campaign instead of creating a second one and double-charging
            // the escrow hold.
            $campaign = app(IdempotencyService::class)->run(
                IdempotencyService::keyFromRequest($request),
                'campaign.create',
                $request->user()->id,
                ['business_id' => $business->id, 'payload_hash' => hash('sha256', json_encode($validated))],
                function () use ($business, $validated, $rewardPerTask, $contributorCount, $tasksBudget, $totalBudget, $platformFee, $ownerWallet, $type) {
                    return DB::transaction(function () use ($business, $validated, $rewardPerTask, $contributorCount, $tasksBudget, $totalBudget, $platformFee, $ownerWallet, $type) {
                $camp = Campaign::create([
                    'uuid' => (string) Str::uuid(),
                    'business_id' => $business->id,
                    'category_id' => $validated['category_id'],
                    'platform' => $validated['platform'] ?? null,
                    'title' => $validated['title'],
                    'objective' => $validated['objective'] ?? null,
                    'description' => $validated['description'],
                    'instructions_markdown' => $validated['instructions_markdown'],
                    'proof_requirements_json' => $validated['proof_requirements_json'] ?? ['screenshot' => true, 'url' => true],
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
                    $ownerWallet,
                    $tasksBudget,
                    'campaign_funding',
                    "Escrow hold — campaign rewards: {$camp->title}",
                    Campaign::class,
                    $camp->id
                );

                if ($platformFee > 0) {
                    $this->ledger->debit(
                        $ownerWallet,
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
                $camp->update(['status' => 'pending_review']);

                return $camp;
                });
            }
        );
        } catch (Exception $e) {
            // A lost race against the funding gate surfaces here: map it to
            // the same honest 422 as the pre-check instead of a generic 400.
            if ($this->isInsufficientFundsError($e)) {
                return $this->insufficientFundingResponse(
                    (int) $ownerWallet->fresh()->available_balance_cents,
                    $totalBudget
                );
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => $campaign->wasRecentlyCreated
                ? 'Campaign funded and launched successfully.'
                : 'Campaign already created — returning the existing record.',
            'data' => $campaign->load(['category', 'tasks']),
        ], $campaign->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Honest 422 for the funding gate: names the funded balance the business
     * has and what the campaign needs, in plain dollars.
     */
    protected function insufficientFundingResponse(int $availableCents, int $requiredCents): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Insufficient funded balance. This needs '
                . '$' . number_format($requiredCents / 100, 2) . ' USD, but the business wallet only has '
                . '$' . number_format($availableCents / 100, 2) . ' USD available. '
                . 'Add funds to the wallet and try again.',
        ], 422);
    }

    /**
     * True when the ledger service rejected a hold/debit for lack of funds.
     */
    protected function isInsufficientFundsError(Exception $e): bool
    {
        return str_contains($e->getMessage(), 'Insufficient available balance')
            || str_contains($e->getMessage(), 'Insufficient wallet balance');
    }

    /**
     * Top up a campaign's escrowed rewards budget from the business wallet.
     * Used for campaigns launched before the funding gate, or to extend a
     * campaign that exhausted its budget.
     */
    public function fund(Request $request, string $id): JsonResponse
    {
        $business = $request->user()->business;
        if (!$business) {
            return response()->json(['success' => false, 'message' => 'Business profile not found.'], 404);
        }

        $campaign = Campaign::where('business_id', $business->id)
            ->where(fn($q) => $q->where('id', $id)->orWhere('uuid', $id))
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'amount_cents' => 'required|integer|min:20',
            'idempotency_key' => 'nullable|string|max:128',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $amount = (int) $request->input('amount_cents');

        // Fail fast with an honest 422 when the wallet cannot cover the top-up.
        $ownerWallet = Wallet::firstOrCreate(
            ['user_id' => $business->owner_id],
            ['currency' => 'USD', 'available_balance_cents' => 0]
        );

        if ((int) $ownerWallet->available_balance_cents < $amount) {
            return $this->insufficientFundingResponse(
                (int) $ownerWallet->available_balance_cents,
                $amount
            );
        }

        try {
            // Idempotent: a retried top-up with the same key + same amount
            // returns the funded campaign instead of escrow-holding twice.
            $campaign = app(IdempotencyService::class)->run(
                IdempotencyService::keyFromRequest($request),
                'campaign.fund',
                $request->user()->id,
                ['campaign_id' => $campaign->id, 'amount_cents' => $amount],
                function () use ($campaign, $amount, $ownerWallet) {
                    return DB::transaction(function () use ($campaign, $amount, $ownerWallet) {
                        $locked = Campaign::where('id', $campaign->id)->lockForUpdate()->firstOrFail();

                        $this->ledger->hold(
                            $ownerWallet,
                            $amount,
                            'campaign_funding',
                            "Top-up escrow — campaign: {$locked->title}",
                            Campaign::class,
                            $locked->id
                        );

                        $locked->increment('remaining_budget_cents', $amount);
                        $locked->increment('total_budget_cents', $amount);

                        return $locked->fresh();
                    });
                }
            );
        } catch (Exception $e) {
            if ($this->isInsufficientFundsError($e)) {
                return $this->insufficientFundingResponse(
                    (int) $ownerWallet->fresh()->available_balance_cents,
                    $amount
                );
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Campaign funded successfully.',
            'data' => $campaign->load(['category', 'tasks']),
        ]);
    }

    /**
     * Get single campaign details with metrics.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        // Phase 13: load tenant-agnostically, then authorize explicitly so a
        // cross-business read is a 403 (not a 404 that hides behind scoping).
        $campaign = Campaign::with(['category', 'tasks'])
            ->where(fn($q) => $q->where('id', $id)->orWhere('uuid', $id))
            ->firstOrFail();

        Gate::authorize('view', $campaign);

        $submissions = TaskSubmission::whereHas('task', fn($q) => $q->where('campaign_id', $campaign->id))
            ->with(['user.profile', 'aiResult', 'files'])
            ->latest()
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => [
                'campaign' => $campaign,
                'submissions' => $submissions,
            ],
        ]);
    }

    /**
     * Toggle campaign status (pause/resume).
     */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $business = $request->user()->business;
        $campaign = Campaign::where('business_id', $business->id)
            ->where(fn($q) => $q->where('id', $id)->orWhere('uuid', $id))
            ->firstOrFail();

        $status = $request->input('status');
        if (!in_array($status, ['active', 'paused', 'cancelled', 'expired'], true)) {
            return response()->json(['success' => false, 'message' => 'Invalid status option.'], 422);
        }

        $isTerminalRefund = fn (string $s) => in_array($s, ['cancelled', 'expired'], true);

        try {
            DB::transaction(function () use ($business, $campaign, $status, $isTerminalRefund) {
                $locked = Campaign::where('id', $campaign->id)->lockForUpdate()->firstOrFail();

                // Cancelling OR expiring refunds the unspent escrowed rewards
                // budget back to the business wallet (platform fee stays earned).
                // The refund is capped at what is actually still held, so it can
                // never over-release.
                if ($isTerminalRefund($status) && !$isTerminalRefund($locked->status)) {
                    $refundable = (int) $locked->remaining_budget_cents + (int) $locked->reserved_budget_cents;

                    if ($refundable > 0) {
                        $ownerWallet = Wallet::firstOrCreate(
                            ['user_id' => $business->owner_id],
                            ['currency' => 'USD', 'available_balance_cents' => 0]
                        );

                        $heldWallet = Wallet::where('id', $ownerWallet->id)->lockForUpdate()->firstOrFail();
                        $release = min($refundable, (int) $heldWallet->pending_balance_cents);

                        if ($release > 0) {
                            $this->ledger->releaseHold(
                                $ownerWallet,
                                $release,
                                'campaign_refund',
                                "Escrow refund — campaign {$status}: {$locked->title}",
                                Campaign::class,
                                $locked->id,
                                ['unrefunded_shortfall_cents' => $refundable - $release]
                            );
                        }

                        $locked->update(['remaining_budget_cents' => 0, 'reserved_budget_cents' => 0]);
                    }
                }

                $locked->update(['status' => $status]);
            });

            $campaign->tasks()->update(['status' => $status === 'active' ? 'available' : 'paused']);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => "Campaign is now {$status}.",
            'data' => $campaign->fresh(),
        ]);
    }

    /**
     * Get all submissions across business campaigns.
     */
    public function submissions(Request $request): JsonResponse
    {
        $business = $request->user()->business;
        $submissions = TaskSubmission::whereHas('task.campaign', fn($q) => $q->where('business_id', $business->id))
            ->with(['task.category', 'user.profile', 'files', 'aiResult', 'businessReviewer:id,name', 'reviewer:id,name'])
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $submissions->items(),
            'meta' => [
                'current_page' => $submissions->currentPage(),
                'last_page' => $submissions->lastPage(),
                'total' => $submissions->total(),
            ],
        ]);
    }

    /**
     * POST /business/submissions/{id}/decision { decision: approve|reject, reason? }
     *
     * First step of the two-step review: the business approves or rejects a
     * proof on its own campaign. No money moves here — staff confirm the
     * final decision in the Verification Center, which credits the
     * contributor. The business may change its mind until staff decide.
     */
    public function reviewSubmission(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'decision' => 'required|in:approve,reject',
            'reason' => 'required_if:decision,reject|nullable|string|min:3|max:1000',
        ], [
            'reason.required_if' => 'Tell the contributor why the proof is rejected.',
        ]);

        $business = $request->user()->business;
        abort_unless($business, 403, 'Business profile not found.');

        $submission = TaskSubmission::where(fn ($q) => $q->where('id', $id)->orWhere('uuid', $id))
            ->whereHas('task.campaign', fn ($q) => $q->where('business_id', $business->id))
            ->firstOrFail();

        abort_if(in_array($submission->status, ['approved', 'rejected'], true), 422,
            'This proof has already been ' . $submission->status . ' by the eBizEarn review team.');

        $approve = $data['decision'] === 'approve';
        $before = ['business_decision' => $submission->business_decision];

        $submission->update([
            'business_decision' => $approve ? 'approved' : 'rejected',
            'business_reason' => $data['reason'] ?? null,
            'business_reviewer_id' => $request->user()->id,
            'business_reviewed_at' => now(),
        ]);

        \App\Services\Audit\AuditLogger::log(
            $request->user(),
            $approve ? 'submission.business_approved' : 'submission.business_rejected',
            TaskSubmission::class,
            $submission->id,
            ['task_id' => $submission->task_id, 'contributor_id' => $submission->user_id],
            $before,
            ['business_decision' => $submission->business_decision, 'reason' => $submission->business_reason],
        );

        return response()->json([
            'success' => true,
            'message' => $approve
                ? 'Proof approved. Our review team will confirm it and release the payment to the contributor.'
                : 'Proof rejected. Our review team will confirm the rejection.',
            'data' => $submission->fresh()->load(['task.category', 'user.profile', 'files', 'businessReviewer:id,name', 'reviewer:id,name']),
        ]);
    }

    /**
     * Priority 4 — campaign branding: upload a company logo for a campaign.
     * Validated image, stored on the public disk, path persisted. Only the
     * owning business (or staff) may set it.
     */
    public function uploadLogo(Request $request, string $id): JsonResponse
    {
        $campaign = Campaign::where(fn ($q) => $q->where('id', $id)->orWhere('uuid', $id))->firstOrFail();
        Gate::authorize('update', $campaign);

        $validator = Validator::make($request->all(), [
            'logo' => 'required|image|mimes:png,jpg,jpeg,webp,svg|max:2048',
            'company_name' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $path = $request->file('logo')->store('campaign-logos', 'public');

        $campaign->update([
            'logo_path' => $path,
            'company_name' => $request->input('company_name', $campaign->company_name),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Campaign logo uploaded.',
            'data' => [
                'logo_path' => $path,
                'logo_url' => Storage::disk('public')->url($path),
                'company_name' => $campaign->company_name,
            ],
        ]);
    }
}
