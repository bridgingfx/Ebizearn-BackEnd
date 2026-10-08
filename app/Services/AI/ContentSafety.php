<?php

namespace App\Services\AI;

/**
 * Keeps campaign content clean: contributors copy and post it publicly, so
 * nothing offensive may get through — not from the AI, not typed by a
 * business.
 *
 * Two layers:
 *  1. Blocked words (config/content_safety.php), matched as whole words
 *     after normalising look-alikes (sh1t, f*ck, a$$hole, fuuuck).
 *  2. The AI service Super Admin chose (AiClient::moderate — OpenAI's
 *     moderation endpoint, or Gemini under its strictest safety filters)
 *     for context: hate, harassment, sexual, violence. Skipped when no AI
 *     is set up or it is unreachable — layer 1 still applies.
 */
class ContentSafety
{
    private const LOOKALIKES = ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's', '!' => 'i', '|' => 'i'];

    /**
     * @return array{ok: bool, reason: ?string, terms: string[]}
     */
    public function check(?string $text, bool $useModeration = true): array
    {
        $text = trim((string) $text);
        if ($text === '') {
            return ['ok' => true, 'reason' => null, 'terms' => []];
        }

        $terms = $this->blockedTermsIn($text);
        if ($terms !== []) {
            return [
                'ok' => false,
                'reason' => 'It contains words that are not allowed: ' . implode(', ', $terms) . '.',
                'terms' => $terms,
            ];
        }

        if ($useModeration && ($categories = $this->moderationFlags($text)) !== []) {
            return [
                'ok' => false,
                'reason' => 'It looks like it contains ' . implode(', ', $categories) . '.',
                'terms' => [],
            ];
        }

        return ['ok' => true, 'reason' => null, 'terms' => []];
    }

    /** @return string[] blocked words found (as written in the list) */
    public function blockedTermsIn(string $text): array
    {
        $exact = [];
        $stretched = []; // collapsed form => term, for "fuuuck" / "shiiit"
        foreach ((array) config('content_safety.blocked_terms', []) as $term) {
            $term = strtolower(trim((string) $term));
            if ($term !== '') {
                $exact[$term] = $term;
                $stretched[$this->collapse($term)] = $term;
            }
        }

        $match = function (string $candidate) use ($exact, $stretched): ?string {
            if ($candidate === '') {
                return null;
            }
            if (isset($exact[$candidate])) {
                return $exact[$candidate];
            }
            // Only when letters were ADDED (fuuuck), never removed — so the
            // ordinary word "bobs" does not match a blocked word.
            $term = $stretched[$this->collapse($candidate)] ?? null;

            return $term !== null && mb_strlen($candidate) >= mb_strlen($term) ? $term : null;
        };

        $found = [];
        foreach (preg_split('/\s+/u', mb_strtolower($text)) as $raw) {
            $candidates = [];
            // As written ("idiot!") and with look-alikes read as letters ("sh1t", "a$$hole").
            foreach (array_unique([$raw, strtr($raw, self::LOOKALIKES)]) as $word) {
                // Punctuation inside / around the word: "f*ck", "sh.it", "(idiot)".
                $candidates[] = preg_replace('/[^\p{L}]+/u', '', $word);
                // Hyphen / slash joined words: "nice-idiot".
                foreach (preg_split('/[^\p{L}]+/u', $word, -1, PREG_SPLIT_NO_EMPTY) as $part) {
                    $candidates[] = $part;
                }
            }
            foreach (array_unique($candidates) as $candidate) {
                if ($term = $match($candidate)) {
                    $found[$term] = true;
                }
            }
        }

        return array_keys($found);
    }

    /** @return string[] readable category names the moderation model flagged */
    private function moderationFlags(string $text): array
    {
        return app(AiClient::class)->moderate($text);
    }

    /** "fuuuck" -> "fuck", "shiit" -> "shit": repeated letters to one. */
    private function collapse(string $word): string
    {
        return preg_replace('/(\p{L})\1+/u', '$1', $word);
    }
}
