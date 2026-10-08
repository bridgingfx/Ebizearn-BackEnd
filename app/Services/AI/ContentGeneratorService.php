<?php

namespace App\Services\AI;

/**
 * Generates ready-to-post social content for campaigns using an LLM.
 * The business describes what the post is for; the AI returns ONLY the
 * post text, which contributors copy, paste, screenshot and submit.
 *
 * Contributors post this publicly under their own names, so every result
 * must be clean and appropriate: the brief is checked before the AI is
 * called, the AI works under strict content rules, and its output is
 * cleaned and checked again (ContentSafety) before anyone sees it.
 */
class ContentGeneratorService
{
    /**
     * Platform-specific content rules.
     */
    private const PLATFORM_RULES = [
        'instagram' => 'Instagram caption: engaging, 5-10 relevant hashtags at the end, max 150 words, emoji okay but tasteful.',
        'tiktok' => 'TikTok caption: punchy and casual, 3-5 hashtags, max 80 words, friendly tone.',
        'facebook' => 'Facebook post: warm and conversational, 2-4 hashtags, max 120 words.',
        'youtube' => 'YouTube comment: genuine and specific to the video topic in the brief, no hashtags, max 60 words.',
        'google_review' => 'Google review: positive, natural language, 40-80 words, no hashtags, no emoji. Mention only what the brief says about the business; never invent prices, staff names or events.',
        'trustpilot' => 'Trustpilot review: positive, natural language, 40-80 words, no hashtags. Mention only what the brief says about the business; never invent prices, staff names or events.',
        'twitter' => 'X/Twitter post: concise and sharp, 1-2 hashtags, max 40 words.',
        'linkedin' => 'LinkedIn post: professional but human, 2-3 hashtags, max 120 words.',
        'default' => 'Social media post: engaging and natural, 3-5 relevant hashtags, max 100 words.',
    ];

    /** Non-negotiable language rules for every platform. */
    private const SAFETY_RULES = <<<'TXT'
Content rules (must always be followed):
- Clean, polite, family-friendly language that is safe for every audience and every country.
- No swear words, slang insults, crude jokes or offensive words of any kind — not even mild or censored ones (no f*ck, sh*t, damn).
- No sexual content, no violence or threats, no hate, no discrimination or stereotypes about any race, religion, caste, nationality, gender, age, disability or sexual orientation.
- No politics, no religion, no alcohol, drugs, tobacco or gambling promotion.
- Never insult, mock or name competitors or any person.
- No false, misleading or exaggerated claims: no guarantees, no "best in the world", no medical, financial or legal promises.
- Respectful and positive tone.
TXT;

    /** What the model answers when a brief cannot be written appropriately. */
    private const REFUSAL = 'UNSAFE_REQUEST';

    public function __construct(private ?ContentSafety $safety = null, private ?AiClient $ai = null)
    {
        $this->safety ??= app(ContentSafety::class);
        $this->ai ??= app(AiClient::class);
    }

