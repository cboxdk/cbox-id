<?php

declare(strict_types=1);

use App\Http\Controllers\HealthStatusController;
use Cbox\LaravelHealth\Http\Middleware\AllowIps;
use Cbox\LaravelHealth\Http\Middleware\EndpointAuth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| /health/status — this application's, in place of the package's.
|--------------------------------------------------------------------------
|
| The package's status route is switched off in config/health.php and this one takes its
| path, name and middleware, so it answers exactly where the package's did, behind the
| same token — plus the checks that must alert without routing. See
| HealthStatusController.
|
*/

if (! config('health.enabled', true)) {
    return;
}

$prefix = config('health.endpoints.prefix', 'health');
$middleware = config('health.middleware', ['api']);

Route::prefix(is_string($prefix) ? $prefix : 'health')
    ->middleware([...(is_array($middleware) ? $middleware : ['api']), AllowIps::class])
    ->get('/status', HealthStatusController::class)
    ->middleware(EndpointAuth::class.':status')
    ->name('health.status');
