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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * Cloudflare Turnstile — the CAPTCHA shown on a signup the risk scorer asks to
     * challenge (see App\Platform\Turnstile). Entirely optional: with no keys the
     * widget never renders, no third-party script is allowed by the CSP, and signup
     * behaves exactly as it does without the feature.
     */
    'turnstile' => [
        'site_key' => env('CBOX_ID_TURNSTILE_SITE_KEY'),
        'secret_key' => env('CBOX_ID_TURNSTILE_SECRET_KEY'),
    ],

    /*
     * Social login providers. The self-host operator configures their own OAuth
     * apps; a provider only appears on the login screen once its credentials are
     * set. Account linking is EXPLICIT: a social identity only reaches an existing
     * account when a signed-in user deliberately linked it (see SocialController).
     * Accounts are never auto-merged by email.
     */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => '/auth/google/callback',
    ],

    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect' => '/auth/github/callback',
    ],

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect' => '/auth/microsoft/callback',
    ],

];
