<?php

return [
    'enabled' => env('SIGNALS_AUTH_ENABLED', false),
    'id_environment' => env('SIGNALS_AUTH_ID_ENVIRONMENT', ''),
    'url' => env('SIGNALS_AUTH_URL', ''),
    'token' => env('SIGNALS_AUTH_TOKEN', ''),
    // Total synchronous deadline, including connection; no retry on the login path.
    'entity_namespace' => env('SIGNALS_AUTH_ENTITY_NAMESPACE', 'cbox-id'),
    'fresh_seconds' => 300,
];
