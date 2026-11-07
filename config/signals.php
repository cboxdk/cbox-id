<?php

declare(strict_types=1);

// cboxdk/laravel-signals-client. `url`/`token` are the Signals application the
// bundled integration enrols with; the token is unused because the bundled
// credential below is a Cbox ID machine token (client credentials) for a
// confidential client the operator registered for Cbox ID itself.
return [
    'url' => env('SIGNALS_URL', 'https://app.cboxsignals.com'),
    'token' => env('SIGNALS_TOKEN'),
    'timeout' => env('SIGNALS_TIMEOUT', 2),
    'webhook_secret' => env('SIGNALS_WEBHOOK_SECRET'),
    'managed' => ['token' => env('SIGNALS_MANAGED_TOKEN')],
    'bundled' => [
        'client_id' => env('SIGNALS_BUNDLED_CLIENT_ID'),
        'client_secret' => env('SIGNALS_BUNDLED_CLIENT_SECRET'),
        // Token endpoint of the realm the Signals application trusts as issuer.
        'token_url' => env('SIGNALS_BUNDLED_TOKEN_URL'),
        // Optional RFC 8707 resource so the token is audience-bound to Signals.
        'resource' => env('SIGNALS_BUNDLED_RESOURCE'),
    ],
];
