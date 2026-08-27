<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleRoutes;
use Cbox\Id\Connectors\Http\Controllers\CatalogController;
use Cbox\Id\Connectors\Http\Controllers\ConnectionsController;

/*
 * Both planes, one component each — the middleware stacks live in ConsoleRoutes.
 *
 * The catalogue is the same on either plane; the connections list is the one that
 * changes, because what it lists is whatever organization the scope resolves — and on
 * the environment plane with none chosen, every connector in the environment, which is
 * the overview the person who owns the environment is entitled to.
 */
ConsoleRoutes::page(
    feature: 'connectors',
    uri: '/connectors',
    component: CatalogController::class,
    name: 'connectors.catalog',
);

ConsoleRoutes::page(
    feature: 'connectors',
    uri: '/connectors/connections',
    component: ConnectionsController::class,
    name: 'connectors.connections',
);
