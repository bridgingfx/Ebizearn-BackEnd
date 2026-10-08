<?php

namespace App\Services\Audit;

use App\Models\Campaign;
use App\Models\Profile;
use App\Models\SupportTicket;
use App\Models\Task;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Models\WithdrawalRequest;

/**
 * Records platform activity that is not an explicit audited action, so the
 * audit trail — and the staff notification feed built on it — shows every
 * new sign-up, task, campaign, proof submission, withdrawal and ticket.
 * Wired up as `created` listeners in AppServiceProvider.
 */
class ActivityRecorder
{
    public static function register(): void
    {
        // A profile is created once the account's role is set (sign-up,
        // social sign-up, staff-created business, team member).
        Profile::created(function (Profile $profile) {
            $user = User::find($profile->user_id);
            if (!$user) {
                return;
            }
            $action = $user->business_owner_id ? 'user.team_member_joined' : 'user.registered';
            self::log(auth()->user() ?? $user, $action, User::class, $user->id, ['role' => $user->role]);
        });

        TaskSubmission::created(fn (TaskSubmission $s) => self::log(
            User::find($s->user_id), 'submission.created', TaskSubmission::class, $s->id, ['task_id' => $s->task_id]
        ));

        WithdrawalRequest::created(fn (WithdrawalRequest $w) => self::log(
            User::find($w->user_id), 'withdrawal.requested', User::class, $w->user_id,
            ['withdrawal_id' => $w->id, 'amount_cents' => $w->amount_cents ?? null]
        ));

        Campaign::created(fn (Campaign $c) => self::log(
            auth()->user(), 'campaign.created', Campaign::class, $c->id, ['business_id' => $c->business_id]
        ));

        Task::created(fn (Task $t) => self::log(
            auth()->user(), 'task.created', Task::class, $t->id, ['campaign_id' => $t->campaign_id]
        ));

        SupportTicket::created(fn (SupportTicket $t) => self::log(
            User::find($t->user_id), 'support_ticket.created', SupportTicket::class, $t->id, ['category' => $t->category ?? null]
        ));
    }

    /** Never let activity logging break the action that triggered it. */
    private static function log(?User $actor, string $action, string $type, $id, array $meta = []): void
    {
        try {
            AuditLogger::log($actor, $action, $type, $id, $meta);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
