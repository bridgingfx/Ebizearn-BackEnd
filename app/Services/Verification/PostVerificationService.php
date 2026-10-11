<?php

namespace App\Services\Verification;

use App\Models\AiVerificationResult;
use App\Models\Campaign;
use App\Models\PostVerification;
use App\Models\SocialChannel;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Notifications\TaskRewardNotice;
use App\Services\AI\ProofAiReviewer;
use App\Services\Audit\AuditLogger;
use App\Services\Email\EmailService;
use App\Services\Fraud\RiskEngine;
use App\Services\Social\InstagramGraphClient;
use App\Services\Social\YouTubeActionClient;
use App\Services\Wallet\WalletLedgerService;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Automatic proof verification and the pending-reward lifecycle.
 *
 * 1. Initial check (right after submit): the AI reads the screenshot; for
 *    Instagram post links the official Instagram API confirms the post is on
 *    the contributor's connected account, published after they took the
 *    task, with the required text. API + AI agree → approved automatically.
 *    Anything uncertain stays in the manual review queue — a screenshot
 *    alone never approves a task.
 * 2. Approval (automatic or by staff): the reward is credited to the
 *    contributor's PENDING balance for the task duration (VerificationService
 *    retention hold) and the funding source is recorded.
 * 3. Final check (end of the duration, `retention:release`): post still
 *    there → reward released to available; deleted → reward refunded to the
 *    business that funded it; can't tell → retry, then manual review.
 *
 * Release and refund are idempotent and lock the submission row, so
 * repeated jobs, callbacks or clicks can never pay or refund twice.
 */
class PostVerificationService
{
    private static bool $kickScheduled = false;

    public function __construct(
        private InstagramGraphClient $instagram,
        private ProofAiReviewer $ai,
        private WalletLedgerService $ledger,
        private RiskEngine $risk,
        private YouTubeActionClient $youtube,
    ) {
    }

    // ------------------------------------------------------------------
    // 1. Initial automatic check
    // ------------------------------------------------------------------

    /** Is there anything to check automatically for this submission? */
    public function shouldAutoVerify(TaskSubmission $submission): bool
    {
        // The agent's risk engine runs for every proof; the platform API and
        // the AI join in when they apply / are set up.
        return true;
    }

    /** Target channel link when the task is "subscribe to this YouTube channel". */
    public function youtubeSubscribeTarget(TaskSubmission $submission): ?string
    {
        $task = $submission->task;
        $platform = strtolower((string) ($task?->platform ?: $task?->campaign?->platform));
        $text = strtolower(($task?->title ?? '') . ' ' . ($task?->taskType?->key ?? '') . ' ' . ($task?->campaign?->title ?? ''));
        $url = (string) ($task?->campaign?->target_url ?? '');

        return $platform === 'youtube' && str_contains($text, 'subscrib') && preg_match('#youtube\.com/(channel/UC|@)#i', $url) ? $url : null;
    }

    /** Real AI review is on (provider auto / ai) and an AI account is set up. */
    private function aiEnabled(): bool
    {
        return in_array(config('verification.ai_provider'), ['auto', 'ai'], true) && $this->ai->ready();
    }

    /** Mark for checking and run it once the HTTP response has been sent. */
    public function queue(TaskSubmission $submission): void
    {
        if (!$this->shouldAutoVerify($submission)) {
            return;
        }
        $submission->forceFill(['auto_verify_status' => 'pending'])->saveQuietly();

        if (self::$kickScheduled) {
            return;
        }
        self::$kickScheduled = true;
        app()->terminating(function () {
            self::$kickScheduled = false;
            ignore_user_abort(true);
            @set_time_limit(0);
            try {
                app(self::class)->processPending(120);
            } catch (Throwable $e) {
                Log::error('Automatic proof verification failed', ['error' => $e->getMessage()]);
            }
        });
    }

    /** Check pending submissions until done or out of time (also run by the scheduler). */
    public function processPending(int $seconds = 50): int
    {
        $deadline = microtime(true) + $seconds;
        $done = 0;
        while (microtime(true) < $deadline) {
            $id = TaskSubmission::where('auto_verify_status', 'pending')->orderBy('id')->value('id');
            if (!$id) {
                break;
            }
            $this->verifyInitial(TaskSubmission::findOrFail($id));
            $done++;
        }

        return $done;
    }

