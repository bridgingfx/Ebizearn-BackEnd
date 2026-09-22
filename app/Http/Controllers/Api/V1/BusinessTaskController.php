<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskSubmission;
use App\Services\Tasks\TaskManagementService;
use App\Services\TaskTypes\RewardBandViolationException;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Business task CRUD + basic analytics — strictly tenant-scoped.
 *
 * A business creates, reads, updates and deletes ONLY tasks under its OWN
 * campaigns (TaskPolicy). Cross-tenant access fails closed via Gate (403).
 * Analytics are computed from real aggregates — no fabricated numbers.
 */
class BusinessTaskController extends Controller
{
    public function __construct(
        protected TaskManagementService $tasks = new TaskManagementService()
    ) {}

    public function index(Request $request): JsonResponse
    {
        $business = $request->user()->business;
        if (!$business) {
            return response()->json(['success' => false, 'message' => 'Business profile not found.'], 404);
        }

        $query = Task::with(['category', 'taskType', 'campaign'])
            ->whereHas('campaign', fn ($q) => $q->where('business_id', $business->id));

        if ($request->filled('campaign_id')) {
            $query->where('campaign_id', $request->input('campaign_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $tasks = $query->latest()->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $tasks->items(),
            'meta' => ['current_page' => $tasks->currentPage(), 'last_page' => $tasks->lastPage(), 'total' => $tasks->total()],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $task = Task::with(['category', 'taskType', 'campaign'])
            ->where(fn ($q) => $q->where('id', $id)->orWhere('uuid', $id))
            ->firstOrFail();

        Gate::authorize('view', $task);

        return response()->json(['success' => true, 'data' => $task]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = $request->user()->business;
        if (!$business) {
            return response()->json(['success' => false, 'message' => 'Business profile not found.'], 404);
        }

        $validator = $this->taskValidator($request->all(), true);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        try {
            $task = $this->tasks->createTask($validator->validated(), $business);
        } catch (RewardBandViolationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() === 403 ? 403 : 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Task created.',
            'data' => $task->load(['category', 'taskType', 'campaign']),
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $task = Task::where(fn ($q) => $q->where('id', $id)->orWhere('uuid', $id))->firstOrFail();

        Gate::authorize('update', $task);

        $validator = $this->taskValidator($request->all(), false);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        try {
            $task = $this->tasks->updateTask($task, $validator->validated());
        } catch (RewardBandViolationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $task->load(['category', 'taskType', 'campaign'])]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $task = Task::where(fn ($q) => $q->where('id', $id)->orWhere('uuid', $id))->firstOrFail();

        Gate::authorize('delete', $task);

        try {
            $this->tasks->deleteTask($task);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => 'Task deleted.']);
    }

    /**
     * Basic business analytics from REAL aggregates: spend, submissions by
     * status, approval rate, cost per verified task. No fabricated numbers —
     * empty data returns zeros.
     */
    public function analytics(Request $request): JsonResponse
    {
        $business = $request->user()->business;
        if (!$business) {
            return response()->json(['success' => false, 'message' => 'Business profile not found.'], 404);
        }

        $campaignIds = DB::table('campaigns')->where('business_id', $business->id)->pluck('id');
        $taskIds = DB::table('tasks')->whereIn('campaign_id', $campaignIds)->pluck('id');

        $byStatus = DB::table('task_submissions')
            ->whereIn('task_id', $taskIds)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $approved = (int) ($byStatus['approved'] ?? 0);
        $total = (int) $byStatus->sum();
        $approvalRate = $total > 0 ? round($approved / $total * 100, 1) : 0.0;

        $spentCents = (int) DB::table('task_submissions')
            ->whereIn('task_submissions.task_id', $taskIds)
            ->where('task_submissions.status', 'approved')
            ->join('tasks', 'tasks.id', '=', 'task_submissions.task_id')
            ->sum('tasks.reward_cents');

        $costPerVerified = $approved > 0 ? (int) round($spentCents / $approved) : 0;

        return response()->json([
            'success' => true,
            'data' => [
                'campaigns_count' => $campaignIds->count(),
                'tasks_count' => $taskIds->count(),
                'submissions_total' => $total,
                'submissions_by_status' => $byStatus,
                'approved_count' => $approved,
                'approval_rate_percent' => $approvalRate,
                'spent_cents' => $spentCents,
                'cost_per_verified_task_cents' => $costPerVerified,
            ],
        ]);
    }

    protected function taskValidator(array $input, bool $isCreate): \Illuminate\Contracts\Validation\Validator
    {
        $required = $isCreate ? 'required' : 'sometimes';

        return Validator::make($input, [
            'campaign_id' => $required . '|integer|exists:campaigns,id',
            'category_id' => 'nullable|integer|exists:task_categories,id',
            'task_type_key' => $required . '|string|exists:task_types,key',
            'title' => $required . '|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'company_logo_url' => 'nullable|url|max:2000',
            'platform' => 'nullable|string|max:64',
            'country_code' => 'nullable|string|max:8',
            'instructions' => 'nullable|string',
            'reward_cents' => $required . '|integer|min:1',
            'slots_total' => $required . '|integer|min:1|max:100000',
            'proof_required' => 'nullable|array',
            'retention_days' => 'nullable|integer|min:0|max:365',
            'fraud_rules' => 'nullable|array',
            'estimated_minutes' => 'nullable|integer|min:1|max:480',
            'difficulty' => 'nullable|in:easy,medium,hard',
            'status' => 'sometimes|in:available,paused,completed',
        ]);
    }
}
