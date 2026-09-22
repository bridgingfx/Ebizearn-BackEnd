<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Round 2 — strong password policy for registration / password reset.
 *
 * Minimum 10 characters with all four character classes: uppercase,
 * lowercase, digit, symbol. One clear message so users know exactly
 * what is expected (and attackers learn nothing new — the policy is
 * public documentation anyway).
 */
class StrongPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = (string) $value;

        $reasons = [];
        if (mb_strlen($value) < 10) {
            $reasons[] = 'at least 10 characters';
        }
        if (!preg_match('/[A-Z]/', $value)) {
            $reasons[] = 'an uppercase letter';
        }
        if (!preg_match('/[a-z]/', $value)) {
            $reasons[] = 'a lowercase letter';
        }
        if (!preg_match('/[0-9]/', $value)) {
            $reasons[] = 'a digit';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $value)) {
            $reasons[] = 'a symbol (e.g. !@#$)';
        }

        if ($reasons !== []) {
            $fail('The password must contain ' . implode(', ', $reasons) . '.');
        }
    }
}
