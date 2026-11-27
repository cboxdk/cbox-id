<?php

declare(strict_types=1);

return [
    // All three must be explicitly configured after every approval host supports binding.
    'infra' => [
        'environment_id' => env('CBOX_ID_CIBA_INFRA_ENVIRONMENT_ID', ''),
        'client_id' => env('CBOX_ID_CIBA_INFRA_CLIENT_ID', ''),
        'resource' => env('CBOX_ID_CIBA_INFRA_RESOURCE', ''),
    ],
];
