<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleRoutes;
use Cbox\Id\Whitelabel\Http\Controllers\BrandAssetController;
use Cbox\Id\Whitelabel\Http\Controllers\BrandingController;
use Illuminate\Support\Facades\Route;

/*
 * Both planes, one component — the middleware stacks live in ConsoleRoutes.
 *
 * The brand-profile table has always had two altitudes: a row per organization, and one
 * row with `organization_id IS NULL` that every organization inherits. The page could
 * only ever be opened by an organization admin, so the environment default — the thing
 * the data model was shaped around, and the only altitude an environment administrator
 * owns — had no editor at all. A previous fix pinned the page to the organization
 * altitude to stop one tenant re-branding the whole environment; this gives the missing
 * altitude to the plane it belongs to instead of leaving it unreachable.
 */
ConsoleRoutes::page(
    feature: 'whitelabel',
    uri: '/branding',
    component: [BrandingController::class, 'index'],
    name: 'whitelabel.branding',
);

// One URL on both consoles — the environment console always said `/branding`.
ConsoleRoutes::moved('/settings/branding', '/branding');

ConsoleRoutes::action(
    feature: 'whitelabel',
    verb: 'post',
    uri: '/branding',
    action: [BrandingController::class, 'save'],
    name: 'whitelabel.branding.save',
);

/*
 * An uploaded logo or favicon ({@see \Cbox\Id\Whitelabel\Assets\DatabaseBrandAssetStore}).
 *
 * OUTSIDE the console stacks and outside `web`: no session, no cookie, no plane — it is an
 * image on a hosted sign-in page, asked for by anonymous visitors on whichever host serves
 * that page. The pattern is the store's own path shape and nothing wider.
 */
Route::get('/brand-assets/{path}', BrandAssetController::class)
    ->where('path', 'brand/[A-Za-z0-9_-]+/[a-z0-9]+-[a-f0-9]{16}\.[a-z0-9]{1,5}')
    ->name('whitelabel.asset');
