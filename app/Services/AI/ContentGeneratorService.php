<?php

namespace App\Services\AI;

/**
 * Generates ready-to-post social content for campaigns using an LLM.
 * The business describes what the post is for; the AI returns creative,
 * platform-appropriate content (hashtags, keywords, length limits).
 * Contributors copy-paste it, screenshot, and submit.
 */
class ContentGeneratorService
{
    /**
     * Platform-specific content rules.
     */
    private const PLATFORM_RULES = [
        'instagram' => 'Instagram caption: engaging, 5-10 relevant hashtags at the end, max 150 words, emoji okay but tasteful.',
        'tiktok' => 'TikTok caption: punchy and casual, 3-5 hashtags, max 80 words, Gen-Z friendly tone.',
        'facebook' => 'Facebook post: warm and conversational, 2-4 hashtags, max 120 words.',
        'youtube' => 'YouTube comment: genuine and specific, no hashtags needed, max 60 words, must sound like a real viewer.',
        'google_review' => 'Google review: authentic 5-star review, specific details, natural language, 40-80 words, no hashtags, no emoji.',
        'trustpilot' => 'Trustpilot review: honest-sounding 5-star review, mentions specific positives, 40-80 words, no hashtags.',
        'twitter' => 'X/Twitter post: concise and sharp, 1-2 hashtags, max 40 words.',
        'linkedin' => 'LinkedIn post: professional but human, 2-3 hashtags, max 120 words.',
        'default' => 'Social media post: engaging and natural, 3-5 relevant hashtags, max 100 words.',
    ];

    /**
     * Generate content via OpenAI (or return a template fallback when no key).
     */
    public function generate(string $platform, string $brief, ?string $companyName = null): array
    {
        $platformKey = strtolower($platform);
        $rules = self::PLATFORM_RULES[$platformKey] ?? self::PLATFORM_RULES['default'];

        $apiKey = config('services.openai.key');
        if (!$apiKey) {
            return [
                'success' => false,
                'message' => 'AI content generation is not configured. Add OPENAI_API_KEY to enable it.',
                'content' => null,
            ];
        }

        $system = "You write social media content for real marketing campaigns. "
            . "Rules: {$rules} "
            . "Never use placeholder brackets like [Your Name]. Write ready-to-post text. "
            . "Return ONLY the post content, no explanations.";

        $user = "Write a post for this: {$brief}";
        if ($companyName) {
            $user .= " (Brand: {$companyName})";
        }

        try {
            $ch = curl_init('https://api.openai.com/v1/chat/completions');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ],
                CURLOPT_POSTFIELDS => json_encode([
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                    'max_tokens' => 500,
                    'temperature' => 0.8,
                ]),
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || !$response) {
                return ['success' => false, 'message' => 'AI service unavailable. Please try again.', 'content' => null];
            }

            $data = json_decode($response, true);
            $content = trim($data['choices'][0]['message']['content'] ?? '');

            if (!$content) {
                return ['success' => false, 'message' => 'AI returned empty content. Please try again.', 'content' => null];
            }

            return ['success' => true, 'message' => 'Content generated.', 'content' => $content];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'AI service error. Please try again.', 'content' => null];
        }
    }
}