    /**
     * Run the initial check for one submission. Returns the recorded outcome.
     */
    public function verifyInitial(TaskSubmission $submission): ?PostVerification
    {
        // Claim it: only one worker checks a submission.
        $claimed = TaskSubmission::whereKey($submission->id)->where('auto_verify_status', 'pending')
            ->update(['auto_verify_status' => 'running']);
        if (!$claimed) {
            return null;
        }

        try {
            $submission = TaskSubmission::with(['task.campaign.business', 'assignment', 'files', 'user'])->findOrFail($submission->id);

            // 1. Fraud & risk (reused images, account switching, coordinated abuse…)
            $risk = $this->risk->assess($submission);

            // 2. Platform API: Instagram post, or YouTube subscription.
            $igUrl = $this->instagramUrl($submission);
            $ytTarget = $igUrl ? null : $this->youtubeSubscribeTarget($submission);
            $api = $igUrl ? $this->checkInstagramPost($submission, $igUrl) : ($ytTarget ? $this->checkYouTubeSubscribe($submission, $ytTarget) : null);
            $ai = $this->aiEnabled()
                ? $this->ai->review($submission, $api['post'] ?? null)
                : ['available' => false, 'error' => 'disabled', 'model' => null, 'is_proof' => null, 'confidence' => 0, 'matches_api_post' => null,
                    'looks_fake' => false, 'issues' => [], 'requirements_met' => [], 'summary' => 'AI review is off (AI_VERIFICATION_PROVIDER).', 'raw' => null];
            $this->storeAiResult($submission, $ai, $api, $risk);

            [$outcome, $reason, $approve] = $this->decide($submission, $api, $ai, $risk);

            // A confirmed subscription is checked again at the end of the duration.
            if (($api['kind'] ?? null) === 'youtube_subscribe' && $api['outcome'] === 'ok') {
                $submission->forceFill([
                    'platform_media_id' => 'yt-sub:' . $api['target_channel_id'],
                    'platform_post_url' => $ytTarget,
                    'platform_posted_at' => $api['subscribed_at'] ? Carbon::parse($api['subscribed_at']) : null,
                ])->saveQuietly();
            }

            if (!empty($api['post'])) {
                $submission->forceFill([
                    'platform_media_id' => (string) $api['post']['id'],
                    'platform_post_url' => (string) ($api['post']['permalink'] ?? $igUrl),
                    'platform_posted_at' => isset($api['post']['timestamp']) ? Carbon::parse($api['post']['timestamp']) : null,
                ])->saveQuietly();
            }

            $record = $this->record($submission, 'initial', $outcome, $reason, $api, $ai, null, $risk);

            if ($approve && in_array($submission->fresh()->status, ['under_review', 'submitted'], true)) {
                $this->autoApprove($submission, $reason);
            } elseif ($api || $risk['blocks_auto_approval']) {
                AuditLogger::log(null, 'submission.sent_to_manual_review', TaskSubmission::class, $submission->id, ['reason' => $reason]);
            }

            $submission->forceFill(['auto_verify_status' => 'done', 'auto_verified_at' => now()])->saveQuietly();

            return $record;
        } catch (Throwable $e) {
            Log::error('Proof verification crashed', ['submission' => $submission->id, 'error' => $e->getMessage()]);
            $submission->forceFill(['auto_verify_status' => 'done', 'auto_verified_at' => now()])->saveQuietly();

            return $this->record($submission, 'initial', 'inconclusive', 'Automatic check failed — needs a person to check.', null, null);
        }
    }

    /**
     * Official Instagram API check of the linked post.
     * @return array{outcome: string, connected: bool, post: ?array, checks: array, error: ?string, account: ?string}
     */
    private function checkInstagramPost(TaskSubmission $submission, string $url): array
    {
        $channel = $this->instagram->channelFor($submission->user_id);
        if (!$channel) {
            return ['outcome' => 'not_connected', 'connected' => false, 'post' => null, 'checks' => ['account_connected' => false],
                'error' => 'The contributor has not connected an Instagram professional account.', 'account' => null];
        }

        $found = $this->instagram->findByPermalink($channel, $url);
        $checks = ['account_connected' => true, 'post_found' => $found['outcome'] === 'ok'];
        $post = $found['post'];

        if ($post) {
            $checks += $this->postChecks($submission, $channel, $post);
        }

        return ['outcome' => $found['outcome'], 'connected' => true, 'post' => $post, 'checks' => $checks,
            'error' => $found['error'], 'account' => $channel->oauth_username ?: $channel->handle];
    }

