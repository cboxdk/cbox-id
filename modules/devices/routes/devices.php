<?php

declare(strict_types=1);

use App\Http\Middleware\BlockDuringImpersonation;
use App\Platform\Console\ConsoleRoutes;
use Cbox\Id\Api\Http\Middleware\ResolveEnvironment;
use Cbox\Id\Devices\Http\Controllers\AppReviewController;
use Cbox\Id\Devices\Http\Controllers\ApprovalController;
use Cbox\Id\Devices\Http\Controllers\BootstrapController;
use Cbox\Id\Devices\Http\Controllers\DeviceController;
use Cbox\Id\Devices\Http\Controllers\DeviceInventoryController;
use Cbox\Id\Devices\Http\Controllers\MyDevicesController;
use Cbox\Id\Devices\Http\Controllers\PrivacyController;
use Cbox\Id\Devices\Http\Middleware\RequireAuthenticator;
use Illuminate\Support\Facades\Route;

/*
 * The administrative half: the estate's enrolled handsets and what the platform pushed
 * to them. Both planes, one component — the middleware stacks live in ConsoleRoutes, and
 * the feature gate is repeated there as well as on the nav item so the URL 404s when the
 * module is off rather than merely being unlinked.
 */
ConsoleRoutes::page(
    feature: 'devices',
    uri: '/trusted-devices',
    component: DeviceInventoryController::class,
    name: 'devices.index',
);

// One URL on both consoles: the organization console said `/sign-in/devices` for the page
// the environment console calls `/trusted-devices`, which is also what the rail says.
ConsoleRoutes::moved('/sign-in/devices', '/trusted-devices');

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
    component: [MyDevicesController::class, 'index'],
    name: 'devices.mine',
);

/*
 * Removing one of your OWN handsets.
 *
 * Its own route with its own verb, so the impersonation guard refuses it on the method —
 * under Volt it was a component action on the shared update endpoint, which route
 * middleware never saw.
 *
 * ORGANIZATION PLANE ONLY, matching the page above and for the same reason: an environment
 * administrator has no handsets here to remove.
 */
ConsoleRoutes::organizationAction(
    feature: 'devices',
    verb: 'delete',
    uri: '/account/devices/{device}',
    action: [MyDevicesController::class, 'destroy'],
    name: 'devices.mine.destroy',
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
            ->middleware(['scope:devices.manage', RequireAuthenticator::class.':enrolling'])
            ->name('api.devices.store');

        Route::get('authenticator/me', [DeviceController::class, 'me'])
            ->middleware(['scope:devices.manage', RequireAuthenticator::class.':enrolling'])
            ->name('api.authenticator.me');

        Route::get('devices', [DeviceController::class, 'index'])
            ->middleware(['scope:devices.manage', RequireAuthenticator::class])
            ->name('api.devices.index');

        Route::delete('devices/{id}', [DeviceController::class, 'destroy'])
            ->middleware(['scope:devices.manage', RequireAuthenticator::class])
            ->name('api.devices.destroy');

        // Read and write are separate scopes so a future read-only surface — a watch
        // complication, a home-screen widget — can show pending approvals without
        // carrying the authority to answer them.
        Route::get('approvals', [ApprovalController::class, 'index'])
            ->middleware(['scope:approvals.read', RequireAuthenticator::class])
            ->name('api.approvals.index');

        Route::get('approvals/{id}', [ApprovalController::class, 'show'])
            ->middleware(['scope:approvals.read', RequireAuthenticator::class])
            ->name('api.approvals.show');

        Route::post('approvals/{id}/approve', [ApprovalController::class, 'approve'])
            ->middleware(['scope:approvals.write', RequireAuthenticator::class])
            ->name('api.approvals.approve');

        Route::post('approvals/{id}/deny', [ApprovalController::class, 'deny'])
            ->middleware(['scope:approvals.write', RequireAuthenticator::class])
            ->name('api.approvals.deny');
    });

// Public policy/support page for the App Store listing; no account or session required.
Route::get('/authenticator/privacy', PrivacyController::class)->middleware('throttle:60,1')->name('authenticator.privacy');

// A real approval exercise, available only to the explicitly configured review subject.
Route::middleware(['web', 'plane:console', 'platform.auth', 'console.customer', 'console.root', BlockDuringImpersonation::class])
    ->prefix('authenticator/review')
    ->name('authenticator.review')
    ->group(function (): void {
        Route::get('/', [AppReviewController::class, 'show'])->name('.show');
        Route::post('/', [AppReviewController::class, 'send'])->name('.send');
    });
