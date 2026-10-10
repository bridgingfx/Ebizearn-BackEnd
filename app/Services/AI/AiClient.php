<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One way to talk to the AI service Super Admin chose (Google Gemini or
 * OpenAI): write text, and check text for offensive content.
 */
class AiClient
{
    /** Gemini safety filters at their strictest — content is posted publicly. */
    private const GEMINI_SAFETY = [
        ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_LOW_AND_ABOVE'],
        ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_LOW_AND_ABOVE'],
        ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_LOW_AND_ABOVE'],
        ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_LOW_AND_ABOVE'],
    ];

    private const CATEGORY_LABELS = [
        'harassment' => 'insulting or harassing language',
        'hate' => 'hateful language',
        'hate_speech' => 'hateful language',
        'sexual' => 'sexual content',
        'sexually_explicit' => 'sexual content',
        'violence' => 'violent content',
        'dangerous_content' => 'harmful content',
        'self-harm' => 'self-harm content',
        'illicit' => 'illegal activity',
    ];

    public function __construct(private AiSettings $settings)
    {
    }

    public function ready(): bool
    {
        return $this->settings->ready();
    }

    /**
     * True when the provider screens every request and answer itself
     * (Gemini with our strictest safety settings) — AI-written text then
     * needs no extra moderation call.
     */
    public function screensOwnOutput(): bool
    {
        return $this->settings->provider() === 'gemini';
    }

    /**
     * Write text. Returns ['ok' => bool, 'text' => ?string, 'blocked' => bool, 'error' => ?string].
     * `blocked` = the provider's own safety filter refused the request or answer.
     */
    public function chat(string $system, string $user, float $temperature = 0.7, int $maxTokens = 500): array
    {
        if (!$this->settings->ready()) {
            return ['ok' => false, 'text' => null, 'blocked' => false, 'error' => 'not_configured'];
        }

        try {
            return $this->settings->provider() === 'gemini'
                ? $this->geminiChat($system, $user, $temperature, $maxTokens)
                : $this->openAiChat($system, $user, $temperature, $maxTokens);
        } catch (\Throwable $e) {
            Log::warning('AI request failed', ['provider' => $this->settings->provider(), 'error' => $e->getMessage()]);

            return ['ok' => false, 'text' => null, 'blocked' => false, 'error' => 'unavailable'];
        }
    }

    /**
     * Look at images and answer in JSON (proof screenshots, post images).
     * $images: data URLs ("data:image/png;base64,…") or https URLs.
     * Returns ['ok' => bool, 'json' => ?array, 'text' => ?string, 'error' => ?string].
     *
     * @param string[] $images
     */
    public function vision(string $system, string $prompt, array $images, int $maxTokens = 700): array
    {
        if (!$this->settings->ready()) {
            return ['ok' => false, 'json' => null, 'text' => null, 'error' => 'not_configured'];
        }

        try {
            $res = $this->settings->provider() === 'gemini'
                ? $this->geminiVision($system, $prompt, $images, $maxTokens)
                : $this->openAiVision($system, $prompt, $images, $maxTokens);
        } catch (\Throwable $e) {
            Log::warning('AI vision request failed', ['provider' => $this->settings->provider(), 'error' => $e->getMessage()]);

            return ['ok' => false, 'json' => null, 'text' => null, 'error' => 'unavailable'];
        }

        if (!$res['ok']) {
            return $res + ['json' => null];
        }

        $json = $this->decodeJson((string) $res['text']);

        return $json === null
            ? ['ok' => false, 'json' => null, 'text' => $res['text'], 'error' => 'unreadable_answer']
            : ['ok' => true, 'json' => $json, 'text' => $res['text'], 'error' => null];
    }

    /** Provider + model actually used (stored with each AI verdict). */
    public function describe(): string
    {
        return $this->settings->provider() . ':' . $this->settings->model();
    }

