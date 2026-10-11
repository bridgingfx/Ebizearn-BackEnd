<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
<<<<<<< Updated upstream
use App\Models\AuditLog;
=======
>>>>>>> Stashed changes
use App\Models\SubmissionAppeal;
use App\Models\TaskAssignment;
use App\Models\TaskSubmission;
use App\Models\WalletTransaction;
use App\Notifications\TaskRewardNotice;
<<<<<<< Updated upstream
=======
use App\Services\Audit\AuditLogger;
>>>>>>> Stashed changes
use App\Services\Staff\StaffScope;
use App\Services\Verification\VerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
<<<<<<< Updated upstream
use Illuminate\Support\Facades\DB;

/**
 * Submission appeals (the missing piece of the verification pipeline):
 *
 * - Contributors can appeal a REJECTED submission for a second human review.
 * - Reviewers uphold (submission returns to the moderator queue) or deny.
 *
 * Safety rules:
 * - Only the submission owner can appeal, only when status is 'rejected',
 *   and only one open appeal per submission.
 * - An upheld appeal moves rejected -> under_review with a full audit trail
 *   (VerificationService::reopenOnAppeal). It never auto-approves or pays —
 *   the normal review path still decides. The slot's reward is reserved in
 *   the campaign pool again so a later approval is funded.
 * - One appeal per submission, within 30 days of the rejection, and never
 *   for a reward that was already paid once and reversed.
=======

/**
 * Appeals: a contributor contests a rejected proof once (within 30 days).
 * Staff uphold it — the proof goes back to the review queue, where the
 * normal approve / reject decision pays or not — or deny it (the rejection
 * stands). An appeal never approves or pays anything by itself.
>>>>>>> Stashed changes
 */
class SubmissionAppealController extends Controller
{
    public const WINDOW_DAYS = 30;

<<<<<<< Updated upstream
    /** Can this rejected submission still be appealed? */
=======
    /** Can this rejected proof still be appealed? */
>>>>>>> Stashed changes
    public static function canAppeal(?TaskSubmission $s): bool
    {
        return $s
            && $s->status === 'rejected'
<<<<<<< Updated upstream
            && !SubmissionAppeal::where('submission_id', $s->id)->exists()
=======
            && !$s->appeal()->exists()
>>>>>>> Stashed changes
            && (!$s->reviewed_at || $s->reviewed_at->gte(now()->subDays(self::WINDOW_DAYS)))
            && !WalletTransaction::where('reference_type', TaskSubmission::class)->where('reference_id', $s->id)->where('type', 'task_reward')->exists();
    }

<<<<<<< Updated upstream
    /**
     * Contributor files an appeal on their rejected submission.
     * POST /api/v1/task-history/{assignmentId}/appeal
     */
    public function store(Request $request, int $assignmentId): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $assignment = TaskAssignment::where('user_id', $request->user()->id)
            ->whereKey($assignmentId)
            ->firstOrFail();

        $submission = TaskSubmission::where('assignment_id', $assignment->id)->firstOrFail();

        if ($submission->status !== 'rejected') {
            return response()->json([
                'success' => false,
                'code' => 'not_rejected',
                'message' => 'Only rejected submissions can be appealed.',
            ], 422);
        }

        if (SubmissionAppeal::where('submission_id', $submission->id)->exists()) {
            return response()->json([
                'success' => false,
                'code' => 'appeal_exists',
                'message' => 'You have already appealed this decision.',
            ], 409);
        }

        if (!self::canAppeal($submission)) {
            return response()->json([
                'success' => false,
                'code' => 'appeal_closed',
                'message' => 'This task can no longer be appealed.',
            ], 422);
        }

        $appeal = SubmissionAppeal::create([
            'submission_id' => $submission->id,
            'user_id' => $request->user()->id,
            'reason' => $data['reason'],
            'status' => SubmissionAppeal::STATUS_OPEN,
        ]);

        AuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => 'submission.appeal_filed',
            'entity_type' => TaskSubmission::class,
            'entity_id' => $submission->id,
            'after_state_json' => ['appeal_id' => $appeal->id],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return response()->json(['success' => true, 'data' => $appeal], 201);
    }

    /**
     * Reviewer queue of appeals.
     * GET /api/v1/admin/submission-appeals?status=open
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->input('status', 'open');
        $query = SubmissionAppeal::with(['submission.task', 'user.profile', 'reviewer:id,name'])
            ->latest();
        // Staff assigned to specific users only see their appeals.
        StaffScope::apply($query, $request->user(), 'submission_appeals.user_id');

        if (in_array($status, [SubmissionAppeal::STATUS_OPEN, SubmissionAppeal::STATUS_UPHELD, SubmissionAppeal::STATUS_DENIED], true)) {
            $query->where('status', $status);
        }

        $appeals = $query->paginate(20);

        return response()->json([
            'success' => true,
            // assignment_id links the appeal to Task History (proof, AI evidence, risk flags).
            'data' => collect($appeals->items())->map(fn (SubmissionAppeal $a) => $a->toArray() + ['assignment_id' => $a->submission?->assignment_id]),
            'meta' => [
                'current_page' => $appeals->currentPage(),
                'last_page' => $appeals->lastPage(),
                'total' => $appeals->total(),
            ],
        ]);
    }

    /**
     * Reviewer decides an appeal.
     * POST /api/v1/admin/submission-appeals/{id}/decision
     * { decision: 'uphold' | 'deny', notes?: string }
     */
    public function decide(Request $request, int $id, VerificationService $verification): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:uphold,deny'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $scopeCheck = SubmissionAppeal::findOrFail($id);
        if (!StaffScope::allowsUser($request->user(), $scopeCheck->user_id)) {
            return StaffScope::notFound();
        }

        $response = DB::transaction(function () use ($request, $id, $data, $verification) {
            $appeal = SubmissionAppeal::whereKey($id)->lockForUpdate()->firstOrFail();

            if (!$appeal->isOpen()) {
                return response()->json([
                    'success' => false,
                    'code' => 'appeal_closed',
                    'message' => 'This appeal has already been decided.',
                ], 409);
            }

            $submission = TaskSubmission::whereKey($appeal->submission_id)->lockForUpdate()->firstOrFail();
            $reviewer = $request->user();
            $before = $submission->toArray();

            if ($data['decision'] === 'uphold') {
                // Second chance: back to the moderator queue. The reviewer
                // still decides approve/reject through the normal path —
                // nothing is auto-approved and no wallet moves here. The slot's
                // reward is reserved in the campaign pool again.
                try {
                    $verification->reopenOnAppeal($submission, $reviewer, $data['notes'] ?? 'Sent back for a second review.');
                } catch (\Exception $e) {
                    return response()->json(['success' => false, 'code' => 'cannot_reopen', 'message' => $e->getMessage()], 422);
                }
                $appeal->update([
                    'status' => SubmissionAppeal::STATUS_UPHELD,
                    'reviewer_id' => $reviewer->id,
                    'reviewed_at' => now(),
                    'review_notes' => $data['notes'] ?? null,
                ]);
            } else {
                $appeal->update([
                    'status' => SubmissionAppeal::STATUS_DENIED,
                    'reviewer_id' => $reviewer->id,
                    'reviewed_at' => now(),
                    'review_notes' => $data['notes'] ?? null,
                ]);
            }

            AuditLog::create([
                'actor_id' => $reviewer->id,
                'action' => $data['decision'] === 'uphold' ? 'submission.appeal_upheld' : 'submission.appeal_denied',
                'entity_type' => TaskSubmission::class,
                'entity_id' => $submission->id,
                'before_state_json' => ['status' => $before['status'], 'appeal_id' => $appeal->id],
                'after_state_json' => ['status' => $submission->fresh()->status, 'appeal_id' => $appeal->id],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);

            return response()->json(['success' => true, 'data' => $appeal->fresh()]);
        });

        // Tell the contributor (in-app).
        if ($response->getStatusCode() === 200) {
            $appeal = SubmissionAppeal::with('submission.task', 'user')->find($id);
            $title = (string) $appeal?->submission?->task?->title;
            $upheld = $appeal?->status === SubmissionAppeal::STATUS_UPHELD;
            $appeal?->user?->notify(new TaskRewardNotice(
                $upheld ? 'appeal_upheld' : 'appeal_denied',
                $upheld ? 'Appeal accepted — second review' : 'Appeal not accepted',
                $upheld ? "Your proof for \"{$title}\" is being reviewed again." : "The rejection of \"{$title}\" stands." . ($appeal->review_notes ? ' ' . $appeal->review_notes : ''),
                '/app/task-history/' . $appeal?->submission?->assignment_id
            ));
        }

        return $response;
=======
    /** POST /contributor/task-history/{id}/appeal { reason } — own task only. */
    public function store(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:10|max:2000'], [
            'reason.min' => 'Explain why the decision is wrong (at least 10 characters).',
        ]);

        $assignment = TaskAssignment::where('user_id', $request->user()->id)->whereKey($id)->firstOrFail();
        $submission = TaskSubmission::where('assignment_id', $assignment->id)->firstOrFail();
        abort_unless(self::canAppeal($submission), 422, $submission->appeal()->exists()
            ? 'You have already appealed this decision.'
            : 'This task can no longer be appealed.');

        $appeal = SubmissionAppeal::create(['submission_id' => $submission->id, 'user_id' => $request->user()->id, 'reason' => $data['reason']]);
        AuditLogger::log($request->user(), 'submission.appeal_opened', TaskSubmission::class, $submission->id, ['appeal_id' => $appeal->id]);

        return response()->json([
            'success' => true,
            'message' => 'Appeal sent. Our review team will look at it again.',
            'data' => ['id' => $appeal->id, 'status' => $appeal->status ?? 'open'],
        ], 201);
    }

    /** GET /admin/submission-appeals?status=open|upheld|denied */
    public function index(Request $request): JsonResponse
    {
        $status = in_array($request->input('status'), ['open', 'upheld', 'denied'], true) ? $request->input('status') : 'open';

        $query = SubmissionAppeal::query()
            ->with(['submission:id,task_id,assignment_id,status', 'submission.task:id,title', 'user:id,name', 'user.profile:id,user_id'])
            ->where('status', $status);
        StaffScope::apply($query, $request->user(), 'submission_appeals.user_id');

        $page = $query->latest('id')->paginate(30);

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn (SubmissionAppeal $a) => [
                'id' => $a->id,
                'uuid' => $a->uuid,
                'submission_id' => $a->submission_id,
                'assignment_id' => $a->submission?->assignment_id,
                'reason' => $a->reason,
                'status' => $a->status,
                'created_at' => $a->created_at,
                'decided_at' => $a->decided_at,
                'review_notes' => $a->review_notes,
                'submission' => $a->submission ? ['id' => $a->submission->id, 'status' => $a->submission->status, 'task' => ['title' => $a->submission->task?->title]] : null,
                'user' => $a->user ? ['name' => $a->user->name] : null,
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** POST /admin/submission-appeals/{id}/decision { decision: uphold|deny, notes } */
    public function decide(Request $request, int $id, VerificationService $verification): JsonResponse
    {
        $data = $request->validate([
            'decision' => 'required|in:uphold,deny',
            'notes' => 'nullable|string|max:1000',
        ]);

        $appeal = SubmissionAppeal::with('submission.task')->findOrFail($id);
        if (!StaffScope::allowsUser($request->user(), $appeal->user_id)) {
            return StaffScope::notFound();
        }
        abort_unless($appeal->status === 'open', 422, 'This appeal was already decided.');

        $uphold = $data['decision'] === 'uphold';
        $notes = trim((string) ($data['notes'] ?? '')) ?: ($uphold ? 'Sent back for a second review.' : 'The original decision stands.');

        if ($uphold) {
            try {
                $verification->reopenOnAppeal($appeal->submission, $request->user(), $notes);
            } catch (\Exception $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
        }

        $appeal->forceFill([
            'status' => $uphold ? 'upheld' : 'denied',
            'review_notes' => $notes,
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
        ])->save();
        AuditLogger::log($request->user(), $uphold ? 'submission.appeal_upheld' : 'submission.appeal_denied', TaskSubmission::class, $appeal->submission_id, ['notes' => $notes]);

        $title = (string) $appeal->submission?->task?->title;
        $appeal->user?->notify(new TaskRewardNotice(
            $uphold ? 'appeal_upheld' : 'appeal_denied',
            $uphold ? 'Appeal accepted — second review' : 'Appeal not accepted',
            $uphold ? "Your proof for \"{$title}\" is being reviewed again. {$notes}" : "The rejection of \"{$title}\" stands: {$notes}",
            '/app/task-history/' . $appeal->submission?->assignment_id
        ));

        return response()->json([
            'success' => true,
            'message' => $uphold ? 'Appeal upheld — the proof is back in the review queue.' : 'Appeal denied — the rejection stands.',
            'data' => ['id' => $appeal->id, 'status' => $appeal->status],
        ]);
>>>>>>> Stashed changes
    }
}