    /**
     * Official YouTube API: is the contributor's connected channel subscribed to the target?
     * @return array{kind: string, outcome: string, connected: bool, post: null, checks: array, error: ?string, account: ?string, target_channel_id: ?string, subscribed_at: ?string}
     */
    private function checkYouTubeSubscribe(TaskSubmission $submission, string $targetUrl): array
    {
        $channel = $this->youtube->channelFor($submission->user_id);
        if (!$channel) {
            return ['kind' => 'youtube_subscribe', 'outcome' => 'not_connected', 'connected' => false, 'post' => null, 'checks' => ['account_connected' => false],
                'error' => 'The contributor has not connected YouTube.', 'account' => null, 'target_channel_id' => null, 'subscribed_at' => null];
        }

        $res = $this->youtube->checkSubscribed($channel, $targetUrl);
        $startedAt = $submission->assignment?->started_at ?? $submission->assignment?->created_at;

        return [
            'kind' => 'youtube_subscribe',
            'outcome' => $res['outcome'],
            'connected' => true,
            'post' => null,
            'checks' => [
                'account_connected' => true,
                'subscribed' => $res['outcome'] === 'ok',
                // Subscribed after taking the task (an older subscription isn't new work).
                'subscribed_after_start' => $res['subscribed_at'] && $startedAt ? Carbon::parse($res['subscribed_at'])->gte(Carbon::parse($startedAt)->subMinutes(10)) : null,
            ],
            'error' => $res['error'],
            'account' => $channel->oauth_username ?: $channel->handle,
            'target_channel_id' => $res['target_channel_id'],
            'subscribed_at' => $res['subscribed_at'],
        ];
    }

