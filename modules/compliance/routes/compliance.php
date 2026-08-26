<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleRoutes;
use Cbox\Id\Compliance\Http\Controllers\AuditTrailController;
use Cbox\Id\Compliance\Http\Controllers\ExportsController;

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
    component: [AuditTrailController::class, 'index'],
    name: 'compliance.audit',
);

ConsoleRoutes::page(
    feature: 'compliance',
    uri: '/compliance/exports',
    component: [ExportsController::class, 'index'],
    name: 'compliance.data-exports',
);

/*
 * The one WRITE on either page: handing over one person's audit trail for a subject access
 * request. A POST rather than a GET, because it is recorded on the trail as an event in its
 * own right — and a link somebody can prefetch is not a thing to record.
 */
ConsoleRoutes::action(
    feature: 'compliance',
    verb: 'post',
    uri: '/compliance/exports/subject',
    action: [ExportsController::class, 'download'],
    name: 'compliance.data-exports.download',
);
