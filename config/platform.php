<?php

return [
    'name' => env('PLATFORM_NAME', 'BizNetwork'),
    'shortName' => env('PLATFORM_SHORT_NAME', 'BizNetwork'),
    'tagline' => 'Small Tasks. Big Opportunities.',
    'supportingTagline' => 'Complete verified digital tasks from real businesses, submit your work and receive rewards securely.',
    'domain' => env('PLATFORM_DOMAIN', 'ebizearn.com'),
    'frontendUrl' => env('FRONTEND_URL', 'http://localhost:5173'),
    'appUrl' => env('PLATFORM_APP_URL', 'https://ebizearn.com'),
    'businessUrl' => env('PLATFORM_BUSINESS_URL', 'https://ebizearn.com/business'),
    'adminUrl' => env('PLATFORM_ADMIN_URL', 'https://ebizearn.com/admin'),
    'supportEmail' => env('PLATFORM_SUPPORT_EMAIL', 'support@ebizearn.com'),
    'defaultCurrency' => 'USD',
    'defaultLocale' => 'en',
    'minWithdrawalCents' => (int) env('WITHDRAWAL_MIN_CENTS', 5000), // $50.00 — mirrors config('payouts.withdrawal_min_cents'), which is the source of truth
    'platformFeePercent' => 15, // 15% platform margin on campaigns
    'socials' => [
        'facebook' => 'https://facebook.com/ebizearn',
        'instagram' => 'https://instagram.com/ebizearn',
        'linkedin' => 'https://linkedin.com/company/ebizearn',
        'x' => 'https://x.com/ebizearn',
        'youtube' => 'https://youtube.com/@ebizearn',
    ],
    'supportedCountries' => [
        'GE' => ['name' => 'Georgia', 'currency' => 'GEL', 'active' => true],
        'US' => ['name' => 'United States', 'currency' => 'USD', 'active' => true],
        'GB' => ['name' => 'United Kingdom', 'currency' => 'GBP', 'active' => true],
        'IN' => ['name' => 'India', 'currency' => 'INR', 'active' => true],
        'PK' => ['name' => 'Pakistan', 'currency' => 'PKR', 'active' => true],
        'BD' => ['name' => 'Bangladesh', 'currency' => 'BDT', 'active' => true],
        'LK' => ['name' => 'Sri Lanka', 'currency' => 'LKR', 'active' => true],
        'PH' => ['name' => 'Philippines', 'currency' => 'PHP', 'active' => true],
        'NG' => ['name' => 'Nigeria', 'currency' => 'NGN', 'active' => true],
        'BR' => ['name' => 'Brazil', 'currency' => 'BRL', 'active' => true],
    ],
    'featureFlags' => [
        'referrals' => true,
        'multiLevelAffiliate' => false, // disabled by default per policy
        'cryptoPayout' => false,
        'ugcTasks' => true,
        'socialTasks' => true,
        'aiVerification' => true,
        'retentionMonitoring' => true,
        'businessSelfServe' => true,
        'multiLanguage' => true,
        'advancedAnalytics' => true,
    ],
];
