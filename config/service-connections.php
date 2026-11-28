<?php

declare(strict_types=1);

return [
    // Platform registration, never customer-supplied credentials or destinations.
    'consumers' => array_values(array_filter(array_map(trim(...), explode(',', (string) env('CBOX_SERVICE_CONNECTION_CLIENTS', ''))))),
    'sites_consumer_client_id' => env('CBOX_SITES_SERVICE_CLIENT_ID'),
    // Temporary, operator-approved migration inventory. Missing/invalid JSON disables it.
    'sites_assets_migration' => json_decode((string) env('CBOX_SITES_ASSETS_MIGRATION', ''), true),
    // Separate product-bound read credentials. Missing credentials fail automatic provisioning closed.
    'admission_tokens' => [
        'cbox-sites' => env('CBOX_SITES_CONNECTION_ADMISSION_TOKEN'),
        'cbox-assets' => env('CBOX_ASSETS_CONNECTION_ADMISSION_TOKEN'),
    ],
    'services' => [
        'cbox_billing_events' => ['client_id' => env('CBOX_BILLING_SERVICE_CLIENT_ID'), 'audience' => env('CBOX_BILLING_CONNECTION_RESOURCE', 'https://app.cboxbilling.com/api/v1/service-connections'), 'scopes' => ['billing:financial-events:read']],
        'cbox_id_events' => ['client_id' => env('CBOX_ID_SERVICE_CLIENT_ID'), 'audience' => env('CBOX_ID_CONNECTION_RESOURCE'), 'scopes' => ['signals:events:read']],
        'cbox_billing' => ['client_id' => env('CBOX_BILLING_SERVICE_CLIENT_ID'), 'audience' => env('CBOX_BILLING_CONNECTION_RESOURCE', 'https://app.cboxbilling.com/api/v1/service-connections'), 'scopes' => ['billing:engage:sync']],
        // A merchant's own Cbox Verify, connected from Cbox Commerce's checkout (cbox-commerce ADR-0012).
        'cbox_verify' => ['client_id' => env('CBOX_VERIFY_SERVICE_CLIENT_ID'), 'audience' => env('CBOX_VERIFY_CONNECTION_RESOURCE', 'https://api.cboxverify.com'), 'scopes' => ['verify:organization:read', 'verify:policies:read', 'verify:sessions:write']],
        'cbox_assets' => ['client_id' => env('CBOX_ASSETS_SERVICE_CLIENT_ID'), 'audience' => env('CBOX_ASSETS_CONNECTION_RESOURCE', 'https://api.cboxassets.com'), 'scopes' => ['assets:consumer:read', 'assets:consumer:write']],
        'cbox_telemetry' => ['client_id' => env('CBOX_TELEMETRY_SERVICE_CLIENT_ID'), 'audience' => env('CBOX_TELEMETRY_CONNECTION_RESOURCE', 'https://app.cboxtelemetry.com/api/v1/service-connections'), 'scopes' => ['telemetry:engage:sync']],
    ],
];
