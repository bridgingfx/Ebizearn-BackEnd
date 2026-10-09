<?php

namespace App\Services\Tasks;

use App\Models\Campaign;
use App\Models\EmailTemplate;
use App\Models\Task;
use App\Models\User;
use App\Services\Email\EmailService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * Emails every eligible contributor when a task goes live — whoever created
 * it (business, admin or super admin).
 *
 * "Live" means what the contributor feed shows (TaskController::index): the
 * task is available, its campaign is active and its post content (if any) is
 * approved. Each task is announced once: tasks.announce_status moves
 * null → pending → sending → done. Pausing and resuming never re-announces.
 *
 * There is no queue worker, so sending happens in batches after the HTTP
 * response is sent, and `tasks:announce-new` (every minute) finishes any
 * task that is still pending. A row lock on the task hands each batch of
 * contributors to exactly one sender, so nobody gets the email twice.
 */
class NewTaskAnnouncer
{
    public const EVENT_KEY = 'new_task_available';
    public const BATCH_SIZE = 25;

    private static bool $kickScheduled = false;

    public function __construct(private EmailService $emails)
    {
    }

    /** Wire the model listeners (called from AppServiceProvider). */
    public static function register(): void
    {
        Task::saved(fn (Task $task) => self::queueTask($task));
        Campaign::saved(fn (Campaign $campaign) => self::queueCampaign($campaign));
    }

    public static function campaignIsLive(?Campaign $campaign): bool
    {
        return $campaign && $campaign->status === 'active' && $campaign->contentReady();
    }

    private static function queueTask(Task $task): void
    {
        if ($task->status !== 'available' || $task->announce_status !== null) {
            return;
        }
        if (!self::campaignIsLive(Campaign::find($task->campaign_id))) {
            return;
        }

        $marked = Task::whereKey($task->id)->whereNull('announce_status')->update(['announce_status' => 'pending']);
        if ($marked) {
            $task->announce_status = 'pending';
            self::kickAfterResponse();
        }
    }

    private static function queueCampaign(Campaign $campaign): void
    {
        if (!self::campaignIsLive($campaign)) {
            return;
        }

        $marked = Task::where('campaign_id', $campaign->id)
            ->where('status', 'available')
            ->whereNull('announce_status')
            ->update(['announce_status' => 'pending']);

        if ($marked) {
            self::kickAfterResponse();
        }
    }

    /** Start sending once the current request has answered the user. */
    private static function kickAfterResponse(): void
    {
        if (self::$kickScheduled) {
            return;
        }
        self::$kickScheduled = true;

        app()->terminating(function () {
            self::$kickScheduled = false;
            ignore_user_abort(true);
            @set_time_limit(0);
            try {
                app(self::class)->processPending(90);
            } catch (Throwable $e) {
                Log::error('New task announcement failed', ['error' => $e->getMessage()]);
            }
        });
    }

    /**
     * Send pending announcements until done or the time budget runs out.
     * Returns how many emails were attempted.
     */
    public function processPending(int $seconds = 50): int
    {
        $deadline = microtime(true) + $seconds;
        $attempted = 0;

        $template = EmailTemplate::where('event_key', self::EVENT_KEY)->first();
        if (!$template || !$template->is_enabled) {
            // Email switched off in Super Admin: close the queue so it doesn't pile up.
            Task::whereIn('announce_status', ['pending', 'sending'])->update(['announce_status' => 'skipped']);
            return 0;
        }

        while (microtime(true) < $deadline) {
            $taskId = Task::whereIn('announce_status', ['pending', 'sending'])->orderBy('id')->value('id');
            if (!$taskId) {
                break;
            }

            [$task, $users] = $this->claimBatch($taskId);
            if (!$task) {
                continue;
            }

            $variables = $this->taskVariables($task);
            foreach ($users as $user) {
                $this->emails->sendEvent(self::EVENT_KEY, $user->email, array_merge($variables, [
                    'user_name' => trim(explode(' ', (string) $user->name)[0] ?? '') ?: 'there',
                    'unsubscribe_url' => rtrim((string) config('app.url'), '/')
                        . URL::signedRoute('email.unsubscribe', ['user' => $user->id], null, false),
                ]));
                $attempted++;
            }
        }

        return $attempted;
    }

    /**
     * Lock the task, take the next contributors after its cursor and move the
     * cursor past them. Returns [task, users]; task is null when there is
     * nothing left to send (or the task stopped being live).
     *
     * @return array{0: ?Task, 1: Collection<int, User>}
     */
    private function claimBatch(int $taskId): array
    {
        return DB::transaction(function () use ($taskId) {
            $task = Task::with('campaign.business')->whereKey($taskId)->lockForUpdate()->first();
            if (!$task || !in_array($task->announce_status, ['pending', 'sending'], true)) {
                return [null, collect()];
            }

            // Paused / cancelled before we got to it — stop, don't advertise a dead task.
            if ($task->status !== 'available' || !self::campaignIsLive($task->campaign)) {
                $task->forceFill(['announce_status' => 'skipped'])->saveQuietly();
                return [null, collect()];
            }

            $users = $this->audience($task->campaign)
                ->where('users.id', '>', (int) $task->announce_cursor)
                ->orderBy('users.id')
                ->limit(self::BATCH_SIZE)
                ->get(['users.id', 'users.name', 'users.email']);

            $done = $users->count() < self::BATCH_SIZE;
            $task->forceFill([
                'announce_status' => $done ? 'done' : 'sending',
                'announce_cursor' => $users->last()?->id ?? $task->announce_cursor,
                'announced_at' => $done ? now() : $task->announced_at,
            ])->saveQuietly();

            return [$task, $users];
        });
    }

    /**
     * Contributors who can see the task in their feed: active, verified email,
     * not unsubscribed, and in a country the campaign targets.
     */
    public function audience(Campaign $campaign): Builder
    {
        $countries = Campaign::normalizeTargetCountries($campaign->target_countries_json);

        return User::query()
            ->where('users.role', 'contributor')
            ->where('users.status', 'active')
            ->whereNotNull('users.email_verified_at')
            ->whereNull('users.marketing_unsubscribed_at')
            ->when(!in_array('ALL', $countries, true), fn (Builder $q) => $q->whereHas(
                'profile',
                fn (Builder $p) => $p->whereIn('country_code', $countries)
            ));
    }

    /** Placeholder values for the email (escaped by EmailService::render). */
    public function taskVariables(Task $task): array
    {
        $campaign = $task->campaign;
        $site = rtrim((string) config('platform.frontendUrl'), '/');
        $description = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($campaign?->description ?: $task->instructions ?: ''))));

        return [
            'task_title' => (string) $task->title,
            'task_description' => $description !== '' ? Str::limit($description, 280) : 'Open the task to see the full instructions.',
            'task_reward' => '$' . number_format(((int) $task->reward_cents) / 100, 2),
            'task_platform' => (string) ($task->platform ?: $campaign?->platform ?: 'Online'),
            'brand_name' => (string) ($task->company_name ?: $campaign?->company_name ?: $campaign?->business?->company_name ?: config('app.name')),
            'task_minutes' => (string) ((int) $task->estimated_minutes ?: 5),
            'task_slots' => (string) max(0, (int) $task->slots_total - (int) $task->slots_taken),
            'task_url' => $site . '/app/tasks/' . ($task->uuid ?: $task->id),
        ];
    }
}