    /**
     * @return array{success: bool, message: string, content: ?string}
     */
    public function generate(string $platform, string $brief, ?string $companyName = null): array
    {
        if (!$this->ai->ready()) {
            return $this->fail('AI content generation is not set up yet. You can still write the post text yourself.');
        }

        // A provider that screens its own requests/answers (Gemini, strictest
        // safety settings) needs no extra moderation call — one AI call per
        // post instead of three. Blocked words are always checked.
        $moderate = !$this->ai->screensOwnOutput();

        // 1. The brief itself must be clean before the AI sees it.
        $briefCheck = $this->safety->check(trim($brief . ' ' . $companyName), $moderate);
        if (!$briefCheck['ok']) {
            return $this->fail('Please rewrite your description. ' . $briefCheck['reason']);
        }

        $platformKey = strtolower($platform);
        $rules = self::PLATFORM_RULES[$platformKey] ?? self::PLATFORM_RULES['default'];

        $system = "You write social media content that ordinary people will copy and post publicly for a real marketing campaign.\n"
            . "Format: {$rules}\n"
            . self::SAFETY_RULES . "\n"
            . "Output: return ONLY the final post text, ready to copy and paste — no title, no introduction such as \"Here is your post\", "
            . "no quotation marks around it, no notes, no options, no placeholders like [Your Name].\n"
            . 'If the request cannot be written while following every content rule, reply with exactly ' . self::REFUSAL . ' and nothing else.';

        $user = "Write the post for this: {$brief}";
        if ($companyName) {
            $user .= " (Brand: {$companyName})";
        }

        // 2. Generate, 3. clean, 4. check again — one stricter retry.
        $lastReason = null;
        foreach ([0.7, 0.3] as $temperature) {
            $result = $this->ai->chat($system, $user, $temperature);
            if ($result['blocked']) {
                // The AI provider's own safety filter refused it.
                return $this->fail('This description cannot be turned into appropriate content. Please describe your product or service in a neutral, positive way.');
            }
            if (!$result['ok']) {
                return $this->fail(!empty($result['busy'])
                    ? 'The AI is busy right now — please try again in a minute.'
                    : 'AI service unavailable. Please try again.');
            }
            $raw = (string) $result['text'];

            if (str_contains($raw, self::REFUSAL)) {
                return $this->fail('This description cannot be turned into appropriate content. Please describe your product or service in a neutral, positive way.');
            }

            $content = $this->clean($raw);
            if ($content === '') {
                continue;
            }

            $check = $this->safety->check($content, $moderate);
            if ($check['ok']) {
                return ['success' => true, 'message' => 'Content generated.', 'content' => $content];
            }
            $lastReason = $check['reason'];
        }

        return $this->fail($lastReason
            ? 'The AI could not produce suitable content for this description. Please rephrase it and try again.'
            : 'AI returned empty content. Please try again.');
    }

    /**
     * Auto mode: one contributor's own wording of the APPROVED post, so many
     * people don't publish identical text. Same meaning, facts, brand,
     * links and hashtags — nothing new. Returns null when the AI is
     * unavailable or no clean version came back (caller falls back to the
     * approved text).
     */
    public function variation(string $approved, string $platform, ?string $brief = null): ?string
    {
        if (!$this->ai->ready() || trim($approved) === '') {
            return null;
        }

        $rules = self::PLATFORM_RULES[strtolower($platform)] ?? self::PLATFORM_RULES['default'];
        $system = "You rewrite an approved social media post so one more person can post it in their own words.\n"
            . "Format: {$rules}\n"
            . self::SAFETY_RULES . "\n"
            . "Keep exactly the same meaning, facts, brand names, links and @mentions. Keep the same hashtags (order may change). "
            . "Do not add any new claim, offer, number or detail. Similar length.\n"
            . 'Output: return ONLY the rewritten post text — no introduction, no quotation marks, no notes.';

        $user = "Approved post:\n{$approved}";
        if ($brief) {
            $user .= "\n\nCampaign description (context only): {$brief}";
        }

        foreach ([0.9, 0.6] as $temperature) {
            $result = $this->ai->chat($system, $user, $temperature);
            if (!$result['ok']) {
                return null;
            }
            $content = $this->clean((string) $result['text']);
            if ($content !== '' && !str_contains($content, self::REFUSAL) && $this->safety->check($content, !$this->ai->screensOwnOutput())['ok']) {
                return $content;
            }
        }

        return null;
    }

    /**
     * Keep only the post: drop "Here's your post:" lead-ins, wrapping quotes,
     * code fences and markdown emphasis.
     */
    public function clean(string $text): string
    {
        $text = trim(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', trim($text)));
        $text = preg_replace('/^(sure|certainly|of course)[^\n]*\n+/i', '', $text);
        $text = preg_replace('/^(here(\'s| is| are)[^\n:]*|caption|post|review|comment)\s*:\s*\n*/i', '', trim($text));
        $text = preg_replace('/\*\*(.+?)\*\*|__(.+?)__/s', '$1$2', $text);
        $text = trim($text);

        foreach (['"' => '"', "'" => "'", '“' => '”', '‘' => '’'] as $open => $close) {
            if (mb_strlen($text) > 1 && str_starts_with($text, $open) && str_ends_with($text, $close)) {
                $text = trim(mb_substr($text, 1, -1));
            }
        }

        return $text;
    }

    private function fail(string $message): array
    {
        return ['success' => false, 'message' => $message, 'content' => null];
    }
}
