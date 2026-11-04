<?php

use App\Providers\AppServiceProvider;
use App\Providers\QueueServiceProvider;
use App\Providers\RadarServiceProvider;
use App\Providers\ServiceConnectionsServiceProvider;

return [
    ServiceConnectionsServiceProvider::class,
    AppServiceProvider::class,
    QueueServiceProvider::class,
    RadarServiceProvider::class,
];