    private function openAiVision(string $system, string $prompt, array $images, int $maxTokens): array
    {
        $content = [['type' => 'text', 'text' => $prompt]];
        foreach ($images as $url) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $url, 'detail' => 'high']];
        }

        $res = Http::withToken($this->settings->apiKey())->timeout(60)->acceptJson()
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->settings->model(),
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $content],
                ],
                'max_tokens' => $maxTokens,
                'temperature' => 0,
                'response_format' => ['type' => 'json_object'],
            ]);

        if (!$res->successful()) {
            return ['ok' => false, 'text' => null, 'error' => $this->errorMessage($res->json(), $res->status())];
        }

        return ['ok' => true, 'text' => trim((string) $res->json('choices.0.message.content')), 'error' => null];
    }

    private function geminiVision(string $system, string $prompt, array $images, int $maxTokens): array
    {
        $parts = [['text' => $prompt]];
        foreach ($images as $url) {
            $inline = $this->inlineImage($url);
            if ($inline) {
                $parts[] = ['inline_data' => $inline];
            }
        }

        $model = $this->settings->model();
        $res = Http::withHeaders(['x-goog-api-key' => $this->settings->apiKey()])->timeout(60)->acceptJson()
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'contents' => [['role' => 'user', 'parts' => $parts]],
                'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => max(2048, $maxTokens * 4), 'responseMimeType' => 'application/json'],
            ]);

        if (!$res->successful()) {
            return ['ok' => false, 'text' => null, 'error' => $this->errorMessage($res->json(), $res->status())];
        }
        if ($this->geminiBlocked($res->json())) {
            return ['ok' => false, 'text' => null, 'error' => 'blocked'];
        }

        return ['ok' => true, 'text' => $this->geminiText($res->json()), 'error' => null];
    }

    /** Gemini needs the bytes: data URLs are split, https URLs downloaded (max 8 MB). */
    private function inlineImage(string $url): ?array
    {
        if (preg_match('#^data:(image/[\w.+-]+);base64,(.+)$#s', $url, $m)) {
            return ['mime_type' => $m[1], 'data' => $m[2]];
        }

        $res = Http::timeout(20)->get($url);
        if (!$res->successful() || strlen($res->body()) > 8 * 1024 * 1024) {
            return null;
        }
        $mime = strtok((string) $res->header('Content-Type'), ';') ?: 'image/jpeg';

        return str_starts_with($mime, 'image/') ? ['mime_type' => $mime, 'data' => base64_encode($res->body())] : null;
    }

    /** First JSON object in the answer (models sometimes wrap it in ```json fences). */
    private function decodeJson(string $text): ?array
    {
        $text = trim(preg_replace('/^```(?:json)?|```$/m', '', $text));
        $json = json_decode($text, true);
        if (is_array($json)) {
            return $json;
        }
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $json = json_decode($m[0], true);

            return is_array($json) ? $json : null;
        }

        return null;
    }

    /**
     * Check text for offensive content. Returns readable reasons; empty =
     * clean or the service could not be reached (the blocked-word list in
     * ContentSafety still applies either way).
     *
     * @return string[]
     */
    public function moderate(string $text): array
    {
        if (!$this->settings->ready()) {
            return [];
        }

        try {
            return $this->settings->provider() === 'gemini' ? $this->geminiModerate($text) : $this->openAiModerate($text);
        } catch (\Throwable $e) {
            Log::warning('Content moderation failed', ['provider' => $this->settings->provider(), 'error' => $e->getMessage()]);

            return [];
        }
    }

    // ---------------------------------------------------------------- OpenAI

    private function openAiChat(string $system, string $user, float $temperature, int $maxTokens): array
    {
        $res = Http::withToken($this->settings->apiKey())->timeout(30)->acceptJson()
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->settings->model(),
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'max_tokens' => $maxTokens,
                'temperature' => $temperature,
            ]);

        if (!$res->successful()) {
            return ['ok' => false, 'text' => null, 'blocked' => false, 'error' => $this->errorMessage($res->json(), $res->status()), 'busy' => $res->status() === 429];
        }

        return ['ok' => true, 'text' => trim((string) $res->json('choices.0.message.content')), 'blocked' => false, 'error' => null];
    }

    private function openAiModerate(string $text): array
    {
        $res = Http::withToken($this->settings->apiKey())->timeout(10)->acceptJson()
            ->post('https://api.openai.com/v1/moderations', ['model' => 'omni-moderation-latest', 'input' => $text]);
        if (!$res->successful()) {
            return [];
        }

        $result = $res->json('results.0') ?? [];
        if (empty($result['flagged'])) {
            return [];
        }

        return $this->labels(array_keys(array_filter($result['categories'] ?? [])));
    }

    // ---------------------------------------------------------------- Gemini

    private function geminiRequest(string $system, string $user, float $temperature, int $maxTokens): \Illuminate\Http\Client\Response
    {
        $model = $this->settings->model();
        $send = fn () => Http::withHeaders(['x-goog-api-key' => $this->settings->apiKey()])->timeout(40)->acceptJson()
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
                'safetySettings' => self::GEMINI_SAFETY,
                // Newer Gemini models "think" before answering; leave room for it.
                'generationConfig' => ['temperature' => $temperature, 'maxOutputTokens' => max(2048, $maxTokens * 4)],
            ]);

        // "High demand" (503) is usually momentary — one retry after a pause.
        $res = $send();
        if (in_array($res->status(), [500, 503], true)) {
            usleep(1_500_000);
            $res = $send();
        }

        return $res;
    }

    private function geminiChat(string $system, string $user, float $temperature, int $maxTokens): array
    {
        $res = $this->geminiRequest($system, $user, $temperature, $maxTokens);
        if (!$res->successful()) {
            return ['ok' => false, 'text' => null, 'blocked' => false, 'error' => $this->errorMessage($res->json(), $res->status()), 'busy' => $res->status() === 429];
        }

        if ($this->geminiBlocked($res->json())) {
            return ['ok' => false, 'text' => null, 'blocked' => true, 'error' => 'blocked'];
        }

        return ['ok' => true, 'text' => $this->geminiText($res->json()), 'blocked' => false, 'error' => null];
    }

    /**
     * Gemini has no separate moderation endpoint: ask it to classify the
     * text under its strictest safety filters. A safety block, or an
     * UNSAFE verdict, flags the text.
     */
    private function geminiModerate(string $text): array
    {
        $system = 'You are a strict content moderator for public social media posts. '
            . 'Reply with exactly SAFE, or UNSAFE followed by a colon and the problem categories '
            . '(harassment, hate, sexual, violence, dangerous_content, profanity). '
            . 'Mark UNSAFE for any swearing, insults, sexual or violent content, hate or discrimination, even if mild or disguised.';
        $res = $this->geminiRequest($system, "Text to check:\n\"\"\"\n{$text}\n\"\"\"", 0.0, 50);
        if (!$res->successful()) {
            Log::warning('Gemini moderation unavailable', ['status' => $res->status()]);

            return [];
        }

        $json = $res->json();
        if ($this->geminiBlocked($json)) {
            $categories = collect($json['candidates'][0]['safetyRatings'] ?? $json['promptFeedback']['safetyRatings'] ?? [])
                ->filter(fn ($r) => !empty($r['blocked']) || in_array($r['probability'] ?? '', ['LOW', 'MEDIUM', 'HIGH'], true))
                ->map(fn ($r) => strtolower(str_replace('HARM_CATEGORY_', '', $r['category'] ?? '')))
                ->all();

            return $this->labels($categories);
        }

        $verdict = strtoupper(trim($this->geminiText($json)));
        if (!str_starts_with($verdict, 'UNSAFE')) {
            return [];
        }
        $cats = array_filter(array_map('trim', explode(',', strtolower(trim(substr($verdict, 6), ": \t")))));

        return $this->labels(array_map(fn ($c) => $c === 'profanity' ? 'harassment' : $c, $cats));
    }

    private function geminiBlocked(array $json): bool
    {
        return !empty($json['promptFeedback']['blockReason'])
            || in_array($json['candidates'][0]['finishReason'] ?? '', ['SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'SPII'], true);
    }

    /** Answer text, skipping the model's "thought" parts. */
    private function geminiText(array $json): string
    {
        return trim(collect($json['candidates'][0]['content']['parts'] ?? [])
            ->reject(fn ($p) => !empty($p['thought']))
            ->pluck('text')
            ->implode(''));
    }

    // ---------------------------------------------------------------- helpers

    /** @param string[] $categories */
    private function labels(array $categories): array
    {
        $labels = [];
        foreach ($categories as $category) {
            $base = explode('/', (string) $category)[0];
            $labels[$base] = self::CATEGORY_LABELS[$base] ?? 'inappropriate content';
        }

        return array_values($labels) ?: ['inappropriate content'];
    }

    private function errorMessage(?array $json, int $status): string
    {
        $message = $json['error']['message'] ?? null;

        return $message ? mb_substr((string) $message, 0, 300) : "HTTP {$status}";
    }
}
