<?php

declare(strict_types=1);

return [
    'base_url' => env('BILLING_CLIENT_BASE_URL'),
    'api_token' => env('BILLING_CLIENT_API_TOKEN'),
    // Product-bound publication credential: definitions only, never customer grants.
    'definition_token' => env('BILLING_CLIENT_DEFINITION_TOKEN'),
    'timeout' => (int) env('BILLING_CLIENT_TIMEOUT', 5),
    'fail' => 'deny',
];
