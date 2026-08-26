<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleRoutes;
use Cbox\Id\RiskPlus\Http\Controllers\RiskEventsController;

/*
 * Both planes, one component — the middleware stacks live in ConsoleRoutes.
 *
 * Risk events are recorded per ENVIRONMENT (they carry an email and no organization), so
 * the environment plane is the one where the feed is complete. It was the plane that
 * could not open it: the module is always on, and an environment under credential
 * stuffing was visible only to whichever tenant's members happened to be targeted.
 */
ConsoleRoutes::page(
    feature: 'risk-plus',
    uri: '/security/risk-events',
    component: RiskEventsController::class,
    name: 'risk-plus.events',
);
