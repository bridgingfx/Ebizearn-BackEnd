<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SubmissionAppeal;
use App\Models\TaskAssignment;
use App\Models\TaskSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
 * - An upheld appeal moves rejected -> under_review with a full audit trail.
 *   It never auto-approves and never touches the wallet — the normal review
 *   path (VerificationService::recordDecision) still decides and pays.
 */
class SubmissionAppealController extends Controller
{
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

        $alreadyOpen = SubmissionAppeal::where('submission_id', $submission->id)
            ->where('status', SubmissionAppeal::STATUS_OPEN)
            ->exists();

        if ($alreadyOpen) {
            return response()->json([
                'success' => false,
                'code' => 'appeal_open',
                'message' => 'You already have an open appeal for this submission.',
            ], 409);
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

        if (in_array($status, [SubmissionAppeal::STATUS_OPEN, SubmissionAppeal::STATUS_UPHELD, SubmissionAppeal::STATUS_DENIED], true)) {
            $query->where('status', $status);
        }

        $appeals = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $appeals->items(),
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
    public function decide(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:uphold,deny'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return DB::transaction(function () use ($request, $id, $data) {
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
                // nothing is auto-approved and no wallet moves here.
                $submission->update([
                    'status' => 'under_review',
                    'verification_stage' => 'moderator_review',
                    'reviewer_id' => null,
                    'reviewed_at' => null,
                ]);
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
    }
}
