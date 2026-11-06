<?php

declare(strict_types=1);

return [
    // Explicit source-owner opt-in, independent of SIGNALS_AUTH_ENABLED.
    'environments' => array_values(array_filter(explode(',', (string) env('SIGNALS_DATA_ENVIRONMENTS', '')))),
    'retention_days' => 7,
    'raw_ip_days' => 7,
    'max_records' => 1000000,
];
