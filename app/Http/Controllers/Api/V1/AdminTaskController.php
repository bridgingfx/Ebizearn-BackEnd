<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Services\Tasks\TaskManagementService;
use App\Services\TaskTypes\RewardBandViolationException;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Phase 4: admin/moderator task CRUD.
 *
 * Route-guarded by `permission:manage_task_templates` (moderators hold it
 * via their role grant; admins and super-admins always pass). Every reward
 * is validated against its task type's band (Phase 12) — out-of-band
 * rewards get a 422.
 */
class AdminTaskController extends Controller
{
    public function __construct(
        protected TaskManagementService $tasks = new TaskManagementService()
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Task::with(['category', 'taskType', 'campaign.business']);

        if ($request->filled('campaign_id')) {
            $query->where('campaign_id', $request->input('campaign_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('task_type_key')) {
            $query->whereHas('taskType', fn ($q) => $q->where('key', $request->input('task_type_key')));
        }

        $tasks = $query->latest()->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $tasks->items(),
            'meta' => ['current_page' => $tasks->currentPage(), 'last_page' => $tasks->lastPage(), 'total' => $tasks->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = $this->taskValidator($request->all(), true);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        try {
            $task = $this->tasks->createTask($validator->validated());
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
        $task = Task::where('id', $id)->orWhere('uuid', $id)->firstOrFail();

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

    public function destroy(string $id): JsonResponse
    {
        $task = Task::where('id', $id)->orWhere('uuid', $id)->firstOrFail();

        try {
            $this->tasks->deleteTask($task);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => 'Task deleted.']);
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
