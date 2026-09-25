<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Signup hardening — validates a phone dial code against the real
 * allow-list (config/phone.php). Accepts "+995" or "995".
 *
 * Single source of truth for the dial-code rule, shared by
 * AuthController@register and ProfileController@update.
 */
class PhoneCountryCode implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $code = ltrim(trim((string) $value), '+');

        if (!preg_match('/^\d{1,4}$/', $code)
            || !in_array($code, config('phone.allowed_codes', []), true)) {
            $fail('The selected phone country code is invalid.');
        }
    }
}
