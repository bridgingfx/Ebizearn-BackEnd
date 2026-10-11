<?php

namespace App\Services\Fraud;

use App\Models\FraudEvent;
use App\Models\SocialChannel;
use App\Models\SubmissionFile;
use App\Models\TaskSubmission;
use Illuminate\Support\Facades\Storage;

/**
 * Fraud & risk assessment for one submission (Verification Agent, step 1).
 *
 * Adds to the submit-time screens (FraudAnalysisService: duplicate proof per
 * campaign, too-fast completion, shared device, hourly velocity):
 *
 * - reused_image        the same picture (perceptual fingerprint) was already
 *                       submitted by ANOTHER account — strong signal
 * - reused_own_image    the contributor re-used their own screenshot for a different task
 * - account_switching   the proof shows a different social account than the
 *                       one the contributor connected, or many different
 *                       accounts across recent proofs
 * - coordinated_abuse   3+ accounts submitting for the same campaign from one
 *                       device or IP within 24 hours
 *
 * A high score never rejects anything by itself — it stops automatic
 * approval and tells the reviewer why.
 */
class RiskEngine
{
    private const LABELS = [
        'reused_image' => 'Same screenshot already submitted by another account',
        'reused_own_image' => 'Screenshot re-used from your own earlier task',
        'account_switching' => 'Proof shows a different social account',
        'many_accounts_in_proofs' => 'Proofs show many different social accounts',
        'coordinated_abuse' => 'Several accounts submitting from one device / IP',
        'suspicious_rapid_completion' => 'Completed unusually fast',
        'duplicate_url_reused' => 'Proof link already used in another submission',
        'shared_device_multiple_accounts' => 'Device used by several accounts',
        'velocity_anomaly' => 'Unusually many submissions in an hour',
        'elevated_user_historical_risk' => 'Account has earlier risk flags',
    ];

    private const WEIGHTS = [
        'reused_image' => 60,
        'reused_own_image' => 25,
        'account_switching' => 35,
        'many_accounts_in_proofs' => 25,
        'coordinated_abuse' => 45,
    ];

    /** Score at or above which the agent never approves automatically. */
    public const BLOCK_AUTO_APPROVE = 50;

    /**
     * @return array{score: int, level: string, flags: array<int, array{code: string, label: string, detail: ?string}>, blocks_auto_approval: bool}
     */
    public function assess(TaskSubmission $submission): array
    {
        $submission->loadMissing(['files', 'task.campaign', 'aiResult']);
        $flags = [];

        // Submit-time screens (already scored by FraudAnalysisService).
        $earlier = $submission->aiResult?->raw_payload_json['fraud_analysis'] ?? null;
        $score = (int) ($earlier['fraud_score'] ?? 0);
        foreach ((array) ($earlier['flags'] ?? []) as $code) {
            $flags[$code] = null;
        }

        foreach ($this->imageFlags($submission) as $code => $detail) {
            $flags[$code] = $detail;
            $score += self::WEIGHTS[$code];
        }
        foreach ($this->accountFlags($submission) as $code => $detail) {
            $flags[$code] = $detail;
            $score += self::WEIGHTS[$code];
        }
        if ($detail = $this->coordinatedAbuse($submission)) {
            $flags['coordinated_abuse'] = $detail;
            $score += self::WEIGHTS['coordinated_abuse'];
        }

        $score = min(100, $score);
        $level = match (true) {
            $score >= 75 => 'critical',
            $score >= 50 => 'high',
            $score >= 25 => 'medium',
            default => 'low',
        };

        $new = array_intersect_key($flags, self::WEIGHTS);
        if ($new && in_array($level, ['medium', 'high', 'critical'], true)) {
            FraudEvent::create([
                'user_id' => $submission->user_id,
                'submission_id' => $submission->id,
                'event_type' => array_key_first($new),
                'severity' => $level,
                'details_json' => ['flags' => array_keys($flags), 'score' => $score, 'details' => array_filter($new)],
                'status' => 'flagged',
            ]);
        }

        return [
            'score' => $score,
            'level' => $level,
            'flags' => collect($flags)->map(fn ($detail, $code) => ['code' => $code, 'label' => self::LABELS[$code] ?? str_replace('_', ' ', $code), 'detail' => $detail])->values()->all(),
            'blocks_auto_approval' => $score >= self::BLOCK_AUTO_APPROVE || isset($flags['reused_image']) || isset($flags['coordinated_abuse']),
        ];
    }

