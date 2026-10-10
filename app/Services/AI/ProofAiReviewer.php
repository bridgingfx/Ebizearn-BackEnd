<?php

namespace App\Services\AI;

use App\Models\TaskSubmission;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Real AI review of a task proof: the AI (OpenAI / Gemini vision, as set in
 * Admin → Settings → AI) looks at the contributor's screenshot and compares
 * it with the task requirements — and, for Instagram post tasks, with what
 * the official Instagram API returned for the post.
 *
 * The AI never logs in anywhere and never fetches Instagram itself: it only
 * reads the evidence the backend gives it. Its verdict ADVISES — approval
 * rules live in PostVerificationService.
 */
class ProofAiReviewer
{
    private const SYSTEM = <<<'TXT'
You check proof screenshots for eBizEarn, a platform that pays people to complete social media tasks.
You receive the task requirements, optionally the data the platform's official API returned for the post, and one or more screenshots the person uploaded.
Decide whether the screenshot genuinely shows the required task completed. Be strict: if the screenshot is unclear, cropped, unrelated, shows a different account or post, or looks edited, say so.
Answer with JSON only, exactly these keys:
{"is_proof_of_task": true|false, "confidence": 0-100, "platform_seen": string|null, "account_handle_seen": string|null, "post_text_seen": string|null, "matches_api_post": true|false|null, "looks_edited_or_fake": true|false, "requirements_met": [string], "issues": [string], "summary": string}
"confidence" is how sure you are about "is_proof_of_task". "matches_api_post" is null when no API data was given. Keep "summary" under 400 characters.
TXT;

    public function __construct(private AiClient $ai)
    {
    }

    public function ready(): bool
    {
        return $this->ai->ready();
    }

    /**
     * @param array|null $apiPost what the Instagram API returned (caption, username, permalink, timestamp, media_url…)
     * @return array{available: bool, error: ?string, model: ?string, is_proof: ?bool, confidence: int, matches_api_post: ?bool,
     *               looks_fake: bool, issues: string[], requirements_met: string[], summary: string, raw: ?array}
     */
    public function review(TaskSubmission $submission, ?array $apiPost = null): array
    {
        $images = $this->screenshots($submission);
        if (!empty($apiPost['media_url']) && ($apiPost['media_type'] ?? 'IMAGE') !== 'VIDEO') {
            $images[] = (string) $apiPost['media_url']; // the real post image, to compare with the screenshot
        }

        $base = [
            'available' => false, 'error' => null, 'model' => null, 'is_proof' => null, 'confidence' => 0,
            'matches_api_post' => null, 'looks_fake' => false, 'issues' => [], 'requirements_met' => [], 'summary' => '', 'raw' => null,
        ];

        if (!$this->ai->ready()) {
            return ['error' => 'not_configured', 'summary' => 'AI is not set up (Admin → Settings → AI), so the screenshot was not analysed.'] + $base;
        }
        if (empty($images)) {
            return ['error' => 'no_screenshot', 'summary' => 'No screenshot to analyse.'] + $base;
        }

        $res = $this->ai->vision(self::SYSTEM, $this->prompt($submission, $apiPost), array_slice($images, 0, 4));
        if (!$res['ok']) {
            return ['error' => $res['error'], 'model' => $this->ai->describe(), 'summary' => 'AI review failed (' . $res['error'] . ') — needs a person to check.'] + $base;
        }

        $j = $res['json'];

        return [
            'available' => true,
            'error' => null,
            'model' => $this->ai->describe(),
            'is_proof' => isset($j['is_proof_of_task']) ? (bool) $j['is_proof_of_task'] : null,
            'confidence' => max(0, min(100, (int) ($j['confidence'] ?? 0))),
            'matches_api_post' => array_key_exists('matches_api_post', $j) && $j['matches_api_post'] !== null ? (bool) $j['matches_api_post'] : null,
            'looks_fake' => (bool) ($j['looks_edited_or_fake'] ?? false),
            'issues' => array_values(array_map('strval', array_slice((array) ($j['issues'] ?? []), 0, 10))),
            'requirements_met' => array_values(array_map('strval', array_slice((array) ($j['requirements_met'] ?? []), 0, 10))),
            'summary' => Str::limit((string) ($j['summary'] ?? ''), 500),
            'raw' => array_intersect_key($j, array_flip(['platform_seen', 'account_handle_seen', 'post_text_seen'])),
        ];
    }

    private function prompt(TaskSubmission $submission, ?array $apiPost): string
    {
        $task = $submission->task;
        $campaign = $task?->campaign;
        $proof = $submission->proof_data_json ?? [];
        $postText = $submission->assignment?->content ?: $campaign?->generated_content;

        $lines = [
            'TASK',
            'Title: ' . ($task?->title ?? '—'),
            'Platform: ' . ($task?->platform ?: $campaign?->platform ?: 'unknown'),
            'Instructions: ' . Str::limit(strip_tags((string) ($task?->instructions ?: $campaign?->instructions_markdown ?: $campaign?->description)), 1200),
        ];
        if ($postText) {
            $lines[] = 'Text the person had to post: ' . Str::limit((string) $postText, 1200);
        }
        if (!empty($campaign?->target_url)) {
            $lines[] = 'Target link: ' . $campaign->target_url;
        }
        $lines[] = '';
        $lines[] = 'PERSON SUBMITTED';
        $lines[] = 'Link: ' . ($proof['url'] ?? '—');
        if (!empty($proof['text_answer'])) {
            $lines[] = 'Answer: ' . Str::limit((string) $proof['text_answer'], 500);
        }
        if (!empty($proof['note'])) {
            $lines[] = 'Note: ' . Str::limit((string) $proof['note'], 300);
        }

        if ($apiPost) {
            $lines[] = '';
            $lines[] = 'OFFICIAL API DATA FOR THE POST (trusted)';
            $lines[] = 'Account: @' . ($apiPost['username'] ?? '?');
            $lines[] = 'Published: ' . ($apiPost['timestamp'] ?? '?');
            $lines[] = 'Permalink: ' . ($apiPost['permalink'] ?? '?');
            $lines[] = 'Caption: ' . Str::limit((string) ($apiPost['caption'] ?? ''), 1500);
            $lines[] = 'The last image (if any) is the real post image from the API.';
        }

        $lines[] = '';
        $lines[] = 'The first image(s) are the person\'s screenshot(s). Return the JSON.';

        return implode("\n", $lines);
    }

    /** Screenshots as data URLs (works with private / localhost storage) or https URLs. */
    private function screenshots(TaskSubmission $submission): array
    {
        $out = [];
        foreach ($submission->files as $file) {
            if (!str_starts_with((string) $file->mime_type, 'image/') && !preg_match('/\.(png|jpe?g|webp|gif)$/i', (string) $file->file_path)) {
                continue;
            }
            $path = (string) $file->file_path;
            if ($path !== '' && Storage::disk('public')->exists($path)) {
                $out[] = 'data:' . ($file->mime_type ?: 'image/png') . ';base64,' . base64_encode(Storage::disk('public')->get($path));
            } elseif (str_starts_with((string) $file->file_url, 'data:image/')) {
                $out[] = (string) $file->file_url;
            } elseif (preg_match('#^https://#i', (string) $file->file_url)) {
                $out[] = (string) $file->file_url;
            }
        }

        return $out;
    }
}
