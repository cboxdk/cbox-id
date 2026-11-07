<?php

return [
    'url' => env('SIGNALS_URL'),
    'token' => env('SIGNALS_TOKEN'),
    'timeout' => env('SIGNALS_TIMEOUT', 2),
    'webhook_secret' => env('SIGNALS_WEBHOOK_SECRET'),
    // Bundled managed integration: the credential that identifies this
    // application towards the enrolment API. Applications usually bind a Cbox
    // ID machine-token implementation of ManagedCredential instead and leave
    // this empty; when set it falls back to `token` when empty.
    'managed' => ['token' => env('SIGNALS_MANAGED_TOKEN')],
];
