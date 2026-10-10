<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleRoutes;
use Cbox\Id\Whitelabel\Http\Controllers\BrandAssetController;
use Illuminate\Support\Facades\Route;

/*
 * THIS MODULE HAS NO CONSOLE PAGE OF ITS OWN ANY MORE.
 *
 * Its branding page and the host's Appearance page both set the logo and the colours, and
 * the rail read "Branding › Branding". They are one page now, the host's Branding page
 * (`/branding`, `/admin/branding`, an organization's `/admin/organizations/{id}/branding`),
 * which edits this module's half — name, email sender, welcome mail, console palette —
 * through this module's own action, `branding.whitelabel.set`, asked for by name. Removed,
 * the module takes that section of the page with it and nothing else.
 */

// One URL on both consoles — the environment console always said `/branding`.
ConsoleRoutes::moved('/settings/branding', '/branding');

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
