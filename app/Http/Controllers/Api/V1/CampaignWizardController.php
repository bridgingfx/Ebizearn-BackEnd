<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Services\Campaigns\CampaignWizardService;
use App\Services\Idempotency\IdempotencyService;
use App\Services\TaskTypes\RewardBandService;
use App\Services\TaskTypes\RewardBandViolationException;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Phase 9: campaign wizard API (business-scoped).
 *
 * preview -> draft -> launch. The draft persists without moving money; the
 * launch step performs the ATOMIC budget reservation through the escrow
 * funding gate. Every endpoint is tenant-scoped: a business can only touch
 * its own drafts and campaigns (CampaignPolicy).
 */
class CampaignWizardController extends Controller
{
    public function __construct(
        protected CampaignWizardService $wizard = new CampaignWizardService(),
        protected RewardBandService $bands = new RewardBandService()
    ) {}

    /**
     * Step: budget preview. Honest math, no persistence:
     * total = reward × contributors + platform_fee_percent% platform fee.
     */
    public function preview(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reward_cents' => 'required|integer|min:1',
            'contributors' => 'required|integer|min:1|max:100000',
            'task_type_key' => 'nullable|string|exists:task_types,key',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        try {
            if ($request->filled('task_type_key')) {
                $type = $this->bands->resolveType($request->input('task_type_key'));
                $this->bands->assertWithinBand($type, (int) $request->input('reward_cents'));
            }

            $preview = $this->wizard->preview($validator->validated());
        } catch (RewardBandViolationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $preview]);
    }

    /**
     * Step: persist a draft. No money moves — launch() funds it.
     */
    public function draft(Request $request): JsonResponse
    {
        $business = $request->user()->business;
        if (!$business) {
            return response()->json(['success' => false, 'message' => 'Business profile not found.'], 404);
        }

        $validator = Validator::make($request->all(), $this->draftRules());

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }
        if ($error = $this->contentError($request)) {
            return $error;
        }

        try {
            $campaign = $this->wizard->createDraft($business, $validator->validated());
        } catch (RewardBandViolationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
        $this->saveContent($request, $campaign);

        return response()->json([
            'success' => true,
            'message' => 'Draft saved. Launch it to reserve the budget and go live.',
            'data' => $campaign->load(['category']),
        ], 201);
    }

    /**
     * Round 3: update a saved draft (resume-editing in the wizard).
     * Only drafts can be edited; anything already launched/funded is
     * immutable through this endpoint.
     */
    public function updateDraft(Request $request, string $id): JsonResponse
    {
        $campaign = Campaign::whereKeyOrUuid($id)->firstOrFail();

        Gate::authorize('update', $campaign);

        if ($campaign->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft campaigns can be edited.',
            ], 422);
        }

        $validator = Validator::make($request->all(), $this->draftRules());

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }
        if ($error = $this->contentError($request)) {
            return $error;
        }

        try {
            $campaign = $this->wizard->updateDraft($campaign, $validator->validated());
        } catch (RewardBandViolationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
        $this->saveContent($request, $campaign);

        return response()->json([
            'success' => true,
            'message' => 'Draft updated.',
            'data' => $campaign->load(['category']),
        ]);
    }

    /**
     * Post content on a draft: content_mode none/manual/auto + the text.
     * Returns a 422 response when it is incomplete or not clean.
     */
    protected function contentError(Request $request): ?JsonResponse
    {
        $mode = $request->input('content_mode');
        if ($mode !== null && !in_array($mode, [Campaign::CONTENT_MANUAL, Campaign::CONTENT_AUTO], true)) {
            return response()->json(['success' => false, 'message' => 'Choose manual or auto content.'], 422);
        }
        $text = trim((string) $request->input('generated_content'));
        if ($mode && $text === '') {
            $message = $mode === Campaign::CONTENT_AUTO
                ? 'Auto content needs an approved sample — generate the post with AI first.'
                : 'Write the post content contributors will copy, or switch content off.';

            return response()->json(['success' => false, 'message' => $message, 'errors' => ['generated_content' => [$message]]], 422);
        }
        if (mb_strlen($text) > 2000) {
            return response()->json(['success' => false, 'message' => 'Post content can be at most 2000 characters.'], 422);
        }

        $safety = app(\App\Services\AI\ContentSafety::class);
        foreach (['generated_content' => 'Post content', 'content_brief' => 'Content description'] as $field => $label) {
            $check = $safety->check($request->input($field));
            if (!$check['ok']) {
                $message = "{$label}: {$check['reason']} Please edit it.";

                return response()->json(['success' => false, 'message' => $message, 'errors' => [$field => [$message]]], 422);
            }
        }

        return null;
    }

    /** Store the draft's post content; staff approve it after launch. */
    protected function saveContent(Request $request, Campaign $campaign): void
    {
        $mode = $request->input('content_mode') ?: null;
        $campaign->forceFill([
            'content_mode' => $mode,
            'generated_content' => $mode ? trim((string) $request->input('generated_content')) : null,
            'content_brief' => $mode ? (mb_substr(trim((string) $request->input('content_brief')), 0, 500) ?: null) : null,
            'content_status' => $mode ? 'pending' : null,
        ])->save();
    }

    /**
     * Shared validation for draft create + draft update.
     */
    protected function draftRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'task_title' => 'nullable|string|max:255',
            'objective' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'category_id' => 'required|exists:task_categories,id',
            'task_type_key' => 'required|string|exists:task_types,key',
            'platform' => 'nullable|string|max:64',
            'target_url' => 'nullable|url|max:2000',
            'country_code' => 'nullable|string|max:8',
            'instructions' => 'nullable|string',
            'proof_requirements' => 'nullable|array',
            'reward_cents' => 'required|integer|min:1',
            'contributors' => 'required|integer|min:1|max:100000',
            'retention_days' => 'nullable|integer|min:0|max:365',
            'estimated_minutes' => 'nullable|integer|min:1|max:480',
            'difficulty' => 'nullable|in:easy,medium,hard',
            'countries' => 'nullable|array',
            'languages' => 'nullable|array',
            'min_contributor_level' => 'nullable|in:starter,explorer,trusted,pro,elite',
        ];
    }

    /**
     * Step: launch — ATOMIC budget reservation + task pool creation.
     * A second launch attempt gets a 409; the budget is held exactly once.
     */
    public function launch(Request $request, string $id): JsonResponse
    {
        $campaign = Campaign::whereKeyOrUuid($id)->firstOrFail();

        Gate::authorize('launch', $campaign);

        $validator = Validator::make($request->all(), [
            'idempotency_key' => 'nullable|string|max:128',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Idempotent: a retried launch with the same key returns the
            // already-launched campaign. The wizard itself is double-safe
            // (non-draft status -> 409), so the key mainly protects the
            // escrow hold + fee debit against network retries.
            $launched = app(IdempotencyService::class)->run(
                IdempotencyService::keyFromRequest($request),
                'campaign.launch',
                $request->user()->id,
                ['campaign_id' => $campaign->id],
                fn () => $this->wizard->launch($campaign)
            );
        } catch (Exception $e) {
            $status = $e->getCode() === 409 ? 409 : 422;

            return response()->json(['success' => false, 'message' => $e->getMessage()], $status);
        }

        return response()->json([
            'success' => true,
            'message' => 'Campaign funded and launched successfully.',
            'data' => $launched->load(['category', 'tasks']),
        ]);
    }
}
