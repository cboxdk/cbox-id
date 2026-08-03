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
     * Social login providers offered by the OPERATOR of this deployment.
     *
     * Only the credentials live here. Everything else about each provider — issuer,
     * endpoints, scopes, and where the identity sits in the response — comes from the
     * shared provider catalogue (Cbox\Id\Federation\ProviderCatalog), the same entries a
     * tenant picks from when connecting its own Google or GitHub. Sign-in runs on the
     * platform's own OIDC and OAuth 2.0 clients, so these providers get the same SSRF
     * pinning and the same RS256 allow-list as every tenant connection.
     *
     * A provider appears on the login screen only once it is COMPLETELY configured, so
     * the screen never shows a button whose flow cannot finish. Account linking is
     * EXPLICIT: a social identity only reaches an existing account when a signed-in user
     * deliberately linked it (see SocialController). Accounts are never auto-merged by
     * email.
     */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    ],

    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
    ],

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),

        /*
         * The directory (tenant) GUID — REQUIRED, and the one setting that is new.
         *
         * The directory is part of Entra's issuer, and an id_token's `iss` names the
         * directory that actually issued it. Signing in against the shared `common`
         * endpoint therefore cannot be verified: its discovery document advertises the
         * literal `https://login.microsoftonline.com/{tenantid}/v2.0`, so there is no
         * issuer to pin and no way to tell which directory a token came from. Pinning to
         * one directory is what makes the assertion checkable — and it means this button
         * admits your organization's accounts, not every Microsoft account in the world.
         *
         * Without it, Microsoft is simply not offered rather than offered unverifiably.
         */
        'directory' => env('MICROSOFT_DIRECTORY'),
    ],

];