    /** Fingerprint this submission's screenshots and look for the same picture elsewhere. */
    private function imageFlags(TaskSubmission $submission): array
    {
        $out = [];
        foreach ($submission->files as $file) {
            if (!$file->phash) {
                $bytes = $this->bytes($file);
                $hash = $bytes ? ImageFingerprint::hash($bytes) : null;
                if (!$hash) {
                    continue;
                }
                $file->forceFill(['phash' => $hash])->saveQuietly();
            }

            $matches = SubmissionFile::query()
                ->join('task_submissions', 'task_submissions.id', '=', 'submission_files.submission_id')
                ->whereNotNull('submission_files.phash')
                ->where('submission_files.submission_id', '!=', $submission->id)
                ->where('submission_files.created_at', '>=', now()->subDays(180))
                ->limit(20000)
                ->get(['submission_files.phash', 'task_submissions.user_id', 'task_submissions.id as sid']);

            foreach ($matches as $m) {
                if (ImageFingerprint::distance($file->phash, $m->phash) > ImageFingerprint::SAME_IMAGE_DISTANCE) {
                    continue;
                }
                if ((int) $m->user_id !== (int) $submission->user_id) {
                    $out['reused_image'] = 'Matches submission #' . $m->sid . ' from another account.';
                    break 2;
                }
                $out['reused_own_image'] ??= 'Matches your submission #' . $m->sid . '.';
            }
        }

        return $out;
    }

    /** The account shown in the proof vs. the one connected; many accounts across proofs. */
    private function accountFlags(TaskSubmission $submission): array
    {
        $platform = strtolower((string) ($submission->task?->platform ?: $submission->task?->campaign?->platform));
        $handle = self::handleFromUrl((string) ($submission->proof_data_json['url'] ?? ''));
        if (!$handle || $platform === '') {
            return [];
        }

        $out = [];
        $channel = SocialChannel::where('user_id', $submission->user_id)->where('platform', $platform)
            ->whereIn('status', ['verified', 'pending'])->first();
        $own = strtolower((string) ($channel?->oauth_username ?: $channel?->handle));
        if ($own !== '' && ltrim($own, '@') !== $handle) {
            $out['account_switching'] = "Proof shows @{$handle}, but the connected {$platform} account is @" . ltrim($own, '@') . '.';
        }

        $recent = TaskSubmission::where('user_id', $submission->user_id)
            ->where('id', '!=', $submission->id)
            ->where('created_at', '>=', now()->subDays(30))
            ->pluck('proof_data_json')
            ->map(fn ($p) => self::handleFromUrl((string) (is_array($p) ? ($p['url'] ?? '') : '')))
            ->filter()
            ->push($handle)
            ->unique();
        if ($recent->count() >= 3) {
            $out['many_accounts_in_proofs'] = 'Last 30 days: ' . $recent->map(fn ($h) => '@' . $h)->implode(', ') . '.';
        }

        return $out;
    }

    /** 3+ accounts on one campaign from the same device or IP within 24h. */
    private function coordinatedAbuse(TaskSubmission $submission): ?string
    {
        $campaignId = $submission->task?->campaign_id;
        if (!$campaignId) {
            return null;
        }
        $ip = $submission->proof_data_json['submitted_ip'] ?? null;
        $peers = TaskSubmission::query()
            ->whereHas('task', fn ($t) => $t->where('campaign_id', $campaignId))
            ->where('created_at', '>=', now()->subDay())
            ->where(function ($q) use ($submission, $ip) {
                if ($submission->device_fingerprint) {
                    $q->orWhere('device_fingerprint', $submission->device_fingerprint);
                }
                if ($ip) {
                    $q->orWhere('proof_data_json->submitted_ip', $ip);
                }
            })
            ->when(!$submission->device_fingerprint && !$ip, fn ($q) => $q->whereRaw('1 = 0'))
            ->distinct()
            ->count('user_id');

        return $peers >= 3 ? "{$peers} accounts submitted for this campaign from the same device or network in 24 hours." : null;
    }

    /** "alice" from instagram.com/alice, x.com/alice/status/1, tiktok.com/@alice/video/1, youtube.com/@alice… */
    public static function handleFromUrl(string $url): ?string
    {
        if (!preg_match('#^https?://(?:www\.|m\.|mobile\.)?([a-z.]+)/([^?\#]*)#i', trim($url), $m)) {
            return null;
        }
        $host = strtolower($m[1]);
        $parts = array_values(array_filter(explode('/', $m[2])));
        $first = strtolower(ltrim($parts[0] ?? '', '@'));
        $reserved = ['p', 'reel', 'reels', 'tv', 'stories', 'watch', 'shorts', 'channel', 'c', 'user', 'i', 'intent', 'share', 'groups', 'permalink.php', 'profile.php', 'video', 'explore', 'hashtag', 'search', 'home', 'in', 'company', 'pages', 'posts', 'status'];

        if ($first === '' || in_array($first, $reserved, true)) {
            return $first === 'stories' && isset($parts[1]) ? strtolower($parts[1]) : null;
        }
        $known = ['instagram.com', 'x.com', 'twitter.com', 'tiktok.com', 'youtube.com', 'facebook.com', 'threads.net', 'linkedin.com'];

        return in_array($host, $known, true) && preg_match('/^[a-z0-9._-]{2,40}$/', $first) ? $first : null;
    }

    private function bytes(SubmissionFile $file): ?string
    {
        $path = (string) $file->file_path;
        if ($path !== '' && Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->get($path);
        }
        if (preg_match('#^data:image/[\w.+-]+;base64,(.+)$#s', (string) $file->file_url, $m)) {
            return base64_decode($m[1]) ?: null;
        }

        return null;
    }
}
