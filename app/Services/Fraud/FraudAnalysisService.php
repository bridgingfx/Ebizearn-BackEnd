<?php

namespace App\Services\Fraud;

use App\Models\FraudEvent;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskSubmission;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;

/**
 * Thrown by the hard fraud screens at submit time. Carries a machine
 * reason code so the API can answer honestly (409 duplicate, 422 invalid)
 * instead of silently accepting a bad proof.
 */
class FraudRejectionException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $reasonCode,
        public readonly int $httpStatus = 422
    ) {
        parent::__construct($message);
    }
}

class FraudAnalysisService
{
    /**
     * Platform -> acceptable proof-URL host suffixes. A proof URL whose host
     * matches none of the task platform's domains is a wrong_url rejection.
     * The 'web' platform accepts any http(s) URL (surveys, app tests).
     */
    public const PLATFORM_DOMAINS = [
        'instagram' => ['instagram.com'],
        'tiktok' => ['tiktok.com'],
        'facebook' => ['facebook.com', 'fb.com'],
        'x' => ['x.com', 'twitter.com'],
        'youtube' => ['youtube.com', 'youtu.be'],
        'linkedin' => ['linkedin.com'],
        'telegram' => ['t.me', 'telegram.me'],
        'whatsapp' => ['wa.me', 'chat.whatsapp.com'],
        'discord' => ['discord.gg', 'discord.com'],
        'reddit' => ['reddit.com'],
        'ios' => ['apps.apple.com', 'testflight.apple.com'],
        'android' => ['play.google.com'],
        'web' => [], // any http(s) host
    ];

    /**
     * Hard screens that run BEFORE a submission row is created.
     *
     * @param array{url?: ?string, screenshot?: ?string, text_answer?: ?string} $proof
     * @param int|null $excludeSubmissionId Bug 5: on resubmit, the row being
     *                                      resubmitted must not match its own previous proof hash.
     *
     * @throws FraudRejectionException
     */
    public function screenProofOrReject(Task $task, array $proof, Request $request, ?int $excludeSubmissionId = null): array
    {
        $taskType = $task->taskType;
        $required = $task->proof_required_json
            ?? $taskType?->proof_required_json
            ?? [];

        // Legacy campaigns stored proof requirements as an assoc map
        // (['screenshot' => true]); the canonical format is a plain list of
        // names. Normalize so old rows still enforce their requirements —
        // without this, the match() below sees only boolean values and every
        // requirement silently passes.
        if (is_array($required) && !array_is_list($required)) {
            $required = array_keys(array_filter($required));
        }

        // 1. Missing requirements: every proof type the type contract demands
        // must be present.
        $missing = [];
        foreach ((array) $required as $need) {
            $present = match ($need) {
                'screenshot' => !empty($proof['screenshot']),
                'url' => !empty($proof['url']),
                'text' => !empty($proof['text_answer']),
                'file' => !empty($proof['screenshot']) || !empty($proof['url']),
                default => true,
            };

            if (!$present) {
                $missing[] = $need;
            }
        }

        if (!empty($missing)) {
            throw new FraudRejectionException(
                'This task requires: ' . implode(', ', $missing) . '. Please attach the missing proof.',
                'missing_requirements',
                422
            );
        }

        // 2. Wrong URL domain vs the task's platform.
        $url = $proof['url'] ?? null;
        if (!empty($url)) {
            $this->assertUrlMatchesPlatform($url, $task);
        }

        // 3. Duplicate screenshot/proof hash — unique PER CAMPAIGN. The same
        // bytes submitted twice for one campaign (by anyone) are rejected.
        $hash = $this->proofHash($proof);

        if ($hash && $task->campaign_id) {
            $dupe = TaskSubmission::where('proof_hash', $hash)
                ->when($excludeSubmissionId, fn ($q) => $q->where('id', '!=', $excludeSubmissionId))
                ->whereHas('task', fn ($q) => $q->where('campaign_id', $task->campaign_id))
                ->exists();

            if ($dupe) {
                throw new FraudRejectionException(
                    'This exact proof was already submitted for this campaign. Duplicate proofs are not accepted.',
                    'duplicate_proof',
                    409
                );
            }
        }

        return [
            'proof_hash' => $hash,
            'device_fingerprint' => $this->deviceFingerprint($request->ip(), (string) $request->userAgent()),
        ];
    }

