<?php

declare(strict_types=1);

return [
    'eligibility_key' => env('ID_ONBOARDING_ELIGIBILITY_KEY'),
    // Collection only. This does not publish an automation or enable email sending.
    'engage_enabled' => (bool) env('ID_ONBOARDING_ENGAGE_ENABLED', false),
    // The source must resolve platform-root workspace IDs, not customer-tenant users.
    'identity_namespace' => 'cbox-id-platform-root',
];
