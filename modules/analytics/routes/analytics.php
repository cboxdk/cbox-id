<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleRoutes;
use Cbox\Id\Analytics\Http\Controllers\SignInActivityController;

/*
 * One page, both planes, one component — the middleware stacks live in ConsoleRoutes.
 *
 * The route NAME is deliberately not `analytics.*`. The environment console had a page
 * named `environment.analytics` (the environment's usage counters, `environment.usage`
 * now), and a nav entry claims its own sub-routes by prefix — so
 * `environment.analytics.overview` would have lit that entry as well as this one, two highlighted items in a single sub-nav,
 * which is the exact bug ConsoleNavigationTest pins for `environment.audit` and
 * `environment.audit-streams`.
 *
 * The two pages are not the same thing and both belong: that one aggregates usage
 * counters across the whole environment, this one charts sign-ins, tokens issued, new
 * users and MFA enrolments for ONE organization — which on the environment plane is the
 * per-tenant drill-down an environment administrator never had. So they get names, and
 * titles, that say which is which.
 *
 * ONE URL on both consoles now, `/sign-in-activity`. The organization console served it at
 * `/analytics`, which the environment console used for its usage page — the same word for
 * two different pages, one host apart. The old spelling answers 301.
 */
ConsoleRoutes::page(
    feature: 'analytics',
    uri: '/sign-in-activity',
    component: SignInActivityController::class,
    name: 'sign-in-activity',
);

ConsoleRoutes::moved('/analytics', '/sign-in-activity');