    /**
     * SHA-256 over the proof payload. Screenshot content wins (it is the
     * strongest identity); otherwise the normalized URL is hashed.
     */
    public function proofHash(array $proof): ?string
    {
        $screenshot = $proof['screenshot'] ?? null;

        if (!empty($screenshot)) {
            // Strip data-URI prefixes so the same bytes hash identically.
            $raw = preg_replace('#^data:[^,]+,#', '', (string) $screenshot);

            return hash('sha256', $raw);
        }

        $url = $proof['url'] ?? null;

        if (!empty($url)) {
            return hash('sha256', 'url:' . strtolower(trim((string) $url)));
        }

        $text = $proof['text_answer'] ?? null;

        return !empty($text) ? hash('sha256', 'text:' . trim((string) $text)) : null;
    }

    /**
     * Device fingerprint: SHA-256(ip + user_agent). Cheap, privacy-light,
     * and enough to spot one device driving many accounts.
     */
    public function deviceFingerprint(?string $ip, string $userAgent): string
    {
        return hash('sha256', ($ip ?? 'unknown') . '|' . $userAgent);
    }

    /**
     * Confidence per flag code (Bug 12): exact matches are high, rough
     * heuristics are low, so moderators can separate signals from proof.
     */
    public const FLAG_CONFIDENCE = [
        'suspicious_rapid_completion' => 'low',
        'duplicate_url_reused' => 'high',
        'elevated_user_historical_risk' => 'low',
        'shared_device_multiple_accounts' => 'high',
        'velocity_anomaly' => 'low',
    ];

    public static function confidenceFor(string $code): string
    {
        return self::FLAG_CONFIDENCE[$code] ?? 'low';
    }

