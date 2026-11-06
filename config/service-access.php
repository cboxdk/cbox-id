<?php

declare(strict_types=1);

return [
    // Separate product-bound credential; directory tokens never gain admission authority.
    'product_billing_token' => env('CBOX_ID_PRODUCT_BILLING_TOKEN'),
    'product_enabled' => (bool) env('CBOX_ID_PRODUCT_ACCESS_ENABLED', env('APP_ENV', 'production') === 'production'),
    'name' => 'Cbox · ID',
    'short_name' => 'ID',
    'enabled' => (bool) env('CBOX_WAITLISTS_ENABLED', false),
    // Explicit identity-environment binding prevents a customer IdP borrowing Cbox's catalogue.
    'identity_environment' => env('CBOX_WAITLISTS_ID_ENVIRONMENT'),
    'billing_url' => env('CBOX_WAITLISTS_BILLING_URL'),
    // A server-only Billing operator token with access:enroll, bound to its billing environment.
    'billing_token' => env('CBOX_WAITLISTS_BILLING_TOKEN'),
];
