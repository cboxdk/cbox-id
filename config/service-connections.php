<?php

declare(strict_types=1);

return [
    // Platform registration, never customer-supplied credentials or destinations.
    'consumers' => array_values(array_filter(array_map(trim(...), explode(',', (string) env('CBOX_SERVICE_CONNECTION_CLIENTS', ''))))),
    'services' => [
        'cbox_billing_events' => ['client_id' => env('CBOX_BILLING_SERVICE_CLIENT_ID'), 'audience' => env('CBOX_BILLING_CONNECTION_RESOURCE', 'https://app.cboxbilling.com/api/v1/service-connections'), 'scopes' => ['billing:financial-events:read']],
        'cbox_id_events' => ['client_id' => env('CBOX_ID_SERVICE_CLIENT_ID'), 'audience' => env('CBOX_ID_CONNECTION_RESOURCE'), 'scopes' => ['signals:events:read']],
        'cbox_billing' => ['client_id' => env('CBOX_BILLING_SERVICE_CLIENT_ID'), 'audience' => env('CBOX_BILLING_CONNECTION_RESOURCE', 'https://app.cboxbilling.com/api/v1/service-connections'), 'scopes' => ['billing:engage:sync']],
        // A merchant's own Cbox Verify, connected from Cbox Commerce's checkout (cbox-commerce ADR-0012).
        'cbox_verify' => ['client_id' => env('CBOX_VERIFY_SERVICE_CLIENT_ID'), 'audience' => env('CBOX_VERIFY_CONNECTION_RESOURCE', 'https://api.cboxverify.com'), 'scopes' => ['verify:organization:read', 'verify:policies:read', 'verify:sessions:write']],
        // A customer's own Cbox Commerce and Cbox Assets, connected in one click from Cbox Flow (cbox-flow FLW-ACT-008).
        // The member role's reads and writes, never organization:admin (keys, members, the plan).
        'cbox_commerce' => ['client_id' => env('CBOX_COMMERCE_SERVICE_CLIENT_ID'), 'audience' => env('CBOX_COMMERCE_CONNECTION_RESOURCE', 'https://api.cboxcommerce.com'), 'scopes' => [
            'organization:read', 'business:read', 'catalog:read', 'inventory:read', 'checkout:read', 'orders:read', 'shipping:read', 'customers:read',
            'business:write', 'catalog:write', 'inventory:write', 'checkout:write', 'orders:write', 'shipping:write', 'customers:write',
        ]],
        'cbox_assets' => ['client_id' => env('CBOX_ASSETS_SERVICE_CLIENT_ID'), 'audience' => env('CBOX_ASSETS_CONNECTION_RESOURCE', 'https://api.cboxassets.com'), 'scopes' => ['organization:read', 'assets:read', 'storage:read', 'assets:write']],
        'cbox_telemetry' => ['client_id' => env('CBOX_TELEMETRY_SERVICE_CLIENT_ID'), 'audience' => env('CBOX_TELEMETRY_CONNECTION_RESOURCE', 'https://app.cboxtelemetry.com/api/v1/service-connections'), 'scopes' => ['telemetry:engage:sync']],
    ],
];