    /**
     * @throws FraudRejectionException
     */
    public function assertUrlMatchesPlatform(string $url, Task $task): void
    {
        $platform = strtolower((string) ($task->platform ?? 'web'));
        $domains = self::PLATFORM_DOMAINS[$platform] ?? null;

        // Unknown platform: fall back to the task type's allowed platforms.
        if ($domains === null) {
            $allowed = $task->taskType?->allowed_platforms_json ?? [];
            $domains = [];

            foreach ((array) $allowed as $p) {
                foreach (self::PLATFORM_DOMAINS[strtolower((string) $p)] ?? [] as $d) {
                    $domains[] = $d;
                }
            }
        }

        // 'web' (or an empty domain list for a known-web platform) accepts
        // any http(s) URL.
        if ($domains === []) {
            return;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        foreach ($domains as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return;
            }
        }

        throw new FraudRejectionException(
            "This task runs on {$platform} — the proof URL must point to {$platform}, not {$host}.",
            'wrong_url',
            422
        );
    }
    /**
     * Evaluate fraud risk for a submission.
     *
     * @return array{risk_level: string, fraud_score: int, flags: array<string>, flag_details: array<int, array{code: string, confidence: string}>}
     */
    public function evaluateSubmission(TaskSubmission $submission): array
    {
        $flags = [];
        $score = 5; // Base clean score

        // 1. Check completion speed
        if ($submission->assignment) {
            $started = $submission->assignment->started_at;
            $durationSeconds = $started ? $submission->created_at->diffInSeconds($started) : 60;
            $minEstimatedSeconds = ($submission->task->estimated_minutes ?? 5) * 60;

            // Inhumanly fast completion. Bug 12: the old floor (10s / 15%
            // of estimate) tripped legitimately fast completions — 30s and
            // 25% of the estimate.
            if ($durationSeconds < max(30, $minEstimatedSeconds * 0.25)) {
                $flags[] = 'suspicious_rapid_completion';
                $score += 35;
            }
        }

        // 2. Check duplicate URL submissions — but never the task's own
        // target URL: the frontend pre-fills it, so every proof against it
        // would flag by design (Bug 12).
        $submittedUrl = $submission->proof_data_json['url'] ?? null;
        if (!empty($submittedUrl) && !$this->isTaskTargetUrl($submission, $submittedUrl)) {
            $duplicateCount = TaskSubmission::where('id', '!=', $submission->id)
                ->whereJsonContains('proof_data_json->url', $submittedUrl)
                ->count();

            if ($duplicateCount > 0) {
                $flags[] = 'duplicate_url_reused';
                $score += 50;
            }
        }

        // 3. Check user rejection history
        $user = $submission->user;
        if ($user && $user->profile) {
            if ($user->profile->fraud_score > 30) {
                $flags[] = 'elevated_user_historical_risk';
                $score += 20;
            }
        }

        // 4. Duplicate accounts: one device fingerprint driving several
        // user accounts in the last 30 days.
        if (!empty($submission->device_fingerprint)) {
            $accountCount = TaskSubmission::where('device_fingerprint', $submission->device_fingerprint)
                ->where('created_at', '>=', now()->subDays(30))
                ->distinct('user_id')
                ->count('user_id');

            if ($accountCount > 1) {
                $flags[] = 'shared_device_multiple_accounts';
                $score += 40;
            }
        }

        // 5. Velocity / unusual behaviour: burst of submissions in one hour.
        $maxPerHour = (int) \App\Models\PlatformSetting::get('fraud.max_submissions_per_hour', 10);
        $recentCount = TaskSubmission::where('user_id', $submission->user_id)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($recentCount > max(1, $maxPerHour)) {
            $flags[] = 'velocity_anomaly';
            $score += 30;
        }

        // Determine level
        $level = match (true) {
            $score >= 75 => 'critical',
            $score >= 50 => 'high',
            $score >= 25 => 'medium',
            default => 'low',
        };

        // Bug 12: every flag carries a confidence (high|medium|low). `flags`
        // stays a plain code list for existing consumers; `flag_details`
        // carries the code + confidence pairs.
        $flagDetails = array_map(
            fn ($code) => ['code' => $code, 'confidence' => self::confidenceFor($code)],
            $flags
        );

        // Log fraud event if medium or higher
        if (in_array($level, ['medium', 'high', 'critical'], true)) {
            FraudEvent::create([
                'user_id' => $submission->user_id,
                'submission_id' => $submission->id,
                'event_type' => $flags[0] ?? 'anomaly_detected',
                'severity' => $level,
                'details_json' => [
                    'flags' => $flags,
                    'flag_details' => $flagDetails,
                    'calculated_score' => $score,
                    'duration_seconds' => $durationSeconds ?? null,
                ],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'status' => 'flagged',
            ]);
        }

        return [
            'risk_level' => $level,
            'fraud_score' => min(100, $score),
            'flags' => $flags,
            'flag_details' => $flagDetails,
        ];
    }

    /**
     * Bug 12: the task's own target URL (campaign.target_url) is the
     * platform's shared link, not fraud — every contributor submits proof
     * against it by design. Compared normalised (scheme/host lowercased,
     * trailing slash stripped).
     */
    protected function isTaskTargetUrl(TaskSubmission $submission, string $url): bool
    {
        $target = $submission->task?->campaign?->target_url;
        if (empty($target)) {
            return false;
        }

        return self::normalizeUrl($url) === self::normalizeUrl($target);
    }

    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return strtolower(rtrim($url, '/'));
        }
        $normalized = strtolower($parts['scheme'] ?? 'https') . '://' . strtolower($parts['host']);
        if (!empty($parts['port'])) {
            $normalized .= ':' . $parts['port'];
        }
        $normalized .= rtrim($parts['path'] ?? '', '/');
        if (!empty($parts['query'])) {
            $normalized .= '?' . $parts['query'];
        }

        return $normalized;
    }
}
