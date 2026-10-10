<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\TaskAssignment;
use App\Models\TaskSubmission;
use App\Services\Staff\StaffScope;
use App\Services\Verification\VerificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin → Task History (view_task_history): every task a contributor took —
 * started, submitted, approved, rejected or expired — with the proof they
 * sent (link, screenshots, video, note) and its status. Status changes go
 * through the normal review endpoint (/admin/submissions/{id}/decision), so
 * rewards and reversals stay on the ledger.
 */
class TaskHistoryController extends Controller
{
    /** Filter value => which rows it matches. "in_review" covers every step before a decision. */
    private const STATUS_FILTERS = [
        'in_progress' => ['assignment' => ['reserved', 'in_progress']],
        'in_review' => ['submission' => ['submitted', 'checking', 'under_review']],
        'action_required' => ['submission' => ['action_required']],
        'approved' => ['submission' => ['approved']],
        'rejected' => ['submission' => ['rejected']],
        'expired' => ['assignment' => ['expired', 'cancelled']],
    ];

    /** Decisions staff can take from each submission status (mirrors VerificationService). */
    private const NEXT_DECISIONS = [
        'submitted' => ['approved', 'rejected', 'action_required'],
        'checking' => ['rejected'],
        'under_review' => ['approved', 'rejected', 'action_required'],
        'action_required' => ['approved', 'rejected'],
        'approved' => ['rejected'],
        'rejected' => [],
    ];

    /** GET /staff/task-history?status=&search=&platform=&from=&to=&page= */
    public function index(Request $request): JsonResponse
    {
        $base = $this->scoped($request);

        $query = (clone $base)
            ->with([
                'task:id,uuid,campaign_id,title,platform,reward_cents,status',
                'task.campaign:id,uuid,business_id,title,platform,company_name',
                'task.campaign.business:id,uuid,company_name',
                'user:id,uuid,name,email,role,status',
                'user.profile:id,user_id,country_code,avatar_url',
                'submission:id,uuid,assignment_id,status,created_at,reviewed_at,proof_data_json,bonus_cents',
                'submission.files:id,submission_id,file_type,file_url,mime_type',
            ]);

        if ($filter = self::STATUS_FILTERS[$request->input('status')] ?? null) {
            $this->applyStatus($query, $filter);
        }

        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $query->where(fn (Builder $q) => $q
                ->whereHas('user', fn ($u) => $u->where('name', 'like', $term)->orWhere('email', 'like', $term))
                ->orWhereHas('task', fn ($t) => $t->where('title', 'like', $term)));
        }
        if ($request->filled('platform')) {
            $platform = strtolower((string) $request->input('platform'));
            $query->whereHas('task', fn ($t) => $t->whereRaw('LOWER(platform) = ?', [$platform])
                ->orWhereHas('campaign', fn ($c) => $c->whereRaw('LOWER(platform) = ?', [$platform])));
        }
        if ($request->filled('from')) {
            $query->whereDate('task_assignments.created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('task_assignments.created_at', '<=', $request->input('to'));
        }

        $page = $query->latest('task_assignments.id')->paginate(max(1, min(100, (int) $request->input('per_page', 20))));

        $counts = [];
        foreach (self::STATUS_FILTERS as $key => $filter) {
            $counts[$key] = $this->applyStatus(clone $base, $filter)->count();
        }
        $counts['all'] = (clone $base)->count();

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn (TaskAssignment $a) => $this->row($a)),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'counts' => $counts,
            ],
        ]);
    }

    /** GET /staff/task-history/{assignmentId} */
    public function show(Request $request, int $id): JsonResponse
    {
        $assignment = $this->scoped($request)
            ->with([
                'task.category:id,name,slug',
                'task.campaign.business:id,uuid,company_name,owner_id',
                'task.campaign.media',
                'user:id,uuid,name,email,role,status,created_at',
                'user.profile',
                'user.wallet',
            ])
            ->whereKey($id)
            ->firstOrFail();

        $submission = TaskSubmission::with(['files', 'aiResult', 'reviewer:id,name,role', 'businessReviewer:id,name'])
            ->where('assignment_id', $assignment->id)
            ->first();

        $timeline = AuditLog::with('actor:id,name,role')
            ->where(function ($q) use ($assignment, $submission) {
                $q->where(fn ($w) => $w->where('entity_type', TaskAssignment::class)->where('entity_id', $assignment->id));
                if ($submission) {
                    $q->orWhere(fn ($w) => $w->where('entity_type', TaskSubmission::class)->where('entity_id', $submission->id));
                }
            })
            ->orderBy('created_at')->orderBy('id')
            ->limit(50)
            ->get(['id', 'actor_id', 'action', 'after_state_json', 'created_at']);

        $assignment->task?->campaign?->makeHidden(['generated_content', 'content_brief']);
        $assignment->user?->profile?->makeHidden(['kyc_front_path', 'kyc_back_path', 'kyc_selfie_path']);

        return response()->json([
            'success' => true,
            'data' => [
                'assignment' => $assignment,
                'submission' => $submission,
                'status' => $submission?->status ?? $assignment->status,
                'next_decisions' => $submission ? (self::NEXT_DECISIONS[$submission->status] ?? []) : [],
                'reason_codes' => VerificationService::REASON_CODES,
                'timeline' => $timeline,
            ],
        ]);
    }

    private function scoped(Request $request): Builder
    {
        $query = TaskAssignment::query()->select('task_assignments.*');
        StaffScope::apply($query, $request->user(), 'task_assignments.user_id');

        return $query;
    }

    private function applyStatus(Builder $query, array $filter): Builder
    {
        if (isset($filter['submission'])) {
            return $query->whereHas('submission', fn ($s) => $s->whereIn('status', $filter['submission']));
        }

        return $query->whereIn('task_assignments.status', $filter['assignment'])->whereDoesntHave('submission');
    }

    private function row(TaskAssignment $a): array
    {
        $s = $a->submission;
        $files = $s?->files ?? collect();
        $proof = $s?->proof_data_json ?? [];

        return [
            'id' => $a->id,
            'status' => $s?->status ?? $a->status,
            'assignment_status' => $a->status,
            'started_at' => $a->started_at ?? $a->created_at,
            'submitted_at' => $s?->created_at,
            'reviewed_at' => $s?->reviewed_at,
            'user' => $a->user ? [
                'id' => $a->user->id,
                'name' => $a->user->name,
                'email' => $a->user->email,
                'country_code' => $a->user->profile?->country_code,
                'avatar_url' => $a->user->profile?->avatar_url,
            ] : null,
            'task' => $a->task ? [
                'id' => $a->task->id,
                'uuid' => $a->task->uuid,
                'title' => $a->task->title,
                'platform' => $a->task->platform ?: $a->task->campaign?->platform,
                'reward_cents' => (int) $a->task->reward_cents,
                'business' => $a->task->campaign?->business?->company_name ?: $a->task->campaign?->company_name,
            ] : null,
            'submission_id' => $s?->id,
            'proof' => [
                'has_link' => !empty($proof['url']),
                'images' => $files->filter(fn ($f) => str_starts_with((string) $f->mime_type, 'image/'))->count(),
                'videos' => $files->filter(fn ($f) => str_starts_with((string) $f->mime_type, 'video/'))->count(),
                'thumb' => $files->first(fn ($f) => str_starts_with((string) $f->mime_type, 'image/'))?->file_url,
            ],
        ];
    }
}
