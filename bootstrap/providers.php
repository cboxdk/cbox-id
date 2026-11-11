<?php

use App\Assistant\ServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\QueueServiceProvider;
use App\Providers\RadarServiceProvider;
use App\Providers\ServiceConnectionsServiceProvider;
use App\Providers\ShellServiceProvider;
use App\Providers\SignalsDataServiceProvider;
use App\Providers\SignalsServiceProvider;

return [
    ServiceConnectionsServiceProvider::class,
    AppServiceProvider::class,
    ServiceProvider::class,
    ShellServiceProvider::class,
    QueueServiceProvider::class,
    RadarServiceProvider::class,
    SignalsServiceProvider::class,
    SignalsDataServiceProvider::class,
];
