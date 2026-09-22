<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Minimum Withdrawal (cents) — FALLBACK VALUE
    |--------------------------------------------------------------------------
    |
    | Phase 2: the runtime source of truth is the active row in the
    | `withdrawal_rules` table (Super-Admin-selectable $10/$25/$50/$100),
    | read via WithdrawalRule::currentMinCents(). This config value is only
    | the fallback when no rule row is active (e.g. before seeding).
    | Do not hardcode a literal amount anywhere in the withdrawal flow.
    */

    'withdrawal_min_cents' => (int) env('WITHDRAWAL_MIN_CENTS', 5000), // $50.00
];
