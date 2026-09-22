<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Affiliate / referral program
    |--------------------------------------------------------------------------
    |
    | Phase 8: three-level affiliate ledger (owner mission brief 2026-09-22
    | evening, reverses the earlier one-level call). Levels AND rates are
    | config so the program can revert to a single direct level with a
    | setting (REFERRAL_LEVELS=1).
    |
    | Credit rules (enforced by ReferralService):
    |  - the chain is resolved at registration from the referee's referrer
    |    chain, up to `levels` deep;
    |  - a level is credited only when the referee satisfies the qualification
    |    rules below — never on registration alone;
    |  - every payout is a ledger-backed wallet transaction of type
    |    referral_reward, guarded by unique(referrer_id, referred_user_id,
    |    level) so a level can never double-pay, even on retry.
    */

    'enabled' => (bool) env('REFERRALS_ENABLED', true),

    // Depth of the affiliate chain. 3 = L1/L2/L3; set to 1 to revert to the
    // old direct-only program.
    'levels' => (int) env('REFERRAL_LEVELS', 3),

    // Per-level reward in cents. Missing levels fall back to the previous
    // level's amount; a level set to 0 is marked rewarded without a payout.
    'rewards_cents' => [
        1 => (int) env('REFERRAL_L1_CENTS', 100), // $1.00 direct
        2 => (int) env('REFERRAL_L2_CENTS', 50),  // $0.50
        3 => (int) env('REFERRAL_L3_CENTS', 25),  // $0.25
    ],

    // Qualification: the referee's email must be verified before any level
    // pays out. (Registration currently marks emails verified by default;
    // this flag keeps the rule enforceable once real verification ships.)
    'require_email_verified' => (bool) env('REFERRALS_REQUIRE_EMAIL_VERIFIED', true),

    // Qualification: rewards trigger on the referee's first approved task
    // (the approval flow calls ReferralService::qualifyAndReward). Turning
    // this off would credit on registration instead — NOT recommended and
    // kept here only so the rule stays explicit.
    'require_first_task_approved' => (bool) env('REFERRALS_REQUIRE_FIRST_TASK_APPROVED', true),
];
