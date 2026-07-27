<?php

declare(strict_types=1);

use App\Http\Middleware\EnforceImpersonationWindow;
use Cbox\Id\Api\Http\Middleware\ResolveEnvironment;
use Cbox\Id\Devices\Http\Controllers\ApprovalController;
use Cbox\Id\Devices\Http\Controllers\DeviceController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

/*
 * The console surface. The feature gate is repeated here as well as on the nav item so
 * the URL 404s when the module is off, rather than merely being unlinked — an unlinked
 * page is still a reachable page.
 */
Route::middleware([
    'web',
    // The same stack every host console route carries. `plane:subject` confines the page
    // to a tenant host, and the impersonation window is enforced rather than inherited —
    // an operator impersonating a user must not read a device inventory outside it.
    'plane:subject',
    EnforceImpersonationWindow::class,
    'platform.auth',
    'console.feature:devices',
])->group(function (): void {
    Volt::route('/sign-in/devices', 'devices.index')->name('devices.index');
});

/*
 * The authenticator app's API.
 *
 * Loaded from the module rather than the host's routes/api.php, so the `api` middleware
 * group and the `/api` prefix — which withRouting() applies automatically there — have
 * to be stated here.
 *
 * ResolveEnvironment maps the request host to an environment so the deny-by-default
 * tenancy scope engages; without it every model read below resolves to no rows. It
 * lives in laravel-id, not the host, so referencing it costs the module nothing.
 *
 * `scope:` authenticates the OAuth access token, pins the RFC 8707 audience to this
 * issuer, checks the scope, and enforces DPoP sender-constraining when the token
 * carries a `cnf.jkt` — which the authenticator's tokens always do.
 */
Route::middleware(['api', ResolveEnvironment::class, 'throttle:api-devices'])
    ->prefix('api/v1')
    ->group(function (): void {
        Route::post('devices', [DeviceController::class, 'store'])
            ->middleware('scope:devices.manage')
            ->name('api.devices.store');

        Route::get('devices', [DeviceController::class, 'index'])
            ->middleware('scope:devices.manage')
            ->name('api.devices.index');

        Route::delete('devices/{id}', [DeviceController::class, 'destroy'])
            ->middleware('scope:devices.manage')
            ->name('api.devices.destroy');

        // Read and write are separate scopes so a future read-only surface — a watch
        // complication, a home-screen widget — can show pending approvals without
        // carrying the authority to answer them.
        Route::get('approvals', [ApprovalController::class, 'index'])
            ->middleware('scope:approvals.read')
            ->name('api.approvals.index');

        Route::get('approvals/{id}', [ApprovalController::class, 'show'])
            ->middleware('scope:approvals.read')
            ->name('api.approvals.show');

        Route::post('approvals/{id}/approve', [ApprovalController::class, 'approve'])
            ->middleware('scope:approvals.write')
            ->name('api.approvals.approve');

        Route::post('approvals/{id}/deny', [ApprovalController::class, 'deny'])
            ->middleware('scope:approvals.write')
            ->name('api.approvals.deny');
    });