    /** Account identity, publish time and caption vs. the task. */
    private function postChecks(TaskSubmission $submission, SocialChannel $channel, array $post): array
    {
        $owner = strtolower((string) ($channel->oauth_username ?: $channel->handle));
        $postUser = strtolower((string) ($post['username'] ?? ''));
        $startedAt = $submission->assignment?->started_at ?? $submission->assignment?->created_at ?? $submission->created_at;
        $postedAt = isset($post['timestamp']) ? Carbon::parse($post['timestamp']) : null;
        $required = $this->requiredText($submission);
        $captionScore = $required ? $this->captionScore((string) ($post['caption'] ?? ''), $required) : null;

        return [
            'account_match' => $postUser === '' || $postUser === $owner,
            // Published after the task was taken (10 min of clock slack).
            'published_after_start' => $postedAt ? $postedAt->gte(Carbon::parse($startedAt)->subMinutes(10)) : false,
            'caption_score' => $captionScore,
            'caption_match' => $captionScore === null ? null : $captionScore >= (int) config('verification.instagram.min_caption_match', 60),
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: bool} outcome, reason, auto-approve?
     */
    private function decide(TaskSubmission $submission, ?array $api, array $ai, ?array $risk = null): array
    {
        $riskNote = $risk && $risk['flags'] ? ' Risk ' . $risk['level'] . ' (' . $risk['score'] . '): ' . collect($risk['flags'])->pluck('label')->implode('; ') . '.' : '';
        $cfg = config('verification.instagram');
        $minAi = (int) ($cfg['min_ai_confidence'] ?? 80);
        $aiOk = $ai['available'] && $ai['is_proof'] === true && !$ai['looks_fake']
            && $ai['confidence'] >= $minAi && $ai['matches_api_post'] !== false && empty($ai['evidence']['cropped']);
        $aiNo = $ai['available'] && $ai['is_proof'] === false && $ai['confidence'] >= $minAi;

        // Not an Instagram post task: the AI only advises the reviewer.
        if ($api === null) {
            if (!$ai['available']) {
                return [$risk && $risk['blocks_auto_approval'] ? 'inconclusive' : 'skipped', ($ai['summary'] ?: 'No automatic check for this task — manual review.') . $riskNote, false];
            }

            return [$aiOk && !($risk['blocks_auto_approval'] ?? false) ? 'verified' : ($aiNo ? 'failed' : 'inconclusive'), 'AI: ' . $ai['summary'] . ' — a reviewer makes the decision.' . $riskNote, false];
        }

        $checks = $api['checks'];
        $problems = [];
        $isYouTube = ($api['kind'] ?? null) === 'youtube_subscribe';
        if ($isYouTube) {
            if (!$api['connected']) {
                $problems[] = 'YouTube account not connected';
            } elseif ($api['outcome'] === 'token_invalid') {
                $problems[] = 'YouTube connection expired — the contributor must reconnect';
            } elseif ($api['outcome'] === 'unavailable') {
                $problems[] = 'YouTube API: ' . ($api['error'] ?? 'unavailable');
            } elseif (empty($checks['subscribed'])) {
                $problems[] = 'the connected channel is not subscribed to the target channel';
            } elseif (($checks['subscribed_after_start'] ?? null) === false) {
                $problems[] = 'the subscription is older than the task (not new)';
            }
        } elseif (!$api['connected']) {
            $problems[] = 'Instagram account not connected';
        } elseif ($api['outcome'] === 'token_invalid') {
            $problems[] = 'Instagram connection expired — the contributor must reconnect';
        } elseif ($api['outcome'] === 'unavailable') {
            $problems[] = 'Instagram API unavailable';
        } elseif (empty($checks['post_found'])) {
            $problems[] = 'post not found on the connected account';
        } else {
            if (empty($checks['account_match'])) {
                $problems[] = 'post belongs to a different account';
            }
            if (empty($checks['published_after_start'])) {
                $problems[] = 'post was published before the task was taken';
            }
            if (($checks['caption_match'] ?? null) === false) {
                $problems[] = 'caption does not contain the required text (' . $checks['caption_score'] . '% match)';
            }
        }

        $apiOk = empty($problems);
        if ($apiOk && !$aiOk) {
            $problems[] = $ai['available'] ? 'AI is not sure the screenshot matches (' . $ai['confidence'] . '%)' : 'AI review unavailable';
        }
        if ($risk && $risk['blocks_auto_approval']) {
            $problems[] = 'risk ' . $risk['level'] . ': ' . collect($risk['flags'])->pluck('label')->implode(', ');
        }

        if ($apiOk && ($aiOk || empty($cfg['require_ai'])) && !($risk['blocks_auto_approval'] ?? false)) {
            $reason = $isYouTube
                ? 'YouTube API confirmed @' . $api['account'] . ' subscribed to the channel'
                : 'Instagram API confirmed the post on @' . $api['account'];
            $reason .= ($aiOk ? ' and the AI matched the screenshot (' . $ai['confidence'] . '%)' : '') . '.';

            return ['verified', $reason, (bool) ($cfg['auto_approve'] ?? true)];
        }

        $definite = $api['connected'] && $api['outcome'] === 'not_found';

        return [$definite || $aiNo ? 'failed' : 'inconclusive', 'Manual review: ' . implode('; ', $problems) . '.', false];
    }

    private function autoApprove(TaskSubmission $submission, string $reason): void
    {
        $updated = app(VerificationService::class)->recordDecision($submission, null, 'approved', 'verified', 'Automatically verified — ' . $reason);

        $task = $updated->task;
        app(EmailService::class)->sendEvent('task_approved', $updated->user->email, [
            'user_name' => $updated->user->name,
            'task_title' => (string) $task?->title,
            'amount' => 'USD ' . number_format(((int) $task?->reward_cents) / 100, 2),
            'reason' => $reason,
        ]);
    }

    private function storeAiResult(TaskSubmission $submission, array $ai, ?array $api, ?array $risk = null): void
    {
        // Keep the submit-time fraud screens next to the agent's risk result.
        $previous = AiVerificationResult::where('submission_id', $submission->id)->value('raw_payload_json');
        $previous = is_array($previous) ? $previous : (json_decode((string) $previous, true) ?: []);
        if ($risk) {
            AiVerificationResult::where('submission_id', $submission->id)->update([
                'raw_payload_json' => json_encode(array_merge($previous, ['risk' => $risk])),
            ]);
        }
        if (!$ai['available']) {
            // No AI verdict — keep the pre-check row; the Instagram result is
            // in post_verifications (shown next to it in Task History).
            return;
        }

        $conf = $ai['available'] ? $ai['confidence'] : 0;
        $match = $ai['is_proof'] === true ? $conf : ($ai['is_proof'] === false ? 100 - $conf : 0);
        $riskScore = max((int) ($risk['score'] ?? 0), $ai['looks_fake'] ? 85 : ($ai['is_proof'] === false ? max(60, $conf) : max(0, 100 - $conf)));
        $suggest = !$ai['available'] ? 'flag'
            : ($ai['is_proof'] && !$ai['looks_fake'] && $conf >= (int) config('verification.instagram.min_ai_confidence', 80) ? 'approve'
                : ($ai['is_proof'] === false && $conf >= 80 ? 'reject' : 'flag'));

        AiVerificationResult::updateOrCreate(['submission_id' => $submission->id], [
            'confidence_score' => $conf,
            'risk_score' => $riskScore,
            'duplicate_risk' => 0,
            'proof_quality' => $ai['available'] ? ($ai['looks_fake'] ? 20 : 80) : 0,
            'content_match' => $match,
            'policy_match' => $ai['looks_fake'] ? 20 : 90,
            'suggested_decision' => $suggest,
            'analysis_summary' => trim($ai['summary'] . ($api ? ' · Instagram API: ' . ($api['error'] ?? 'post confirmed') : '')),
            'raw_payload_json' => [
                'fraud_analysis' => $previous['fraud_analysis'] ?? null,
                'risk' => $risk,
                'provider' => $ai['model'] ?? 'none',
                'issues' => $ai['issues'],
                'requirements_met' => $ai['requirements_met'],
                'seen' => $ai['raw'],
                'instagram' => $api ? ['outcome' => $api['outcome'], 'checks' => $api['checks']] : null,
            ],
            'ai_simulated' => false,
            'ai_label' => $ai['available'] ? 'AI review (' . $ai['model'] . ')' . ($api ? ' + Instagram API' : '') : ($api ? 'Instagram API (AI unavailable)' : 'Manual review'),
        ]);
    }

    // ------------------------------------------------------------------
    // 2. Approval bookkeeping (called by VerificationService::applyApproval)
    // ------------------------------------------------------------------

    /** Record where the reward came from and when it may be released. */
    public static function recordApproval(TaskSubmission $submission, ?Campaign $campaign, ?WalletTransaction $hold, ?string $releaseAt): void
    {
        $ownerId = $campaign?->business?->owner_id;
        $ownerWallet = $ownerId ? Wallet::where('user_id', $ownerId)->first() : null;

        $submission->forceFill([
            'funding_type' => $ownerWallet ? 'business_wallet' : 'platform',
            'funding_user_id' => $ownerId,
            'funding_wallet_id' => $ownerWallet?->id,
            'funding_reference' => $campaign ? 'campaign:' . $campaign->id : null,
            'hold_tx_id' => $hold?->id,
            'reward_status' => $hold ? 'pending_duration' : 'released',
            'final_check_due_at' => $releaseAt ? Carbon::parse($releaseAt) : null,
            'final_check_attempts' => 0,
        ])->saveQuietly();
    }

    // ------------------------------------------------------------------
    // 3. Final check, release, refund
    // ------------------------------------------------------------------

    /**
     * End-of-duration check for an approved Instagram post submission.
     * Returns verified | refunded | retry | manual_review | skipped.
     */
    public function finalCheck(TaskSubmission $submission, ?User $actor = null): string
    {
        $submission = $submission->fresh(['task.campaign.business', 'user']);
        if ($submission->status !== 'approved' || !in_array($submission->reward_status, ['pending_duration', 'reverification_required'], true)) {
            return 'skipped';
        }

        if (str_starts_with((string) $submission->platform_media_id, 'yt-sub:')) {
            return $this->finalYouTubeCheck($submission, $actor);
        }

        $channel = $this->instagram->channelFor($submission->user_id);
        $api = ['outcome' => 'not_connected', 'post' => null, 'checks' => ['account_connected' => (bool) $channel], 'error' => 'Instagram account disconnected.'];

        if ($channel) {
            $res = $this->instagram->media($channel, (string) $submission->platform_media_id);
            // A "not found" by id is confirmed against the account's post list
            // before anything is refunded.
            if ($res['outcome'] === 'not_found' && $submission->platform_post_url) {
                $res = $this->instagram->findByPermalink($channel, $submission->platform_post_url);
            }
            $api = ['outcome' => $res['outcome'], 'post' => $res['post'], 'checks' => ['account_connected' => true, 'post_found' => $res['outcome'] === 'ok'], 'error' => $res['error']];
            if ($res['post']) {
                $api['checks'] += $this->postChecks($submission, $channel, $res['post']);
            }
        }

        $checks = $api['checks'];
        $stillQualifies = $api['outcome'] === 'ok' && !empty($checks['account_match']) && ($checks['caption_match'] ?? null) !== false;

        if ($stillQualifies) {
            $this->record($submission, 'final', 'verified', 'Post still live on Instagram at the end of the task duration.', $api, null, $actor);
            $this->releaseReward($submission, $actor, 'Final check passed — post still live.');

            return 'verified';
        }

        $deleted = $api['outcome'] === 'not_found'
            || ($api['outcome'] === 'ok' && (empty($checks['account_match']) || ($checks['caption_match'] ?? null) === false));
        if ($deleted) {
            $why = $api['outcome'] === 'not_found' ? 'Post was deleted or archived before the end of the task duration.' : 'Post was changed and no longer meets the task requirements.';
            $this->record($submission, 'final', 'failed', $why, $api, null, $actor);
            $this->refundReward($submission, $actor, $why);

            return 'refunded';
        }

        return $this->retryOrEscalate($submission, $api, $actor);
    }

    /** End of duration for a subscribe task: still subscribed → pay; unsubscribed → refund. */
    private function finalYouTubeCheck(TaskSubmission $submission, ?User $actor): string
    {
        $channel = $this->youtube->channelFor($submission->user_id);
        $api = ['kind' => 'youtube_subscribe', 'outcome' => 'not_connected', 'post' => null, 'checks' => ['account_connected' => (bool) $channel], 'error' => 'YouTube account disconnected.'];
        if ($channel) {
            $res = $this->youtube->checkSubscribed($channel, (string) $submission->platform_post_url);
            $api = ['kind' => 'youtube_subscribe', 'outcome' => $res['outcome'], 'post' => null, 'checks' => ['account_connected' => true, 'subscribed' => $res['outcome'] === 'ok'], 'error' => $res['error']];
        }

        if ($api['outcome'] === 'ok') {
            $this->record($submission, 'final', 'verified', 'Still subscribed at the end of the task duration.', $api, null, $actor);
            $this->releaseReward($submission, $actor, 'Final check passed — still subscribed.');

            return 'verified';
        }
        if ($api['outcome'] === 'not_found') {
            $why = 'Unsubscribed from the channel before the end of the task duration.';
            $this->record($submission, 'final', 'failed', $why, $api, null, $actor);
            $this->refundReward($submission, $actor, $why);

            return 'refunded';
        }

        return $this->retryOrEscalate($submission, $api, $actor);
    }

    /** Couldn't confirm either way: never assume the worst — retry, then a person decides. */
    private function retryOrEscalate(TaskSubmission $submission, array $api, ?User $actor): string
    {
        $attempts = $submission->final_check_attempts + 1;
        $max = (int) config('verification.instagram.final_check_max_attempts', 5);
        $why = 'Could not confirm the post (' . ($api['error'] ?? $api['outcome']) . ') — attempt ' . $attempts . ' of ' . $max . '.';
        $this->record($submission, 'final', 'inconclusive', $why, $api, null, $actor);

        if ($attempts >= $max) {
            $submission->forceFill(['final_check_attempts' => $attempts, 'reward_status' => 'reverification_required'])->saveQuietly();
            AuditLogger::log($actor, 'submission.reverification_required', TaskSubmission::class, $submission->id, ['reason' => $why]);
            $submission->user?->notify(new TaskRewardNotice('reward_review', 'Reward check needs attention',
                'We could not confirm your task "' . $submission->task?->title . '" automatically. Check your social account connection in your profile — our team will review it.', '/app/profile?tab=socials'));

            return 'manual_review';
        }

        $submission->forceFill([
            'final_check_attempts' => $attempts,
            'final_check_due_at' => now()->addHours(max(1, (int) config('verification.instagram.final_check_retry_hours', 6))),
        ])->saveQuietly();

        return 'retry';
    }

    /**
     * Pending → available, exactly once. Shares the idempotency key with the
     * retention:release command, so the two can never both pay.
     */
    public function releaseReward(TaskSubmission $submission, ?User $actor = null, string $note = ''): TaskSubmission
    {
        return DB::transaction(function () use ($submission, $actor, $note) {
            $locked = TaskSubmission::whereKey($submission->id)->lockForUpdate()->firstOrFail();
            if ($locked->reward_status === 'released') {
                return $locked;
            }
            if ($locked->status !== 'approved' || $locked->reward_status === 'refunded') {
                throw new Exception('This reward can no longer be released.');
            }

            $lastRelease = null;
            foreach ($this->openHolds($locked) as $hold) {
                $lastRelease = $this->ledger->releaseHold(
                    Wallet::findOrFail($hold->wallet_id),
                    abs((int) $hold->amount_cents),
                    'retention_release',
                    "Retention matured — reward released (submission #{$locked->id})",
                    $hold->reference_type,
                    $hold->reference_id,
                    ['hold_transaction_id' => $hold->id],
                    "retention-release-{$hold->id}"
                );
            }

            $locked->forceFill([
                'reward_status' => 'released',
                'release_tx_id' => $lastRelease?->id ?? $locked->release_tx_id,
                'final_checked_at' => now(),
            ])->saveQuietly();

            AuditLogger::log($actor, 'submission.reward_released', TaskSubmission::class, $locked->id, ['note' => $note]);

            $task = $locked->task;
            $amount = '$' . number_format(((int) $task?->reward_cents) / 100, 2);
            $locked->user?->notify(new TaskRewardNotice('reward_released', 'Reward released',
                "{$amount} for \"{$task?->title}\" is now in your available balance.", '/app/wallet'));
            $this->businessOwner($locked)?->notify(new TaskRewardNotice('reward_released', 'Task completed',
                "{$locked->user?->name}'s post for \"{$task?->title}\" passed the final check — reward paid.", '/business/submissions'));

            return $locked->fresh();
        });
    }

    /**
     * Cancel the pending reward and return it to the account that funded it
     * (the campaign's business wallet), exactly once.
     */
    public function refundReward(TaskSubmission $submission, ?User $actor = null, string $reason = 'Post removed'): TaskSubmission
    {
        return DB::transaction(function () use ($submission, $actor, $reason) {
            $locked = TaskSubmission::whereKey($submission->id)->lockForUpdate()->firstOrFail();
            if ($locked->reward_status === 'refunded') {
                return $locked;
            }
            if ($locked->reward_status === 'released') {
                throw new Exception('This reward was already released to the contributor.');
            }
            if ($locked->status !== 'approved') {
                throw new Exception('Only approved rewards can be refunded.');
            }

            // Reverses the approval: cancels the pending holds, returns the
            // reward to the business escrow and the campaign pool, reverses
            // referral rewards (VerificationService::reverseApproval).
            app(VerificationService::class)->recordDecision($locked, $actor, 'rejected', 'post_removed', $reason);

            $refundTx = WalletTransaction::where('type', 'campaign_funding')
                ->where('reference_type', TaskSubmission::class)
                ->where('reference_id', $locked->id)
                ->where('metadata_json->escrow_restore', true)
                ->latest('id')
                ->first();

            // Campaign already over: the business can't spend it any more, so
            // the escrow goes back to its available balance.
            $campaign = $locked->task?->campaign;
            if ($refundTx && $campaign && !in_array($campaign->status, ['active', 'paused', 'pending_review'], true)) {
                // reverseApproval put the reward back into the campaign pool; the
                // campaign is over, so it leaves the pool and goes to the business.
                Campaign::whereKey($campaign->id)->lockForUpdate()->first()
                    ?->decrement('remaining_budget_cents', min((int) $campaign->fresh()->remaining_budget_cents, (int) $refundTx->amount_cents));
                $refundTx = $this->ledger->releaseHold(
                    Wallet::findOrFail($refundTx->wallet_id),
                    (int) $refundTx->amount_cents,
                    'campaign_refund',
                    "Refund — task reward not earned (submission #{$locked->id}): {$reason}",
                    TaskSubmission::class,
                    $locked->id,
                    ['campaign_id' => $campaign->id],
                    "post-refund-{$locked->id}"
                );
            }

            $locked->forceFill([
                'reward_status' => 'refunded',
                'refund_tx_id' => $refundTx?->id,
                'final_checked_at' => now(),
            ])->saveQuietly();

            AuditLogger::log($actor, 'submission.reward_refunded', TaskSubmission::class, $locked->id, [
                'reason' => $reason,
                'funding_user_id' => $locked->funding_user_id,
                'refund_tx_id' => $refundTx?->id,
            ]);

            $task = $locked->task;
            $locked->user?->notify(new TaskRewardNotice('reward_refunded', 'Reward cancelled',
                "The reward for \"{$task?->title}\" was cancelled: {$reason}", '/app/my-tasks'));
            $this->businessOwner($locked)?->notify(new TaskRewardNotice('reward_refunded', 'Reward refunded to you',
                "The reward for \"{$task?->title}\" ({$locked->user?->name}) was returned to your funds: {$reason}", '/business/billing'));

            return $locked->fresh();
        });
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /** Retention holds for this submission that were neither released nor cancelled. */
    private function openHolds(TaskSubmission $submission)
    {
        return WalletTransaction::where('type', 'retention_hold')
            ->where('reference_type', TaskSubmission::class)
            ->where('reference_id', $submission->id)
            ->get()
            ->reject(fn (WalletTransaction $hold) => WalletTransaction::where('wallet_id', $hold->wallet_id)
                ->whereIn('type', ['retention_release', 'retention_hold_cancel'])
                ->where('metadata_json->hold_transaction_id', $hold->id)
                ->exists());
    }

    private function businessOwner(TaskSubmission $submission): ?User
    {
        $id = $submission->funding_user_id ?: $submission->task?->campaign?->business?->owner_id;

        return $id ? User::find($id) : null;
    }

    public function instagramUrl(TaskSubmission $submission): ?string
    {
        $url = (string) ($submission->proof_data_json['url'] ?? '');

        return InstagramGraphClient::shortcode($url) ? $url : null;
    }

    /** The text the contributor had to post (their own AI version, else the campaign text). */
    private function requiredText(TaskSubmission $submission): ?string
    {
        $text = trim((string) ($submission->assignment?->content ?: $submission->task?->campaign?->generated_content));

        return $text !== '' ? $text : null;
    }

    /**
     * How much of the required text the caption carries (0–100): hashtags
     * must all appear; the words are compared as a set (order / emoji /
     * punctuation ignored).
     */
    public function captionScore(string $caption, string $required): int
    {
        $norm = fn (string $s) => preg_split('/\s+/u', trim(preg_replace('/[^\p{L}\p{N}#\s]+/u', ' ', mb_strtolower($s))), -1, PREG_SPLIT_NO_EMPTY);
        $want = array_unique(array_filter($norm($required), fn ($w) => mb_strlen($w) > 2 || str_starts_with($w, '#')));
        if (empty($want)) {
            return 100;
        }
        $have = array_flip($norm($caption));

        $tags = array_filter($want, fn ($w) => str_starts_with($w, '#'));
        foreach ($tags as $tag) {
            if (!isset($have[$tag])) {
                return min(50, (int) round(100 * count(array_filter($want, fn ($w) => isset($have[$w]))) / count($want)));
            }
        }

        return (int) round(100 * count(array_filter($want, fn ($w) => isset($have[$w]))) / count($want));
    }

    private function record(TaskSubmission $submission, string $stage, string $outcome, string $reason, ?array $api, ?array $ai, ?User $actor = null, ?array $risk = null): PostVerification
    {
        $post = $api['post'] ?? null;

        return PostVerification::create([
            'submission_id' => $submission->id,
            'stage' => $stage,
            'outcome' => $outcome,
            'reason' => mb_substr($reason, 0, 500),
            'api_checks_json' => $api ? ['outcome' => $api['outcome'], 'error' => $api['error'] ?? null] + ($api['checks'] ?? []) : null,
            'ai_json' => $ai ? array_intersect_key($ai, array_flip(['available', 'model', 'is_proof', 'confidence', 'matches_api_post', 'looks_fake', 'issues', 'requirements_met', 'summary', 'error', 'evidence'])) : null,
            // Post facts only — never tokens or raw API envelopes.
            'api_meta_json' => $post ? array_intersect_key($post, array_flip(['id', 'permalink', 'timestamp', 'username', 'media_type', 'caption'])) : null,
            'actor_id' => $actor?->id,
            'checked_at' => now(),
            'risk_json' => $risk,
        ]);
    }
}
