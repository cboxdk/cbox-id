<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleRoutes;

/*
 * One page, both planes, one component — the middleware stacks live in ConsoleRoutes.
 *
 * The route NAME is deliberately not `analytics.*`. The environment console already has
 * a page named `environment.analytics` (the environment's usage counters), and a nav
 * entry claims its own sub-routes by prefix — so `environment.analytics.overview` would
 * have lit that entry as well as this one, two highlighted items in a single sub-nav,
 * which is the exact bug ConsoleNavigationTest pins for `environment.audit` and
 * `environment.audit-streams`.
 *
 * The two pages are not the same thing and both belong: that one aggregates usage
 * counters across the whole environment, this one charts sign-ins, tokens issued, new
 * users and MFA enrolments for ONE organization — which on the environment plane is the
 * per-tenant drill-down an environment administrator never had. So they get names, and
 * titles, that say which is which.
 *
 * The organization plane's URL is unchanged; only the route name moved.
 */
ConsoleRoutes::page(
    feature: 'analytics',
    uri: '/analytics',
    component: 'analytics.dashboard',
    name: 'sign-in-activity',
    environmentUri: '/sign-in-activity',
);
