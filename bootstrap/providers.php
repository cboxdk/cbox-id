<?php

use App\Providers\AppServiceProvider;
use App\Providers\QueueServiceProvider;
use App\Providers\RadarServiceProvider;
use App\Providers\ServiceConnectionsServiceProvider;
use App\Providers\SignalsServiceProvider;

return [
    ServiceConnectionsServiceProvider::class,
    AppServiceProvider::class,
    QueueServiceProvider::class,
    RadarServiceProvider::class,
    SignalsServiceProvider::class,
];
