<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\SubmissionFile;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Fraud\FraudAnalysisService;
use App\Services\Fraud\FraudRejectionException;
use App\Services\Verification\VerificationService;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class TaskController extends Controller
{
    public function __construct(
        protected VerificationService $verificationService = new VerificationService(),
        protected FraudAnalysisService $fraudService = new FraudAnalysisService()
    ) {}

    /**
     * Browse available tasks with filtering, search, and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Task::with(['category', 'campaign.business'])
            ->where('status', 'available')
            // Priority 4 — approval gate: tasks are invisible until their
            // campaign is approved to `active` by staff.
            ->whereHas('campaign', function ($q) {
                $q->where('status', 'active');
            });

        // Filter by category slug
        if ($request->filled('category')) {
            $categorySlug = $request->input('category');
            $query->whereHas('category', function ($q) use ($categorySlug) {
                $q->where('slug', $categorySlug);
            });
        }

        // Search in title or description
        if ($request->filled('search')) {
            $search = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', $search)
                  ->orWhereHas('campaign', function ($cq) use ($search) {
                      $cq->where('description', 'like', $search);
                  });
            });
        }

        // Filter by difficulty
        if ($request->filled('difficulty')) {
            $query->where('difficulty', $request->input('difficulty'));
        }

        // Sorting
        $sortBy = $request->input('sort', 'newest');
        match ($sortBy) {
            'reward_desc' => $query->orderByDesc('reward_cents'),
            'reward_asc' => $query->orderBy('reward_cents'),
            'time_asc' => $query->orderBy('estimated_minutes'),
            default => $query->latest(),
        };

        $tasks = $query->paginate($request->input('per_page', 12));

        return response()->json([
            'success' => true,
            'data' => $tasks->items(),
            'meta' => [
                'current_page' => $tasks->currentPage(),
                'last_page' => $tasks->lastPage(),
                'per_page' => $tasks->perPage(),
                'total' => $tasks->total(),
            ],
        ]);
    }

    /**
     * Get detailed task information including campaign guidelines and proof requirements.
     *
     * Public endpoint: scoped to available tasks in active campaigns, mirroring
     * index(). Draft/paused tasks must not be enumerable without authentication.
     */
    public function show(string $id): JsonResponse
    {
        $task = Task::with(['category', 'campaign.business'])
            ->where('status', 'available')
            ->whereHas('campaign', fn ($q) => $q->where('status', 'active'))
            ->where(function ($q) use ($id) {
                $q->where('tasks.id', $id)->orWhere('tasks.uuid', $id);
            })
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $task,
        ]);
    }

    /**
     * Start/reserve a task for the authenticated contributor.
     */
    public function start(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $task = Task::where('id', $id)->orWhere('uuid', $id)->firstOrFail();

        if ($task->status !== 'available' || $task->slots_taken >= $task->slots_total) {
            return response()->json([
                'success' => false,
                'message' => 'This task is no longer available or has reached capacity.',
            ], 400);
        }

        try {
            $assignment = $this->reserveSlot($task, $user);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => $assignment->wasRecentlyCreated ? 'Task started successfully.' : 'Task already started.',
            'data' => $assignment->load('task.campaign'),
        ], $assignment->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Reserve a slot atomically: the task row and the campaign budget row are
     * both locked inside one transaction, and the campaign's remaining budget
     * is decremented together with the slot increment. Slots can therefore
     * never be oversold and remaining_budget_cents can never go negative.
     * Lost races return the winning assignment instead of duplicating.
     */
    protected function reserveSlot(Task $task, User $user): TaskAssignment
    {
        return DB::transaction(function () use ($task, $user) {
            $lockedTask = Task::where('id', $task->id)->lockForUpdate()->firstOrFail();

            if ($lockedTask->status !== 'available' || $lockedTask->slots_taken >= $lockedTask->slots_total) {
                throw new Exception('This task is no longer available or has reached capacity.');
            }

            $existing = TaskAssignment::where('task_id', $lockedTask->id)
                ->where('user_id', $user->id)
                ->whereIn('status', ['reserved', 'in_progress', 'submitted'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $campaign = Campaign::where('id', $lockedTask->campaign_id)->lockForUpdate()->firstOrFail();

            if ($campaign->status !== 'active') {
                throw new Exception('This campaign is not currently active.');
            }

            if ($campaign->remaining_budget_cents < $lockedTask->reward_cents) {
                throw new Exception('Campaign budget exhausted — no funded slots remaining.');
            }

            try {
                $assignment = TaskAssignment::create([
                    'task_id' => $lockedTask->id,
                    'user_id' => $user->id,
                    'status' => 'in_progress',
                    'reserved_until' => now()->addHours(2), // 2 hours reservation window
                    'started_at' => now(),
                ]);
            } catch (QueryException $e) {
                // Lost a race with a concurrent request: return the winner's
                // assignment instead of creating a duplicate.
                $winner = TaskAssignment::where('task_id', $lockedTask->id)
                    ->where('user_id', $user->id)
                    ->first();

                if ($winner) {
                    return $winner;
                }

                throw $e;
            }

            $lockedTask->increment('slots_taken');
            $campaign->decrement('remaining_budget_cents', $lockedTask->reward_cents);
            $campaign->increment('reserved_budget_cents', $lockedTask->reward_cents);

            return $assignment;
        });
    }

    /**
     * Submit proof for a task assignment.
     */
    public function submit(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $task = Task::where('id', $id)->orWhere('uuid', $id)->firstOrFail();

        if ($task->status !== 'available') {
            return response()->json([
                'success' => false,
                'message' => 'This task is not currently accepting submissions.',
            ], 409);
        }

        if (TaskSubmission::where('task_id', $task->id)->where('user_id', $user->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'You have already submitted proof for this task.',
            ], 409);
        }

        $assignment = TaskAssignment::where('task_id', $task->id)
            ->where('user_id', $user->id)
            ->whereIn('status', ['in_progress', 'reserved'])
            ->first();

        if (!$assignment) {
            if ($task->slots_taken >= $task->slots_total) {
                return response()->json([
                    'success' => false,
                    'message' => 'This task has reached capacity.',
                ], 409);
            }

            // Auto-create assignment if not explicitly reserved (atomic slot +
            // budget reservation, race-safe).
            try {
                $assignment = $this->reserveSlot($task, $user);
            } catch (Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 409);
            }
        }

        $validator = Validator::make($request->all(), [
            'proof_url' => 'nullable|required_without_all:proof_screenshot,text_answer|url|max:2000',
            'proof_screenshot' => 'nullable|required_without_all:proof_url,text_answer|string|max:10485760', // Base64 or uploaded URL
            'text_answer' => 'nullable|required_without_all:proof_url,proof_screenshot|string|max:5000',
            'note' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $proofData = [
            'url' => $request->input('proof_url'),
            'text_answer' => $request->input('text_answer'),
            'note' => $request->input('note'),
            'submitted_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];

        // Phase 6 hard screens BEFORE the row exists: missing proof
        // requirements, wrong URL domain vs the task platform, and duplicate
        // proof hashes (per campaign) are rejected here with honest codes.
        $task->load('taskType');

        try {
            $screens = $this->fraudService->screenProofOrReject($task, [
                'url' => $request->input('proof_url'),
                'screenshot' => $request->input('proof_screenshot'),
                'text_answer' => $request->input('text_answer'),
            ], $request);
        } catch (FraudRejectionException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'reason_code' => $e->reasonCode,
            ], $e->httpStatus);
        }

        $submission = null;

        try {
            $submission = DB::transaction(function () use ($task, $user, $assignment, $proofData, $screens, $request) {
                $sub = TaskSubmission::create([
                    'task_id' => $task->id,
                    'user_id' => $user->id,
                    'assignment_id' => $assignment->id,
                    'status' => 'submitted',
                    'verification_stage' => 'received',
                    'proof_hash' => $screens['proof_hash'],
                    'device_fingerprint' => $screens['device_fingerprint'],
                    'proof_data_json' => $proofData,
                ]);

                $assignment->update([
                    'status' => 'submitted',
                    'completed_at' => now(),
                ]);

                // Save screenshot file entry if provided.
                // Priority 3 — proof persistence: base64 data is decoded and
                // written to storage (not just a path string); URLs are kept as-is.
                $screenshot = $request->input('proof_screenshot');
                if (!empty($screenshot)) {
                    $filePath = 'proofs/' . $sub->uuid . '.png';
                    $fileUrl = $screenshot;
                    $fileSize = 1024 * 512;
                    $mimeType = 'image/png';

                    if ($this->isBase64Image($screenshot)) {
                        $binary = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $screenshot));
                        Storage::disk('public')->put($filePath, $binary);
                        $fileUrl = Storage::disk('public')->url($filePath);
                        $fileSize = strlen($binary);
                        $mimeType = $this->detectImageMime($binary) ?? 'image/png';
                    }

                    SubmissionFile::create([
                        'submission_id' => $sub->id,
                        'file_type' => 'screenshot',
                        'file_path' => $filePath,
                        'file_url' => $fileUrl,
                        'file_size_bytes' => $fileSize,
                        'mime_type' => $mimeType,
                    ]);
                }

                return $sub;
            });
        } catch (QueryException $e) {
            // Lost a double-submit race against the unique(task_id, user_id)
            // constraint: treat it as the existing "already submitted" case.
            if (TaskSubmission::where('task_id', $task->id)->where('user_id', $user->id)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'You have already submitted proof for this task.',
                ], 409);
            }

            throw $e;
        }

        // Phase 6: run the system screening stage (submitted -> checking ->
        // under_review). The heuristic NEVER auto-approves — the submission
        // always lands in the moderator queue with flags/scores attached.
        $aiResult = $this->verificationService->screenSubmission($submission);

        return response()->json([
            'success' => true,
            'message' => 'Submission received and placed under review.',
            'data' => [
                'submission' => $submission->load(['task.campaign', 'files', 'aiResult']),
                'ai_precheck' => [
                    'confidence_score' => $aiResult->confidence_score,
                    'suggested_decision' => $aiResult->suggested_decision,
                    'summary' => $aiResult->analysis_summary,
                    // Honesty labelling: mock results are simulated placeholders.
                    'ai_simulated' => (bool) $aiResult->ai_simulated,
                    'ai_label' => $aiResult->ai_label,
                ],
            ],
        ], 201);
    }

    /**
     * Get Contributor Dashboard Overview stats.
     */
    public function contributorDashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $wallet = $user->wallet ?? Wallet::firstOrCreate(['user_id' => $user->id]);

        $completedCount = TaskSubmission::where('user_id', $user->id)->where('status', 'approved')->count();
        $pendingCount = TaskSubmission::where('user_id', $user->id)->whereIn('status', ['submitted', 'under_review'])->count();

        // Calculate today's earnings
        $todayCents = DB::table('wallet_transactions')
            ->where('wallet_id', $wallet->id)
            ->where('amount_cents', '>', 0)
            ->whereDate('created_at', now()->toDateString())
            ->sum('amount_cents');

        // Recommended tasks
        $recommendedTasks = Task::with(['category', 'campaign.business'])
            ->where('status', 'available')
            ->latest()
            ->take(5)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'name' => $user->name,
                    'level' => $user->profile?->contributor_level ?? 'starter',
                    'avatar' => $user->profile?->avatar_url,
                ],
                'stats' => [
                    'available_balance_cents' => $wallet->available_balance_cents,
                    'pending_balance_cents' => $wallet->pending_balance_cents,
                    'today_earnings_cents' => $todayCents,
                    'completed_tasks_count' => $completedCount,
                    'pending_tasks_count' => $pendingCount,
                ],
                'recommended_tasks' => $recommendedTasks,
            ],
        ]);
    }

    /**
     * Contributor's task history and active assignments.
     */
    public function myTasks(Request $request): JsonResponse
    {
        $user = $request->user();
        $status = $request->input('status'); // submitted, under_review, approved, rejected

        $query = TaskSubmission::with(['task.category', 'task.campaign.business', 'files', 'aiResult'])
            ->where('user_id', $user->id);

        if (!empty($status)) {
            $query->where('status', $status);
        }

        $submissions = $query->latest()->paginate(15);

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
     * Detect base64-encoded image data (with or without data-URI prefix).
     */
    protected function isBase64Image(string $value): bool
    {
        if (preg_match('#^data:image/\w+;base64,#i', $value)) {
            return true;
        }
        // Raw base64: long string, valid base64 alphabet, decodes to bytes
        // starting with a known image magic number.
        if (strlen($value) < 100 || !preg_match('#^[A-Za-z0-9+/=\r\n]+$#', $value)) {
            return false;
        }
        $binary = base64_decode($value, true);
        return $binary !== false && $this->detectImageMime($binary) !== null;
    }

    /**
     * Sniff PNG/JPEG/GIF/WebP magic bytes. Returns null when unknown.
     */
    protected function detectImageMime(string $binary): ?string
    {
        if (str_starts_with($binary, "\x89PNG\r\n\x1a\n")) return 'image/png';
        if (str_starts_with($binary, "\xff\xd8\xff")) return 'image/jpeg';
        if (str_starts_with($binary, 'GIF87a') || str_starts_with($binary, 'GIF89a')) return 'image/gif';
        if (str_starts_with($binary, 'RIFF') && substr($binary, 8, 4) === 'WEBP') return 'image/webp';
        return null;
    }
}
