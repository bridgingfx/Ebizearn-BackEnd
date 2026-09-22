<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Minimum Withdrawal (cents) — SINGLE SOURCE OF TRUTH
    |--------------------------------------------------------------------------
    |
    | Master spec (adjudicated 2026-09-22): the withdrawal threshold is $50.00
    | (5000 cents). Every enforcement path — WalletController validation,
    | WalletLedgerService::requestWithdrawal, and all UI-facing responses —
    | MUST read config('payouts.withdrawal_min_cents'). Do not hardcode a
    | literal amount anywhere in the withdrawal flow.
    |
    | NOTE: the legacy admin-editable `min_withdrawal_cents` system setting
    | (system_settings table, seeded by DatabaseSeeder) is INTENTIONALLY not
    | consulted. Config wins here so the threshold cannot silently drift
    | between code paths (previously the DB value had no effect at all).
    | To change the threshold, set WITHDRAWAL_MIN_CENTS in the environment.
    */

    'withdrawal_min_cents' => (int) env('WITHDRAWAL_MIN_CENTS', 5000), // $50.00
];
