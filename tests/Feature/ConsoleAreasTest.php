<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\Navigation\ConsoleNav;
use App\Platform\Navigation\ConsoleNavigation;
use App\Platform\PlatformAuth;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * Invariants of the console's information architecture. All three were broken in
 * shipped code, and none is the kind of thing anyone notices reading a diff.
 */
it('gives every nav area a unique order', function (): void {
    // DefaultNavRegistry::areas() sorts on `order` alone, so a tie resolves by service
    // provider boot order: the rail silently reshuffles when a module is enabled or
    // disabled. The console shipped Logs/Security both at 60 and Settings/Connectors
    // both at 70.
    $orders = collect(Console::nav()->areas())->map(fn ($area): int => $area->order);

    expect($orders->duplicates()->values()->all())->toBe([]);
});

it('never puts two rail areas on the same subject', function (): void {
    $keys = collect(Console::nav()->areas())->pluck('key')->all();

    // Analytics belongs beside Overview › Usage, compliance's audit pages beside the
    // activity log, and risk events in Logs — not each in an area of its own. A rail
    // with one area per module asks the reader to learn our module boundaries.
    expect($keys)->not->toContain('analytics')
        ->and($keys)->not->toContain('compliance')
        ->and($keys)->not->toContain('security');
});

/**
 * The promise a nav label makes: click "Token vault" and you land on a page headed
 * "Token vault". Six host pages and two plugin pages broke it. Asserted over the
 * rendered document rather than the source, so it holds for any page a module adds
 * later without that module knowing this test exists.
 */
it('lands every nav entry on a page titled the way the entry is labelled', function (): void {
    $subject = app(Subjects::class)->create('nav@acme.test', 'Nav Admin', 'super-secret-1234');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-areas'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);

    // Behind the sudo step-up gate: it redirects, so there is no title to read.
    $behindStepUp = ['vault'];

    $checked = 0;

    foreach (Console::nav()->areas() as $area) {
        foreach ($area->pages() as $page) {
            if (in_array($page->route, $behindStepUp, true) || ! Route::has($page->route)) {
                continue;
            }

            $response = $this->get(route($page->route));

            // A page gated off in this environment (inactive module, missing
            // entitlement) is not a naming failure — only a reachable page is checked.
            if ($response->status() !== 200) {
                continue;
            }

            expect($response->getContent())->toContain('<title>'.e($page->label).' · ');
            $checked++;
        }
    }

    // Guard against the loop silently checking nothing: a broken session would 302
    // every page and turn this into a test that always passes.
    expect($checked)->toBeGreaterThanOrEqual(12);
});

/**
 * The same promise, on the planes the test above cannot see.
 *
 * `Console::nav()` is the plugin registry, which is the ORGANIZATION console. The
 * environment plane and the platform section declare themselves in
 * {@see ConsoleNavigation}, so nothing reached them and both had shipped violations: the
 * rail said "Profile" while the tab said "Security" and the heading said "Profile &
 * security"; the rail said "Domains" over a page headed "Environment domains". The
 * organization console does not have this class of bug precisely because it has had a
 * test since the day it was written, so the guard is the durable half of the fix, not
 * the renames.
 *
 * Three assertions per page, not one: nav label === <title> === <h1>. A page can satisfy
 * any two of those and still tell a person three different things.
 */
function assertNavEntriesMatchTheirPages(ConsoleNav $nav, string $plane): int
{
    $entries = [];

    foreach ($nav->areas as $area) {
        foreach ($area->pages as $page) {
            $entries[] = [$area->label, $page->label, $page->route];
        }
    }

    return assertNavEntryLabelsMatchTheirPages($entries, $plane);
}

/**
 * The same check, over entries taken from the console-kit REGISTRY rather than off a
 * statically-declared {@see ConsoleNav}.
 *
 * Two sources because the console has two kinds of navigation and only one of them is a
 * value object: the environment plane declares its rail statically, while everything the
 * one console renders — the organization areas and the platform section alike — is
 * assembled at runtime from the registry. The promise being checked is identical either
 * way, so the loop is, and only the flattening differs.
 *
 * @param  list<array{0: string, 1: string, 2: string}>  $entries  [area label, page label, route]
 */
function assertNavEntryLabelsMatchTheirPages(array $entries, string $plane): int
{
    $checked = 0;

    foreach ($entries as [$areaLabel, $pageLabel, $route]) {
        if (! Route::has($route)) {
            continue;
        }

        $response = test()->get(route($route));

        // Gated off in this environment (inactive module, missing entitlement, a
        // shape this deployment does not have) is not a naming failure.
        if ($response->status() !== 200) {
            continue;
        }

        $html = (string) $response->getContent();
        $label = e($pageLabel);

        // `toContain` is variadic in Pest, so a message passed to it reads as a
        // second needle and the failure names the wrong thing. Assert the boolean.
        expect(str_contains($html, '<title>'.$label.' · '))->toBeTrue(
            "{$plane} › {$areaLabel} › {$pageLabel}: the nav entry and the <title> disagree",
        );

        // The h1 carries classes and whitespace, so match on its text rather than on
        // a whole tag: this has to hold for a heading rendered by x-page-header and
        // for one a page still writes itself.
        expect((bool) preg_match('/<h1[^>]*>\s*'.preg_quote($label, '/').'\s*</', $html))->toBeTrue(
            "{$plane} › {$areaLabel} › {$pageLabel}: the nav entry and the <h1> disagree",
        );

        $checked++;
    }

    return $checked;
}

