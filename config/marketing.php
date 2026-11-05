<?php

declare(strict_types=1);

return [
    // Opt-in: self-hosted installations keep their existing application homepage.
    'host' => env('CBOX_ID_MARKETING_HOST'),
    'app_url' => env('CBOX_ID_MARKETING_APP_URL', env('APP_URL', 'http://localhost')),
];
