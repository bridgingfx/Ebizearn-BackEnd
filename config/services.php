<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Round 2 — Social Login (Google / Apple)
    |--------------------------------------------------------------------------
    |
    | Server-side ID-token verification for POST /api/v1/auth/social/{provider}.
    | Dawood-side setup required before these go live:
    |  - Google: create an OAuth 2.0 Client ID in Google Cloud Console, add
    |    ebizearn.com as an authorized origin, set GOOGLE_CLIENT_ID here.
    |  - Apple: create a Services ID in Apple Developer, set APPLE_CLIENT_ID.
    |    (APPLE_TEAM_ID / APPLE_KEY_ID / APPLE_PRIVATE_KEY are reserved for a
    |    future authorization-code exchange flow; plain ID-token verification
    |    does not need them.)
    | Placeholder values fail verification closed — every social login 401s
    | until the real IDs are supplied.
    |
    */

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
    ],

    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
        'team_id' => env('APPLE_TEAM_ID'),
        'key_id' => env('APPLE_KEY_ID'),
        'private_key' => env('APPLE_PRIVATE_KEY'),
    ],

    'brevo' => [
        // Brevo transactional email (HTTP API, no SMTP). Used for all app email when set.
        'key' => env('BREVO_API_KEY'),
        'from_email' => env('MAIL_FROM_ADDRESS', 'info@ebizearn.com'),
        'from_name' => env('MAIL_FROM_NAME', 'eBizEarn'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
