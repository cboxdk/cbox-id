<?php

declare(strict_types=1);

use App\Platform\ConsoleLocation;
use App\Platform\Navigation\ConsoleNavigation;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

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
 * The Identity platform area is the one that varies by role, and it varies through the
 * console-kit FEATURE registry rather than through this class — the account console it
 * came from is gone, and its pages are pages of the one console now.
 *
 * The failure guarded against is unchanged and is not a crash: a Viewer offered a
 * Billing link gets a page they cannot open, and an area emptied by role filtering would
 * render a rail icon leading nowhere. What changed is where the answer comes from, so
 * this asks the registry the rail actually reads.
 */
it('hides what an account role may not see, and drops the area when it holds nothing', function (): void {
    platformRootDeployment();

    $result = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'nav-owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    $area = collect(Console::nav()->areas())->firstWhere('key', 'identity-platform');
    $pages = fn (): array => collect($area?->pages() ?? [])
        ->filter(fn ($page): bool => $page->feature === null || Console::featureActive($page->feature))
        ->map(fn ($page): string => $page->route)
        ->values()->all();

    // NOBODY SIGNED IN is not "a member with no permissions" — it is no account at all,
    // which is every organization on every host except the root's own. Every page here is
    // about an account, so the area holds nothing and the rail drops it. The account
    // console used to keep two pages in that state and both were dead ends: Projects
    // explained what a project is and gated its only CTA off, Profile rendered a form
    // bound to nobody.
    expect($pages())->toBe([]);

    signInAsMember($result->member);
    expect($pages())->toContain('billing')
        ->and($pages())->toContain('organization-settings')
        ->and($pages())->toContain('api-keys');

    // A Viewer reads the roster and the bill and changes nothing.
    //
    // A SECOND member, because the first is the account's only owner and demoting the last
    // owner is refused — re-roling an owner orphans the account just as surely as deleting
    // one, which is why it is now refused up front rather than half-written.
    $viewer = app(Memberships::class)->create(
        $result->account->id,
        'viewer@acme.example',
        'a-strong-unbreached-passphrase',
        'Viewer',
    );
    app(Memberships::class)->setRole($viewer->id, MembershipRole::Viewer);
    nextRequest();
    signInAsMember($viewer->refresh());

    expect($pages())->toContain('billing')
        ->and($pages())->not->toContain('organization-settings')
        ->and($pages())->not->toContain('api-keys');
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
        ->and($nav->areaFor('billing'))->toBeNull();

    $audit = $nav->areaFor('environment.audit');
    $pages = array_values(array_filter($audit?->pages ?? [], fn ($page): bool => $page->owns('environment.audit-streams')));

    expect(array_map(fn ($page): string => $page->route, $pages))
        ->toBe(['environment.audit-streams'], 'environment.audit swallowed environment.audit-streams');
});

/**
 * The eyebrow above a page title is the one label whose entire job is telling you where
 * you are. It resolved from the organization console's plugin registry only, so on all
 * 41 account, environment and operator pages it answered null and the eyebrow simply did
 * not render — the orientation feature looked half-built because on three planes out of
 * four it was. Both sources are asked now, which is what lets an Identity platform page
 * (registry) and an environment page (this class) each be placed by the same call.
 */
it('knows where every page in every plane sits', function (): void {
    $location = app(ConsoleLocation::class);

    expect($location->areaLabel('billing'))->toBe('Identity platform')
        ->and($location->areaLabel('account'))->toBe('My account')
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
