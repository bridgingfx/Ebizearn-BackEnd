<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Bug 9 (bug report 2026-11-20): catch near-miss email domains at signup
 * (e.g. customerfive51@gmail.vom next to the real gmail.com account) and
 * suggest the intended address instead of creating a dead account.
 *
 * Two checks, both offline (deliberately NOT the `email:dns` rule — a live
 * DNS lookup rejects test domains such as example.com, which has no MX
 * records, and depends on resolver availability):
 *  - domain typo: Levenshtein <= 2 against well-known mail providers;
 *  - TLD sanity: a 3-4 letter TLD one edit away from "com" (e.g. .vom,
 *    .cmo, .con) is almost always a typo. `.cam` is excluded — it is a
 *    real gTLD one edit from "com".
 */
class NoEmailTypo implements ValidationRule
{
    /** Well-known mail providers users mistype most often. */
    public const POPULAR_DOMAINS = [
        'gmail.com', 'googlemail.com', 'outlook.com', 'outlook.co.uk',
        'hotmail.com', 'hotmail.co.uk', 'live.com', 'msn.com',
        'yahoo.com', 'yahoo.co.uk', 'yahoo.co.in', 'icloud.com', 'me.com', 'mac.com',
        'protonmail.com', 'proton.me', 'aol.com', 'mail.com', 'gmx.com', 'gmx.net',
        'zoho.com', 'yandex.com', 'fastmail.com', 'tutanota.com',
        'qq.com', '163.com', '126.com', 'sina.com', 'naver.com',
    ];

    public const MAX_DISTANCE = 2;

    protected ?string $suggestion = null;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $email = (string) $value;
        $at = strrpos($email, '@');
        if ($at === false) {
            return; // the `email` rule reports malformed addresses.
        }
        $local = substr($email, 0, $at + 1);
        $domain = strtolower(substr($email, $at + 1));
        if ($domain === '' || !str_contains($domain, '.') || in_array($domain, self::POPULAR_DOMAINS, true)) {
            return;
        }

        // 1. Near-miss of a well-known provider (gmail.vom -> gmail.com).
        $best = null;
        $bestDistance = self::MAX_DISTANCE + 1;
        foreach (self::POPULAR_DOMAINS as $popular) {
            $d = levenshtein($domain, $popular);
            if ($d > 0 && $d < $bestDistance) {
                $best = $popular;
                $bestDistance = $d;
            }
        }
        if ($best !== null) {
            $this->suggestion = $local . $best;
            $fail("Did you mean {$this->suggestion}?");

            return;
        }

        // 2. TLD sanity: .vom / .cmo / .con on an otherwise unknown domain.
        $tld = substr($domain, strrpos($domain, '.') + 1);
        if ($tld !== 'com' && strlen($tld) >= 3 && strlen($tld) <= 4 && $tld !== 'cam' && levenshtein($tld, 'com') <= 1) {
            $this->suggestion = $local . substr($domain, 0, -strlen($tld)) . 'com';
            $fail("Did you mean {$this->suggestion}?");
        }
    }
}
