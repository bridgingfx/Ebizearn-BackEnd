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
     *
     * @throws FraudRejectionException
     */
    public function screenProofOrReject(Task $task, array $proof, Request $request): array
    {
        $taskType = $task->taskType;
        $required = $task->proof_required_json
            ?? $taskType?->proof_required_json
            ?? [];

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
     * @return array{risk_level: string, fraud_score: int, flags: array<string>}
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

            // Inhumanly fast completion (e.g. less than 15% of expected time)
            if ($durationSeconds < max(10, $minEstimatedSeconds * 0.15)) {
                $flags[] = 'suspicious_rapid_completion';
                $score += 35;
            }
        }

        // 2. Check duplicate URL submissions
        $submittedUrl = $submission->proof_data_json['url'] ?? null;
        if (!empty($submittedUrl)) {
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

        // Log fraud event if medium or higher
        if (in_array($level, ['medium', 'high', 'critical'], true)) {
            FraudEvent::create([
                'user_id' => $submission->user_id,
                'submission_id' => $submission->id,
                'event_type' => $flags[0] ?? 'anomaly_detected',
                'severity' => $level,
                'details_json' => [
                    'flags' => $flags,
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
        ];
    }
}
