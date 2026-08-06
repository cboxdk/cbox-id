<?php

declare(strict_types=1);
use Cbox\LaravelPostal\Models\PostalMessage;
use Cbox\LaravelPostal\Models\PostalMessageEvent;

return [

    /*
    |--------------------------------------------------------------------------
    | Default server
    |--------------------------------------------------------------------------
    |
    | The server entry used when none is named explicitly — by the `postal`
    | mail transport, the notification channel and the Postal facade.
    |
    */

    'default' => env('POSTAL_SERVER', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Servers
    |--------------------------------------------------------------------------
    |
    | One entry per Postal mail server. Each Postal mail server has its own
    | X-Server-API-Key, so multi-product deployments configure one entry per
    | product and address them by name: Postal::server('cbox-billing').
    |
    */

    'servers' => [
        'default' => [
            'url' => env('POSTAL_URL', 'https://postal.example.com'),
            'key' => env('POSTAL_KEY'),

            // How this connection submits mail:
            //   api      — structured JSON via /api/v1/send/message (default)
            //   smtp-api — raw RFC 2822 via /api/v1/send/raw
            //   smtp     — classic SMTP submission (requires 'smtp' settings;
            //              'url'/'key' become optional and, when present,
            //              still power message lookups and postal:message)
            'type' => env('POSTAL_TYPE', 'api'),

            // Only used by type "smtp". Credentials map to a Postal SMTP
            // credential; omit username/password for SMTP-IP credentials.
            // 'smtp' => [
            //     'host' => env('POSTAL_SMTP_HOST'),
            //     'port' => (int) env('POSTAL_SMTP_PORT', 25),
            //     'username' => env('POSTAL_SMTP_USERNAME'),
            //     'password' => env('POSTAL_SMTP_PASSWORD'),
            //     'tls' => (bool) env('POSTAL_SMTP_TLS', false),
            //     'timeout' => (int) env('POSTAL_SMTP_TIMEOUT', 30),
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP client
    |--------------------------------------------------------------------------
    |
    | Retries apply to transport errors, HTTP 429 and 5xx responses only.
    | Postal's own `status: error` envelopes are not retryable.
    |
    */

    'http' => [
        'timeout' => (int) env('POSTAL_TIMEOUT', 15),
        'retry' => [
            'times' => (int) env('POSTAL_RETRY_TIMES', 3),
            'sleep_ms' => (int) env('POSTAL_RETRY_SLEEP_MS', 200),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | Postal POSTs signed webhooks to the configured path. The signature is an
    | RSA signature over the raw request body (X-Postal-Signature-256 = SHA256,
    | X-Postal-Signature = legacy SHA1). The public key is served by Postal at
    | /.well-known/jwks.json — `php artisan postal:webhook-key` prints it as
    | PEM for POSTAL_WEBHOOK_PUBLIC_KEY.
    |
    */

    'webhooks' => [
        'enabled' => (bool) env('POSTAL_WEBHOOKS_ENABLED', true),
        'path' => 'postal/webhook',
        'middleware' => ['api'],
        'verify_signature' => (bool) env('POSTAL_WEBHOOK_VERIFY', true),
        'public_key' => env('POSTAL_WEBHOOK_PUBLIC_KEY'),
        'queue' => env('POSTAL_WEBHOOK_QUEUE'),
        'connection' => env('POSTAL_WEBHOOK_CONNECTION'),

        // Persist an idempotent status row per message (postal_messages) and a
        // deduplicated event log (postal_message_events).
        'store' => (bool) env('POSTAL_WEBHOOK_STORE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound messages
    |--------------------------------------------------------------------------
    |
    | Postal routes can deliver received mail to an HTTP endpoint. Point a
    | route at {path}/{server} (Postal admin → Routes → HTTP Endpoint; use
    | the BodyAsJSON encoding). Deliveries are signed with the same key as
    | webhooks and become PostalInboundMessage events.
    |
    */

    'inbound' => [
        'enabled' => (bool) env('POSTAL_INBOUND_ENABLED', true),
        'path' => 'postal/inbound',
        'middleware' => ['api'],
        'verify_signature' => (bool) env('POSTAL_INBOUND_VERIFY', true),
        'queue' => env('POSTAL_INBOUND_QUEUE'),
        'connection' => env('POSTAL_INBOUND_CONNECTION'),
        'store' => (bool) env('POSTAL_INBOUND_STORE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Broadcasting (opt-in)
    |--------------------------------------------------------------------------
    |
    | When enabled, webhook events also broadcast on a private channel
    | "postal.server.{name}" via the application's own configured broadcaster.
    | The package depends on no broadcaster and ships with this off.
    |
    */

    'broadcast' => [
        'enabled' => (bool) env('POSTAL_BROADCAST', false),
        'channel' => 'postal',
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Subclass the package models and point these keys at your classes to
    | add relations, scopes or casts. The package still owns the schema, and
    | both models must live on the same database connection — the store's
    | dedupe/upsert transaction cannot span two connections.
    |
    */

    'models' => [
        'message' => PostalMessage::class,
        'event' => PostalMessageEvent::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redirect all mail (dev convenience)
    |--------------------------------------------------------------------------
    |
    | When set, every message sent through the `postal` mail transport is
    | delivered to this address instead of its real recipients.
    |
    */

    'redirect_to' => env('POSTAL_REDIRECT_TO'),

];