/**
 * The Identity platform area is checked by the ORGANIZATION-console loop above, not here:
 * its pages are in `Console::nav()` like every other console page. It gets its own sign-in
 * because that loop's fixture is an ordinary org member, for whom every one of these
 * pages is feature-gated off and skipped as a non-200 — so without this, the area's eight
 * pages would be "checked" by being invisible.
 */
it('lands every Identity platform nav entry on a page titled the way the entry is labelled', function (): void {
    platformRootDeployment();

    $result = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'areas-owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    signInAsMember($result->owner->id);

    $area = collect(Console::nav()->areas())->firstWhere('key', 'identity-platform');
    $checked = 0;

    foreach ($area?->pages() ?? [] as $page) {
        $response = $this->get(route($page->route));

        expect($response->status())->toBe(200, "identity-platform › {$page->label}: not reachable by its owner");

        $html = (string) $response->getContent();
        $label = e($page->label);

        // `toContain` is variadic in Pest, so a message passed to it reads as a second
        // needle and the failure names the wrong thing. Assert the boolean.
        expect(str_contains($html, '<title>'.$label.' · '))->toBeTrue(
            "identity-platform › {$page->label}: the nav entry and the <title> disagree",
        );
        expect((bool) preg_match('/<h1[^>]*>\s*'.preg_quote($label, '/').'\s*</', $html))->toBeTrue(
            "identity-platform › {$page->label}: the nav entry and the <h1> disagree",
        );

        $checked++;
    }

    // Eight until Identity platform › Activity was retired into Logs › Activity log,
    // which reads the same hash-chained entries for the same organization. The count is
    // exact for the reason above: a shrinking number must be a decision.
    expect($checked)->toBe(7);
});

it('lands every environment nav entry on a page titled and headed the way the entry is labelled', function (): void {
    // The environment plane is where the module-declared pages are merged in, so this is
    // also the loop that catches a module naming its page one thing in the rail and
    // another in its own heading — without that module knowing this test exists.
    $setup = crudSetup();

    $checked = assertNavEntriesMatchTheirPages(
        app(ConsoleNavigation::class)->environment(),
        'environment',
    );

    expect($setup['envId'])->not->toBe('')
        ->and($checked)->toBeGreaterThanOrEqual(10);
});

it('lands every platform nav entry on a page titled and headed the way the entry is labelled', function (): void {
    actAsOperator();

    // From the REGISTRY, because the platform section is three areas of the one console
    // now rather than a nav tree of its own. Filtered to the platform areas by key: the
    // organization areas beside them are covered by their own loop above, with a fixture
    // that admits them.
    $platformAreas = ['platform', 'platform-insights', 'platform-admin'];
    $entries = [];

    foreach (Console::nav()->areas() as $area) {
        if (! in_array($area->key, $platformAreas, true)) {
            continue;
        }

        foreach ($area->pages() as $page) {
            $entries[] = [$area->label, $page->label, $page->route];
        }
    }

    $checked = assertNavEntryLabelsMatchTheirPages($entries, 'platform');

    // Six pages across three areas; anything less means the session stopped resolving
    // and the loop is asserting nothing. It was seven until the operator "Security" page
    // was retired — it enrolled a second factor nothing verified, so the screen promised
    // a protection that did not exist. The count is deliberately exact for the reason in
    // the comment above: a shrinking number must be a decision, not a silent no-op.
    expect($checked)->toBe(6);
});

/**
 * TWO REGISTRATIONS ON ONE URI SILENTLY DELETE A PAGE, and two nav entries on one route
 * silently send a click to the wrong area.
 *
 * `/members` was registered twice with the name `members`. Laravel keys the route
 * collection on `method|domain|uri`, so the second replaced the first: a 600-line
 * component became unreachable from any URL, and because two areas then named the same
 * route, clicking "People" lit up "Identity platform" — with a matching eyebrow, and
 * "Roles" gone from the sub-nav — because the layout picks the FIRST area containing the
 * active route and `identity-platform` sorts lower.
 *
 * Nothing could see it. The suite already asserts unique area orders and unique labels;
 * neither is violated by two pages sharing a route, and no HTTP test fails when the URL
 * still answers 200 — with the other component.
 */
it('registers no route twice and points no two nav entries at one route', function (): void {
    $seen = [];
    $duplicates = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        foreach ($route->methods() as $method) {
            $key = $method.'|'.($route->getDomain() ?? '').'|'.$route->uri();

            if (isset($seen[$key])) {
                $duplicates[] = $key;
            }

            $seen[$key] = true;
        }
    }

    expect($duplicates)->toBe([], 'these URIs are registered more than once, so all but the last are dead: '.implode(', ', $duplicates));

    // And the nav half: an area is chosen by first-match on the active route, so two
    // entries naming one route make the rail's answer depend on area order.
    $routes = [];
    $shared = [];

    foreach (Console::nav()->areas() as $area) {
        foreach ($area->pages() as $page) {
            if (isset($routes[$page->route])) {
                $shared[] = $page->route.' ('.$routes[$page->route].' and '.$area->key.')';
            }

            $routes[$page->route] = $area->key;
        }
    }

    expect($shared)->toBe([], 'these routes are claimed by two areas, so the rail lights the wrong one: '.implode(', ', $shared));
});
