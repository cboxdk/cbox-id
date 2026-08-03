<?php

declare(strict_types=1);

use App\Platform\ConsoleLocation;
use App\Platform\Navigation\ConsoleNavigation;
use Cbox\Id\Platform\Enums\AccountRole;
use Illuminate\Support\Facades\Route;

/**
 * A nav entry pointing at a route that does not exist throws on EVERY page of that
 * plane, because the sidebar renders on all of them — so a typo here is not a broken
 * link, it is a whole console down. Cheap to check, and the check is the reason the
 * lists are readable PHP instead of arrays buried in a Blade file.
 */
it('names only routes that exist', function (): void {
    $navigation = new ConsoleNavigation;
    $missing = [];

    foreach ($navigation->all() as $nav) {
        foreach ($nav->routes() as $route) {
            if (! Route::has($route)) {
                $missing[] = $route;
            }
        }
    }

    // Nothing to check means the extraction broke, not that the nav is clean.
    expect(count($navigation->environment()->routes()))->toBeGreaterThan(10);
    expect($missing)->toBe([], 'nav entries with no route: '.implode(', ', $missing));
});

/**
 * Two areas sharing a label collapse into one in the rail — the projection keys on the
 * label, so the second one is unreachable and its pages simply vanish from the console
 * with nothing failing anywhere.
 */
it('gives every area within a plane a distinct label', function (): void {
    foreach ((new ConsoleNavigation)->all() as $nav) {
        $labels = array_map(fn ($area): string => $area->label, $nav->areas);

        expect($labels)->toBe(array_values(array_unique($labels)), 'duplicate area label: '.implode(', ', $labels));
    }
});

/**
 * The workspace nav is the one that varies by role, and the failure it guards against
 * is not a crash: a Viewer seeing a Billing link gets a page they cannot open, and an
 * area emptied by role filtering would render a rail icon leading nowhere.
 */
it('hides what a role may not see, and drops an area left empty', function (): void {
    $navigation = new ConsoleNavigation;

    $ownerRoutes = $navigation->workspace(AccountRole::Owner)->routes();
    $viewerRoutes = $navigation->workspace(AccountRole::Viewer)->routes();

    expect($ownerRoutes)->toContain('workspace.billing')
        ->and($ownerRoutes)->toContain('workspace.settings')
        ->and($viewerRoutes)->not->toContain('workspace.settings')
        ->and($viewerRoutes)->not->toContain('workspace.api-keys');

    // Whatever the role, no area survives with nothing in it.
    foreach (AccountRole::cases() as $role) {
        foreach ($navigation->workspace($role)->areas as $area) {
            expect($area->pages)->not->toBe([], "{$role->value} sees an empty '{$area->label}' area");
        }
    }

    // And with no member resolved at all, the nav still stands up.
    expect($navigation->workspace(null)->routes())->toContain('workspace.home');
});

/**
 * Prefix matching, which is the subtle part. `environment.audit` must claim its own
 * detail routes and NOT `environment.audit-streams`, which is a different page in the
 * same area — the bug this replaced was exactly that, two nav items lit at once.
 */
it('claims a page detail route without claiming its prefix siblings', function (): void {
    $nav = (new ConsoleNavigation)->environment();

    expect($nav->areaFor('environment.audit')?->label)->toBe('Logs')
        ->and($nav->areaFor('environment.audit.show')?->label)->toBe('Logs')
        ->and($nav->areaFor('environment.users.show')?->label)->toBe('People')
        ->and($nav->areaFor('workspace.billing'))->toBeNull();

    $audit = $nav->areaFor('environment.audit');
    $pages = array_values(array_filter($audit?->pages ?? [], fn ($page): bool => $page->owns('environment.audit-streams')));

    expect(array_map(fn ($page): string => $page->route, $pages))
        ->toBe(['environment.audit-streams'], 'environment.audit swallowed environment.audit-streams');
});

/**
 * The eyebrow above a page title is the one label whose entire job is telling you where
 * you are. It resolved from the organization console's plugin registry only, so on all
 * 41 workspace, environment and operator pages it answered null and the eyebrow simply
 * did not render — the orientation feature looked half-built because on three planes out
 * of four it was.
 */
it('knows where every page in every plane sits', function (): void {
    $location = app(ConsoleLocation::class);

    expect($location->areaLabel('workspace.billing'))->toBe('Account')
        ->and($location->areaLabel('workspace.security'))->toBe('Personal')
        ->and($location->areaLabel('environment.connections'))->toBe('Sign-in')
        ->and($location->areaLabel('environment.users.show'))->toBe('People')
        ->and($location->areaLabel('platform.usage'))->toBe('Insights')
        ->and($location->areaLabel('platform.operators'))->toBe('Administration');

    // A route belonging to no plane's navigation still answers null rather than
    // guessing — the eyebrow is omitted, not wrong.
    expect($location->areaLabel('login'))->toBeNull()
        ->and($location->areaLabel(''))->toBeNull();
});

/**
 * Coverage, stated as a number so it cannot quietly regress: every route the three
 * fixed-plane navs declare must resolve. A page added to a layout but not here would
 * pass the existence check above and still ship with no eyebrow.
 */
it('resolves an area for every navigable route', function (): void {
    $location = app(ConsoleLocation::class);
    $unplaced = [];

    foreach ((new ConsoleNavigation)->all() as $nav) {
        foreach ($nav->routes() as $route) {
            if ($location->areaLabel($route) === null) {
                $unplaced[] = $route;
            }
        }
    }

    expect($unplaced)->toBe([], 'no eyebrow would render on: '.implode(', ', $unplaced));
});
