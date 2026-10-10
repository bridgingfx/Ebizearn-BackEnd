<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\TaskAssignment;
use App\Models\TaskSubmission;
use App\Models\SocialChannel;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Verification\PostVerificationService;
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
        // Reward lifecycle after approval.
        'pending_duration' => ['reward' => ['pending_duration']],
        'reverification_required' => ['reward' => ['reverification_required']],
        'released' => ['reward' => ['released']],
        'refunded' => ['reward' => ['refunded']],
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
                'submission:id,uuid,assignment_id,status,created_at,reviewed_at,proof_data_json,bonus_cents,reward_status,final_check_due_at,auto_verify_status',
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

        // Money held / paid / returned (task rewards, cents).
        $scopeIds = StaffScope::userIds($request->user());
        $money = fn (array $statuses) => (int) TaskSubmission::query()
            ->join('tasks', 'tasks.id', '=', 'task_submissions.task_id')
            ->whereIn('task_submissions.reward_status', $statuses)
            ->when($scopeIds !== null, fn ($q) => $q->whereIn('task_submissions.user_id', $scopeIds))
            ->sum('tasks.reward_cents');
        $totals = [
            'pending_cents' => $money(['pending_duration', 'reverification_required']),
            'released_cents' => $money(['released']),
            'refunded_cents' => $money(['refunded']),
        ];

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn (TaskAssignment $a) => $this->row($a)),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'counts' => $counts,
                'totals' => $totals,
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

        $submission = TaskSubmission::with(['files', 'aiResult', 'reviewer:id,name,role', 'businessReviewer:id,name', 'postVerifications.actor:id,name,role'])
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
                'reward_actions' => $this->rewardActions($submission),
                'funding' => $submission && $submission->funding_user_id ? [
                    'type' => $submission->funding_type,
                    'user' => User::find($submission->funding_user_id, ['id', 'name', 'email', 'role']),
                    'wallet_id' => $submission->funding_wallet_id,
                    'reference' => $submission->funding_reference,
                ] : null,
                'ledger' => $submission ? WalletTransaction::where('reference_type', TaskSubmission::class)
                    ->where('reference_id', $submission->id)
                    ->orderBy('id')
                    ->get(['id', 'wallet_id', 'type', 'amount_cents', 'description', 'created_at'])
                    ->map(fn ($t) => $t->toArray() + ['wallet_owner' => Wallet::find($t->wallet_id)?->user?->only(['id', 'name', 'role'])]) : [],
                'instagram' => $this->instagramStatus($assignment->user_id),
            ],
        ]);
    }

    /**
     * POST /staff/task-history/{id}/reward { action: release|refund|recheck|verify, note }
     * Manual control of the reward after approval (review_submissions):
     * release now, refund to the funder, re-run the final post check, or
     * re-run the automatic proof check.
     */
    public function reward(Request $request, int $id, PostVerificationService $posts): JsonResponse
    {
        $data = $request->validate([
            'action' => 'required|in:release,refund,recheck,verify',
            'note' => 'required_if:action,release,refund|nullable|string|min:3|max:500',
        ], ['note.required_if' => 'Add a short reason (kept in the audit log).']);

        $assignment = $this->scoped($request)->whereKey($id)->firstOrFail();
        $submission = TaskSubmission::where('assignment_id', $assignment->id)->firstOrFail();
        abort_unless(in_array($data['action'], $this->rewardActions($submission), true), 422, 'That action is not available for this task right now.');

        $actor = $request->user();
        $message = match ($data['action']) {
            'release' => $posts->releaseReward($submission, $actor, 'Released by staff: ' . $data['note']) ? 'Reward released to the contributor.' : '',
            'refund' => $posts->refundReward($submission, $actor, 'Refunded by staff: ' . $data['note']) ? 'Reward refunded to the funding account.' : '',
            'recheck' => match ($posts->finalCheck($submission, $actor)) {
                'verified' => 'Post is still live — reward released.',
                'refunded' => 'Post is gone — reward refunded to the business.',
                'manual_review' => 'Still could not confirm the post — waiting for a manual decision.',
                'retry' => 'Could not confirm the post right now — it will be retried automatically.',
                default => 'Nothing to check.',
            },
            'verify' => $this->rerunInitial($submission, $posts),
        };

        return response()->json(['success' => true, 'message' => $message]);
    }

    private function rerunInitial(TaskSubmission $submission, PostVerificationService $posts): string
    {
        $submission->forceFill(['auto_verify_status' => 'pending'])->saveQuietly();
        $record = $posts->verifyInitial($submission);

        return 'Automatic check finished: ' . ($record?->outcome ?? 'skipped') . ($record?->reason ? ' — ' . $record->reason : '');
    }

    /** What staff can do with the reward right now. */
    private function rewardActions(?TaskSubmission $submission): array
    {
        if (!$submission) {
            return [];
        }
        $actions = [];
        if (in_array($submission->status, ['submitted', 'checking', 'under_review', 'action_required'], true)) {
            $actions[] = 'verify';
        }
        if ($submission->status === 'approved' && in_array($submission->reward_status, ['pending_duration', 'reverification_required'], true)) {
            $actions[] = 'release';
            $actions[] = 'refund';
            if ($submission->platform_media_id) {
                $actions[] = 'recheck';
            }
        }

        return $actions;
    }

    /** The contributor's Instagram connection (never the token). */
    private function instagramStatus(int $userId): ?array
    {
        $channel = SocialChannel::where('user_id', $userId)->where('platform', 'instagram')->first();
        if (!$channel) {
            return null;
        }

        return [
            'handle' => $channel->oauth_username ?: $channel->handle,
            'connected_via' => $channel->connected_via,
            'status' => $channel->status,
            'scopes' => $channel->oauth_scopes,
            'expires_at' => $channel->oauth_expires_at,
            'token_expired' => $channel->oauth_expires_at ? $channel->oauth_expires_at->isPast() : false,
            'last_check_at' => $channel->last_robo_check_at,
            'note' => $channel->robo_check_note,
        ];
    }

    private function scoped(Request $request): Builder
    {
        $query = TaskAssignment::query()->select('task_assignments.*');
        StaffScope::apply($query, $request->user(), 'task_assignments.user_id');

        return $query;
    }

    private function applyStatus(Builder $query, array $filter): Builder
    {
        if (isset($filter['reward'])) {
            return $query->whereHas('submission', fn ($s) => $s->whereIn('reward_status', $filter['reward']));
        }
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
            'reward_status' => $s?->reward_status,
            'final_check_due_at' => $s?->final_check_due_at,
            'auto_verify_status' => $s?->auto_verify_status,
            'proof' => [
                'has_link' => !empty($proof['url']),
                'images' => $files->filter(fn ($f) => str_starts_with((string) $f->mime_type, 'image/'))->count(),
                'videos' => $files->filter(fn ($f) => str_starts_with((string) $f->mime_type, 'video/'))->count(),
                'thumb' => $files->first(fn ($f) => str_starts_with((string) $f->mime_type, 'image/'))?->file_url,
            ],
        ];
    }
}
