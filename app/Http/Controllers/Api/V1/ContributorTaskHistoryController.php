<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\TaskAssignment;
use App\Models\TaskSubmission;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Contributor CRM → Task History: every task the signed-in contributor took,
 * and a step-by-step timeline of one task built from real records only
 * (assignment, submission, proof files, automatic checks, review decision,
 * wallet ledger). Always scoped to the signed-in user — another person's
 * task is a plain 404. Staff identities, risk scores and the business's
 * wallet are never exposed here.
 */
class ContributorTaskHistoryController extends Controller
{
    private const FILTERS = [
        'in_progress' => ['assignment' => ['reserved', 'in_progress']],
        'in_review' => ['submission' => ['submitted', 'checking', 'under_review', 'action_required']],
        'pending_reward' => ['reward' => ['pending_duration', 'reverification_required']],
        'completed' => ['submission_or_reward' => true],
        'rejected' => ['submission' => ['rejected']],
        'expired' => ['assignment' => ['expired', 'cancelled'], 'no_submission' => true],
    ];

    /** GET /contributor/task-history?status=&search=&page= */
    public function index(Request $request): JsonResponse
    {
        $base = TaskAssignment::query()->where('task_assignments.user_id', $request->user()->id);

        $query = (clone $base)->with([
            'task' => fn ($q) => $q->withTrashed()->select('id', 'uuid', 'campaign_id', 'category_id', 'title', 'platform', 'reward_cents', 'retention_days'),
            'task.category:id,name',
            'task.campaign' => fn ($q) => $q->withTrashed()->select('id', 'business_id', 'title', 'platform', 'company_name'),
            'task.campaign.business:id,company_name',
            'submission:id,assignment_id,status,reward_status,created_at,reviewed_at,final_check_due_at,proof_data_json',
            'submission.files:id,submission_id,file_url,mime_type',
        ]);

        if ($f = self::FILTERS[$request->input('status')] ?? null) {
            $this->applyFilter($query, $f);
        }
        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $query->whereHas('task', fn ($t) => $t->withTrashed()->where('title', 'like', $term));
        }

        $page = $query->latest('task_assignments.id')->paginate(max(1, min(50, (int) $request->input('per_page', 15))));

