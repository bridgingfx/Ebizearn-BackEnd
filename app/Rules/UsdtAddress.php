<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a USDT receiving address for the given network.
 *
 *  - TRC-20 (Tron): base58 address, starts with 'T', 34 chars total
 *    (T + 33 base58 chars, no 0/O/I/l).
 *  - ERC-20 (Ethereum): '0x' + 40 hex chars.
 */
class UsdtAddress implements ValidationRule
{
    public const NETWORKS = ['TRC-20', 'ERC-20'];

    public function __construct(protected ?string $network = 'TRC-20') {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || $value === '') {
            $fail('The :attribute is required.');

            return;
        }

        $network = strtoupper(trim((string) $this->network));

        if ($network === 'ERC-20') {
            if (!preg_match('/^0x[0-9a-fA-F]{40}$/', $value)) {
                $fail('The :attribute must be a valid ERC-20 address (0x + 40 hex characters).');
            }

            return;
        }

        // Default: TRC-20 (Tron base58).
        if (!preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $value)) {
            $fail('The :attribute must be a valid TRC-20 address (starts with T, 34 characters).');
        }
    }
}
