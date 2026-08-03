<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleRoutes;
use Cbox\Id\Api\Http\Middleware\ResolveEnvironment;
use Cbox\Id\Devices\Http\Controllers\ApprovalController;
use Cbox\Id\Devices\Http\Controllers\BootstrapController;
use Cbox\Id\Devices\Http\Controllers\DeviceController;
use Illuminate\Support\Facades\Route;

/*
 * The administrative half: the estate's enrolled handsets and what the platform pushed
 * to them. Both planes, one component — the middleware stacks live in ConsoleRoutes, and
 * the feature gate is repeated there as well as on the nav item so the URL 404s when the
 * module is off rather than merely being unlinked.
 */
ConsoleRoutes::page(
    feature: 'devices',
    uri: '/sign-in/devices',
    component: 'devices.index',
    name: 'devices.index',
    environmentUri: '/trusted-devices',
);

/*
 * The personal half: my enrolment code, my handsets. ORGANIZATION PLANE ONLY, and
 * deliberately — this is the one module page that is not administration.
 *
 * Every read and write on it is keyed to the signed-in SUBJECT, which is why it carries
 * no admin gate. An environment administrator is a control-plane identity holding an
 * account membership: a subject of the platform root, never a subject inside the
 * environment they administer. They therefore have no handsets here to list and no
 * enrolment to start, so serving it on that plane would render a permanently empty page
 * — the "empty read-only shell" the merge is meant to stop shipping, not create.
 *
 * Their own devices belong to their own identity, and live in the workspace console
 * where the rest of it does.
 */
ConsoleRoutes::organizationPage(
    feature: 'devices',
    uri: '/account/devices',
    component: 'devices.mine',
    name: 'devices.mine',
);

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
/*
 * Discovery. Unauthenticated, and deliberately so: a public client's id is not a
 * credential, and each environment mints its own because oauth_clients.client_id is
 * globally unique — so one binary can serve every tenant only by asking.
 */
Route::middleware(['api', ResolveEnvironment::class, 'throttle:60,1'])
    ->get('/.well-known/cbox-authenticator', BootstrapController::class)
    ->name('devices.bootstrap');

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
