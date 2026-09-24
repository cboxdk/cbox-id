<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleRoutes;
use Cbox\Id\Whitelabel\Http\Controllers\BrandingController;

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
