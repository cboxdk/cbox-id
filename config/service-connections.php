<?php

declare(strict_types=1);

return [
    // Platform registration, never customer-supplied credentials or destinations.
    'consumers' => array_values(array_filter(array_map(trim(...), explode(',', (string) env('CBOX_SERVICE_CONNECTION_CLIENTS', ''))))),
    'services' => [
        'cbox_billing' => ['client_id' => env('CBOX_BILLING_SERVICE_CLIENT_ID'), 'audience' => env('CBOX_BILLING_CONNECTION_RESOURCE', 'https://app.cboxbilling.com/api/v1/service-connections'), 'scopes' => ['billing:engage:sync']],
        'cbox_telemetry' => ['client_id' => env('CBOX_TELEMETRY_SERVICE_CLIENT_ID'), 'audience' => env('CBOX_TELEMETRY_CONNECTION_RESOURCE', 'https://app.cboxtelemetry.com/api/v1/service-connections'), 'scopes' => ['telemetry:engage:sync']],
    ],
];
