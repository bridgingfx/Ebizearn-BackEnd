<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Services\Campaigns\CampaignWizardService;
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

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'task_title' => 'nullable|string|max:255',
            'objective' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'category_id' => 'required|exists:task_categories,id',
            'task_type_key' => 'required|string|exists:task_types,key',
            'platform' => 'nullable|string|max:64',
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
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        try {
            $campaign = $this->wizard->createDraft($business, $validator->validated());
        } catch (RewardBandViolationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Draft saved. Launch it to reserve the budget and go live.',
            'data' => $campaign->load(['category']),
        ], 201);
    }

    /**
     * Step: launch — ATOMIC budget reservation + task pool creation.
     * A second launch attempt gets a 409; the budget is held exactly once.
     */
    public function launch(Request $request, string $id): JsonResponse
    {
        $campaign = Campaign::where(fn ($q) => $q->where('id', $id)->orWhere('uuid', $id))->firstOrFail();

        Gate::authorize('launch', $campaign);

        try {
            $launched = $this->wizard->launch($campaign);
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