        $counts = ['all' => (clone $base)->count()];
        foreach (self::FILTERS as $key => $f) {
            $counts[$key] = $this->applyFilter(clone $base, $f)->count();
        }

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn (TaskAssignment $a) => $this->row($a)),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'counts' => $counts,
            ],
        ]);
    }

    /** GET /contributor/task-history/{id} — the signed-in contributor's own task only. */
    public function show(Request $request, int $id): JsonResponse
    {
        $assignment = TaskAssignment::where('user_id', $request->user()->id)->whereKey($id)
            ->with([
                'task' => fn ($q) => $q->withTrashed(),
                'task.category:id,name',
                'task.campaign' => fn ($q) => $q->withTrashed(),
                'task.campaign.business:id,company_name',
            ])
            ->firstOrFail();

        $submission = TaskSubmission::with(['files', 'aiResult', 'postVerifications'])->where('assignment_id', $assignment->id)->first();
        $task = $assignment->task;
        $campaign = $task?->campaign;
        $ledger = $submission
            ? WalletTransaction::where('reference_type', TaskSubmission::class)
                ->where('reference_id', $submission->id)
                ->whereHas('wallet', fn ($w) => $w->where('user_id', $request->user()->id))
                ->orderBy('id')->get(['id', 'type', 'amount_cents', 'description', 'metadata_json', 'created_at'])
            : collect();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $assignment->id,
                'status' => $submission?->status ?? $assignment->status,
                'reward_status' => $submission?->reward_status,
                'task' => $task ? [
                    'id' => $task->id,
                    'uuid' => $task->uuid,
                    'title' => $task->title,
                    'category' => $task->category?->name,
                    'platform' => $task->platform ?: $campaign?->platform,
                    'reward_cents' => (int) $task->reward_cents,
                    'duration_days' => (int) ($task->retention_days ?? 0),
                    'instructions' => $task->instructions ?: $campaign?->instructions_markdown,
                    'description' => $campaign?->description,
                    'target_url' => $campaign?->target_url,
                    'proof_required' => $task->proof_required_json,
                    'brand' => $campaign?->business?->company_name ?: $campaign?->company_name,
                    'still_available' => !$task->trashed() && $task->status === 'available',
                ] : null,
                'submission' => $submission ? [
                    'id' => $submission->id,
                    'status' => $submission->status,
                    'submitted_at' => $submission->created_at,
                    'reviewed_at' => $submission->reviewed_at,
                    'review_notes' => $submission->review_notes,
                    'proof' => array_intersect_key($submission->proof_data_json ?? [], array_flip(['url', 'text_answer', 'note'])),
                    'files' => $submission->files->map(fn ($f) => ['id' => $f->id, 'url' => $f->file_url, 'mime_type' => $f->mime_type, 'uploaded_at' => $f->created_at]),
                    'reward_status' => $submission->reward_status,
                    'final_check_due_at' => $submission->final_check_due_at,
                    'post_url' => $submission->platform_post_url,
                ] : null,
                'ledger' => $ledger->map(fn ($t) => ['type' => $t->type, 'amount_cents' => (int) $t->amount_cents, 'description' => $t->description, 'created_at' => $t->created_at]),
                'timeline' => $this->timeline($assignment, $submission, $ledger),
            ],
        ]);
    }

    /**
     * Chronological steps from real records. Each: at, kind, title, detail, tone.
     * Reviewer names are never shown — decisions come from "the eBizEarn review team".
     */
    private function timeline(TaskAssignment $a, ?TaskSubmission $s, $ledger): array
    {
        $events = [];
        $add = function ($at, string $kind, string $title, ?string $detail = null, string $tone = 'info') use (&$events) {
            if ($at) {
                $events[] = ['at' => Carbon::parse($at)->toIso8601String(), 'kind' => $kind, 'title' => $title, 'detail' => $detail, 'tone' => $tone];
            }
        };

        $add($a->started_at ?? $a->created_at, 'started', 'Task started', 'You reserved a slot and started the task.');

        if (!$s) {
            if (in_array($a->status, ['expired', 'cancelled'], true)) {
                $add($a->updated_at, 'expired', 'Slot expired', 'No proof was submitted before the slot expired.', 'muted');
            }

            return $this->sorted($events);
        }

        $link = $s->proof_data_json['url'] ?? null;
        $add($s->created_at, 'submitted', 'Proof submitted', $link ? 'Link: ' . $link : null);
        foreach ($s->files as $file) {
            $add($file->created_at ?? $s->created_at, 'evidence', 'Screenshot uploaded', $file->mime_type ? Str::upper(Str::after($file->mime_type, '/')) . ' file' : null);
        }

        // Automatic checks (AI + Instagram API) — outcome and reason only.
        foreach ($s->postVerifications as $v) {
            $title = match ([$v->stage, $v->outcome]) {
                ['initial', 'verified'] => 'Automatic check passed',
                ['initial', 'skipped'] => 'Sent for manual review',
                ['initial', 'inconclusive'], ['initial', 'failed'] => 'Sent for manual review',
                ['final', 'verified'] => 'Final check passed — post still live',
                ['final', 'failed'] => 'Final check failed',
                ['final', 'inconclusive'] => 'Final check could not confirm the post',
                default => 'Verification update',
            };
            $tone = $v->outcome === 'verified' ? 'success' : ($v->outcome === 'failed' ? 'danger' : 'warning');
            $add($v->checked_at, 'verification', $title, $v->reason, $tone);
        }
        if ($s->aiResult && !$s->aiResult->ai_simulated && $s->postVerifications->isEmpty() && !str_contains((string) $s->aiResult->analysis_summary, 'running')) {
            $add($s->aiResult->updated_at, 'verification', 'AI review', $s->aiResult->analysis_summary, 'info');
        }

        if ($s->business_reviewed_at) {
            $add($s->business_reviewed_at, 'business', $s->business_decision === 'approved' ? 'Business recommended approval' : 'Business recommended rejection', null, $s->business_decision === 'approved' ? 'success' : 'warning');
        }

        // Review decisions from the audit trail (each one, in order).
        $decisions = AuditLog::where('entity_type', TaskSubmission::class)->where('entity_id', $s->id)
            ->whereIn('action', ['submission.approved', 'submission.rejected', 'submission.action_required', 'submission.rejected_after_approval_reversed'])
            ->orderBy('created_at')->orderBy('id')->get(['action', 'actor_id', 'after_state_json', 'created_at']);
        foreach ($decisions as $d) {
            $note = $d->after_state_json['review_notes'] ?? null;
            $who = $d->actor_id ? 'by the eBizEarn review team' : 'automatically';
            [$title, $tone] = match ($d->action) {
                'submission.approved' => ['Approved ' . $who, 'success'],
                'submission.action_required' => ['Changes requested ' . $who, 'warning'],
                default => ['Rejected ' . $who, 'danger'],
            };
            $add($d->created_at, 'decision', $title, $note, $tone);
        }
        if ($decisions->isEmpty() && $s->reviewed_at && in_array($s->status, ['approved', 'rejected', 'action_required'], true)) {
            $add($s->reviewed_at, 'decision', ucfirst(str_replace('_', ' ', $s->status)), $s->review_notes, $s->status === 'approved' ? 'success' : ($s->status === 'rejected' ? 'danger' : 'warning'));
        }

        // Money.
        foreach ($ledger as $t) {
            $amount = '$' . number_format(abs((int) $t->amount_cents) / 100, 2);
            match ($t->type) {
                'task_reward' => $add($t->created_at, 'payment', "Reward credited — {$amount}", null, 'success'),
                'rank_bonus' => $add($t->created_at, 'payment', "Rank bonus — {$amount}", null, 'success'),
                'retention_hold' => $add($t->created_at, 'payment', "{$amount} moved to your pending balance",
                    !empty($t->metadata_json['release_at']) ? 'Held until ' . Carbon::parse($t->metadata_json['release_at'])->toDayDateTimeString() . ' (task duration ' . ($t->metadata_json['retention_days'] ?? '?') . ' days).' : null, 'info'),
                'retention_release' => $add($t->created_at, 'payment', "{$amount} released to your available balance", null, 'success'),
                'retention_hold_cancel' => $add($t->created_at, 'payment', "Pending reward cancelled — {$amount}", null, 'danger'),
                'task_reward_reversal', 'rank_bonus_reversal' => $add($t->created_at, 'payment', "Reward reversed — {$amount}", null, 'danger'),
                default => null,
            };
        }

        if ($s->reward_status === 'pending_duration' && $s->final_check_due_at) {
            $add($s->final_check_due_at, 'scheduled', 'Final check scheduled', 'Keep your post live until then — the reward is released after this check.', 'muted');
        }
        if ($s->reward_status === 'refunded') {
            $add($s->final_checked_at ?? $s->reviewed_at, 'refund', 'Reward returned to the business', $s->review_notes, 'danger');
        }

        $final = match (true) {
            $s->reward_status === 'released' || ($s->status === 'approved' && $s->reward_status === null) => ['Task completed', 'success'],
            $s->reward_status === 'refunded' => ['Task closed — reward refunded', 'danger'],
            $s->status === 'rejected' => ['Task closed — rejected', 'danger'],
            default => null,
        };
        if ($final) {
            $last = collect($events)->max('at');
            $add($s->final_checked_at ?? $last ?? $s->reviewed_at, 'final', $final[0], null, $final[1]);
        }

        return $this->sorted($events);
    }

    private function sorted(array $events): array
    {
        // Stable by time; "final" always last.
        usort($events, fn ($x, $y) => [$x['kind'] === 'final', $x['at']] <=> [$y['kind'] === 'final', $y['at']]);

        return $events;
    }

    private function applyFilter(Builder $q, array $f): Builder
    {
        if (!empty($f['submission_or_reward'])) {
            return $q->whereHas('submission', fn ($s) => $s->where(fn ($w) => $w->where('reward_status', 'released')
                ->orWhere(fn ($x) => $x->where('status', 'approved')->whereNull('reward_status'))));
        }
        if (isset($f['reward'])) {
            return $q->whereHas('submission', fn ($s) => $s->whereIn('reward_status', $f['reward']));
        }
        if (isset($f['submission'])) {
            return $q->whereHas('submission', fn ($s) => $s->whereIn('status', $f['submission']));
        }
        $q->whereIn('task_assignments.status', $f['assignment']);

        return !empty($f['no_submission']) ? $q->whereDoesntHave('submission') : $q;
    }

    private function row(TaskAssignment $a): array
    {
        $s = $a->submission;
        $task = $a->task;

        return [
            'id' => $a->id,
            'status' => $s?->status ?? $a->status,
            'reward_status' => $s?->reward_status,
            'started_at' => $a->started_at ?? $a->created_at,
            'submitted_at' => $s?->created_at,
            'reviewed_at' => $s?->reviewed_at,
            'final_check_due_at' => $s?->final_check_due_at,
            'task' => $task ? [
                'id' => $task->id,
                'uuid' => $task->uuid,
                'title' => $task->title,
                'category' => $task->category?->name,
                'platform' => $task->platform ?: $task->campaign?->platform,
                'reward_cents' => (int) $task->reward_cents,
                'brand' => $task->campaign?->business?->company_name ?: $task->campaign?->company_name,
            ] : null,
            'proof' => [
                'has_link' => !empty($s?->proof_data_json['url']),
                'files' => $s?->files?->count() ?? 0,
                'thumb' => $s?->files?->first(fn ($f) => str_starts_with((string) $f->mime_type, 'image/'))?->file_url,
            ],
        ];
    }
}
