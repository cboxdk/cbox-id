<?php

use App\Providers\AppServiceProvider;
use App\Providers\QueueServiceProvider;
use App\Providers\RadarServiceProvider;
use App\Providers\SignalsServiceProvider;

return [
    AppServiceProvider::class,
    QueueServiceProvider::class,
    RadarServiceProvider::class,
    SignalsServiceProvider::class,
];
