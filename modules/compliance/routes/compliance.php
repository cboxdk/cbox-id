<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleRoutes;

/*
 * Both planes, one component each — the middleware stacks live in ConsoleRoutes.
 *
 * Compliance is the module with the least excuse for having been organization-only: the
 * person a regulator asks for an environment's audit exports and retention posture is
 * the environment administrator, and they could not open either page.
 */
ConsoleRoutes::page(
    feature: 'compliance',
    uri: '/compliance/audit',
    component: 'compliance.audit',
    name: 'compliance.audit',
);

ConsoleRoutes::page(
    feature: 'compliance',
    uri: '/compliance/exports',
    component: 'compliance.exports',
    name: 'compliance.exports',
);
