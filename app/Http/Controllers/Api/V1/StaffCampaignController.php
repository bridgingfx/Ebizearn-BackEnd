<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Campaign;
use App\Models\TaskSubmission;
use App\Services\Audit\AuditLogger;
use App\Services\Campaigns\CampaignCreationService;
use App\Services\Staff\StaffScope;
use App\Services\Campaigns\CampaignManagementService;
use App\Services\Campaigns\InsufficientCampaignFundsException;
use App\Services\Idempotency\IdempotencyService;
use App\Services\TaskTypes\RewardBandViolationException;
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
 * - edit (edit_campaigns) changes copy / targeting only, never money
 * - delete (delete_campaigns) is refused once contributors have worked on
 *   the campaign; otherwise its escrow is released before deletion
 */
class StaffCampaignController extends Controller
{
    public function __construct(
        protected CampaignManagementService $campaigns = new CampaignManagementService()
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Campaign::with(['business.owner', 'category', 'creator:id,name,role,business_owner_id'])
            ->withCount('tasks')
            ->latest();
        StaffScope::applyToCampaigns($query, $request->user());

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
            'data' => $query->paginate(max(1, min(100, (int) $request->input('per_page', 20)))),
        ]);
    }

    /**
     * Active business accounts a campaign can be posted for, with the
     * owner wallet's available balance (the funding gate checks it).
     * Lets post_campaigns work without the broader manage_users permission.
     */
    public function businessOptions(): JsonResponse
    {
        $businesses = Business::with(['owner:id,name,email,status', 'owner.wallet:id,user_id,available_balance_cents'])
            ->whereHas('owner', fn ($q) => $q->where('role', 'business')->where('status', 'active'))
            ->when(StaffScope::userIds(request()->user()), fn ($q, $ids) => $q->whereIn('owner_id', $ids))
            ->orderBy('company_name')
            ->get()
            ->map(fn (Business $b) => [
                'id' => $b->id,
                'company_name' => $b->company_name,
                'owner_name' => $b->owner?->name,
                'available_balance_cents' => (int) ($b->owner?->wallet?->available_balance_cents ?? 0),
            ]);

        return response()->json(['success' => true, 'data' => $businesses]);
    }

    public function show(string $id): JsonResponse
    {
        try {
            $campaign = Campaign::whereKeyOrUuid($id)
                ->with(['business.owner', 'category', 'tasks.taskType', 'creator:id,name,role,business_owner_id'])
                ->withCount('tasks')
                ->firstOrFail();
            if (!StaffScope::allowsBusiness(request()->user(), $campaign->business_id)) {
                return StaffScope::notFound();
            }

            $spent = (int) TaskSubmission::whereIn('task_id', $campaign->tasks()->pluck('id'))
                ->where('status', 'approved')
                ->join('tasks', 'tasks.id', '=', 'task_submissions.task_id')
                ->sum('tasks.reward_cents');

            return response()->json([
                'success' => true,
                'data' => array_merge($campaign->toArray(), ['spent_cents' => $spent]),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Campaign not found.'], 404);
        } catch (\Throwable $e) {
            \Log::error('StaffCampaign show failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not load campaign details.'], 500);
        }
    }

    /**
     * Staff-created campaign (admin posting on behalf of a business).
     * Same creation pipeline as the business portal — reward band check,
     * P0 funding gate against the TARGET business owner's wallet, escrow
     * hold + fee debit, task pool, parked in pending_review for approval.
     * The admin only chooses the business; the money always comes from
     * that business's wallet, never from thin air.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|integer|exists:businesses,id',
            'title' => 'required|string|max:255',
            'objective' => 'nullable|string|max:255',
            'description' => 'required|string',
            'category_id' => 'required|exists:task_categories,id',
            'platform' => 'nullable|string|max:64',
            'reward_per_task_cents' => 'required|integer|min:1', // the task type's reward band is the real rule
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

        $business = Business::findOrFail($request->input('business_id'));
        if (!StaffScope::allowsUser($request->user(), (int) $business->owner_id)) {
            return StaffScope::notFound();
        }
        $actor = $request->user();

        try {
            $campaign = app(CampaignCreationService::class)->create(
                $business,
                $validator->validated(),
                $actor->id,
                IdempotencyService::keyFromRequest($request)
            );
        } catch (RewardBandViolationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (InsufficientCampaignFundsException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient funded balance. This needs '
                    . '$' . number_format($e->requiredCents / 100, 2) . ' USD, but the business wallet ('
                    . $business->company_name . ') only has '
                    . '$' . number_format($e->availableCents / 100, 2) . ' USD available. '
                    . 'Add funds to the wallet and try again.',
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }

        AuditLogger::log(
            $actor,
            'campaign.created_by_staff',
            Campaign::class,
            $campaign->id,
            ['business_id' => $business->id, 'title' => $campaign->title]
        );

        return response()->json([
            'success' => true,
            'message' => $campaign->wasRecentlyCreated
                ? 'Campaign created and funded successfully.'
                : 'Campaign already created — returning the existing record.',
            'data' => $campaign->load(['business', 'category', 'tasks']),
        ], $campaign->wasRecentlyCreated ? 201 : 200);
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

        $campaign = Campaign::whereKeyOrUuid($id)->firstOrFail();
        if (!StaffScope::allowsBusiness($request->user(), $campaign->business_id)) {
            return StaffScope::notFound();
        }
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
                    $this->campaigns->releaseUnspentEscrow($locked);
                    // No 'cancelled' state on tasks — pausing the pool stops
                    // new assignments; the cancelled campaign is the truth.
                    $locked->tasks()->where('status', 'available')->update(['status' => 'paused']);
                }

                $locked->update(['status' => $target]);

                // Approving a campaign in review also approves its post
                // content (staff see it on the campaign before approving).
                if ($campaign->status === 'pending_review' && $target === 'active' && $locked->content_mode && $locked->content_status === 'pending') {
                    $locked->forceFill([
                        'content_status' => 'approved',
                        'content_reviewed_by' => $request->user()->id,
                        'content_reviewed_at' => now(),
                        'content_review_note' => null,
                    ])->save();
                }

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
     * PATCH /staff/campaigns/{id}/content (edit_campaigns) — staff override
     * of the post content: mode (manual / auto / none) and text. Text staff
     * write is checked for safety and approved by them.
     */
    public function updateContent(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'content_mode' => 'nullable|in:manual,auto',
            'generated_content' => 'nullable|string|max:2000',
            'content_brief' => 'nullable|string|max:500',
        ]);

        $campaign = Campaign::whereKeyOrUuid($id)->firstOrFail();
        if (!StaffScope::allowsBusiness($request->user(), $campaign->business_id)) {
            return StaffScope::notFound();
        }

        $mode = $data['content_mode'] ?? null;
        $text = trim((string) ($data['generated_content'] ?? ''));
        if ($mode && $text === '') {
            return response()->json(['success' => false, 'message' => 'Add the approved post text (the base for auto mode).', 'errors' => ['generated_content' => ['Required.']]], 422);
        }

        $safety = app(\App\Services\AI\ContentSafety::class);
        foreach (['generated_content' => $text, 'content_brief' => $data['content_brief'] ?? null] as $field => $value) {
            $check = $safety->check($value);
            if (!$check['ok']) {
                $message = "{$check['reason']} Please edit it.";

                return response()->json(['success' => false, 'message' => $message, 'errors' => [$field => [$message]]], 422);
            }
        }

        $before = $campaign->only(['content_mode', 'content_status', 'generated_content']);
        $campaign->forceFill([
            'content_mode' => $mode,
            'generated_content' => $mode ? $text : $campaign->generated_content,
            'content_brief' => array_key_exists('content_brief', $data) ? ($data['content_brief'] ?: null) : $campaign->content_brief,
            'content_status' => $mode ? 'approved' : null,
            'content_reviewed_by' => $mode ? $request->user()->id : null,
            'content_reviewed_at' => $mode ? now() : null,
            'content_review_note' => null,
        ])->save();

        AuditLogger::log($request->user(), 'campaign.content_updated', Campaign::class, $campaign->id, [], $before,
            $campaign->only(['content_mode', 'content_status', 'generated_content']));

        return response()->json(['success' => true, 'message' => 'Content saved and approved.', 'data' => $campaign->fresh()]);
    }

    /**
     * POST /staff/campaigns/{id}/content/decision (manage_campaigns)
     * { decision: approve|reject, note? } — tasks show to contributors only
     * once the content is approved.
     */
    public function contentDecision(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'decision' => 'required|in:approve,reject',
            'note' => 'nullable|string|max:500|required_if:decision,reject',
        ], ['note.required_if' => 'Tell the business what to change.']);

        $campaign = Campaign::whereKeyOrUuid($id)->firstOrFail();
        if (!StaffScope::allowsBusiness($request->user(), $campaign->business_id)) {
            return StaffScope::notFound();
        }
        if (!$campaign->content_mode) {
            return response()->json(['success' => false, 'message' => 'This campaign has no post content.'], 422);
        }
        if ($data['decision'] === 'approve' && !app(\App\Services\AI\ContentSafety::class)->check($campaign->generated_content)['ok']) {
            return response()->json(['success' => false, 'message' => 'This content contains inappropriate words — edit it before approving.'], 422);
        }

        $status = $data['decision'] === 'approve' ? 'approved' : 'rejected';
        $campaign->forceFill([
            'content_status' => $status,
            'content_review_note' => $data['note'] ?? null,
            'content_reviewed_by' => $request->user()->id,
            'content_reviewed_at' => now(),
        ])->save();

        AuditLogger::log($request->user(), 'campaign.content_' . $status, Campaign::class, $campaign->id, ['note' => $data['note'] ?? null]);

        return response()->json([
            'success' => true,
            'message' => $status === 'approved' ? 'Content approved — the tasks are visible to contributors.' : 'Content rejected.',
            'data' => $campaign->fresh(),
        ]);
    }

    /**
     * Edit a campaign's copy and targeting (edit_campaigns). Reward, budget
     * and contributor count are escrow-backed and never editable here.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), CampaignManagementService::editRules());

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $campaign = Campaign::whereKeyOrUuid($id)->firstOrFail();
        if (!StaffScope::allowsBusiness($request->user(), $campaign->business_id)) {
            return StaffScope::notFound();
        }
        $before = $campaign->only(array_keys($validator->validated()));

        try {
            $campaign = $this->campaigns->updateDetails($campaign, $validator->validated());
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        AuditLogger::log($request->user(), 'campaign.updated_by_staff', Campaign::class, $campaign->id, [], $before, $validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Campaign updated.',
            'data' => $campaign->load(['business.owner', 'category'])->loadCount('tasks'),
        ]);
    }

    /**
     * Safe delete (delete_campaigns): refused once any contributor has
     * worked on the campaign — cancel it instead, history is preserved.
     * Otherwise the campaign's outstanding escrow returns to the business
     * wallet, then the campaign and its tasks are soft-deleted.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $campaign = Campaign::whereKeyOrUuid($id)->firstOrFail();
        if (!StaffScope::allowsBusiness($request->user(), $campaign->business_id)) {
            return StaffScope::notFound();
        }

        try {
            $released = $this->campaigns->deleteSafely($campaign);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        AuditLogger::log(
            $request->user(),
            'campaign.deleted',
            Campaign::class,
            $campaign->id,
            ['title' => $campaign->title, 'status' => $campaign->status, 'escrow_released_cents' => $released]
        );

        return response()->json([
            'success' => true,
            'message' => $released > 0
                ? 'Campaign deleted. $' . number_format($released / 100, 2) . ' escrow returned to the business wallet.'
                : 'Campaign deleted.',
            'data' => ['escrow_released_cents' => $released],
        ]);
    }
}
