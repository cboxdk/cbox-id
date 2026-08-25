<?php

declare(strict_types=1);

use App\Platform\Navigation\ConsoleNavigation;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * THE RAIL MUST NOT OFFER A DOOR THAT IS LOCKED TO THE PERSON LOOKING AT IT.
 *
 * A page absent from the navigation is a capability somebody does not have. A page PRESENT
 * in the navigation that answers 403 is a bug report: it reads like a permissions problem
 * the visitor should ask somebody to fix, and there is nothing to fix.
 *
 * It happened because the two gates ask different questions at different granularities.
 * The rail's role gate works on whole AREAS — a plain member sees `overview` and `account`
 * — while individual pages call `ConsoleScope::assertMayAdminister()` in their own boot().
 * `Usage` sits inside `overview` and is organization-wide telemetry, so a member was shown
 * a link to a page that refused them. One page, but nothing about the arrangement stopped
 * it being ten.
 *
 * So this walks what the rail ACTUALLY renders and opens every link, rather than asserting
 * about one page somebody remembered. It is the difference between fixing an instance and
 * closing the class.
 */
function railLinksFor(MembershipRole $role): array
{
    $subject = app(Subjects::class)->create($role->value.'@nav.test', 'Nav '.$role->value, 'a-strong-unbreached-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-nav-'.$role->value));
    app(Memberships::class)->add($org->id, $subject->id, $role);
    app(Subjects::class)->markEmailVerified($subject->id, $role->value.'@nav.test');

    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);

    $html = (string) test()->get(route('dashboard'))->assertOk()->getContent();

    // The rail's own links, as rendered. Anchors only — a rail entry is an anchor, and
    // reading them from the markup is what keeps this honest about what a person sees.
    preg_match_all('#<a[^>]+href="(https?://[^"]+)"#i', $html, $matches);

    $host = parse_url((string) config('app.url'), PHP_URL_HOST);

    return collect($matches[1])
        ->filter(fn (string $href): bool => parse_url($href, PHP_URL_HOST) === $host)
        ->map(fn (string $href): string => (string) parse_url($href, PHP_URL_PATH))
        // Logout is a POST target rendered as a link by the shell; opening it with GET
        // proves nothing about authorization.
        ->reject(fn (string $path): bool => str_contains($path, '/logout'))
        ->unique()
        ->values()
        ->all();
}

it('offers a plain member no page that refuses them', function (): void {
    $paths = railLinksFor(MembershipRole::Member);

    expect($paths)->not->toBe([], 'the rail rendered no links at all — the fixture is wrong, not the console');

    $refused = [];

    foreach ($paths as $path) {
        if (test()->get($path)->status() === 403) {
            $refused[] = $path;
        }
    }

    expect($refused)->toBe([]);
})->group('security');

it('offers an owner no page that refuses them', function (): void {
    $refused = [];

    foreach (railLinksFor(MembershipRole::Owner) as $path) {
        if (test()->get($path)->status() === 403) {
            $refused[] = $path;
        }
    }

    expect($refused)->toBe([]);
})->group('security');

/**
 * AND NOTHING IS ROUTED WITH NO WAY TO REACH IT.
 *
 * The opposite failure to the one above, and the one that actually happened: two pages
 * moved from the organization plane to the environment plane, were routed there, and were
 * put on no rail — reachable for a day only by typing the URL, while the getting-started
 * guide told people to look for them in a menu that did not list them.
 *
 * Asserted over the environment rail as a whole rather than for those two pages by name,
 * because naming them is a check that passes for the next page somebody forgets.
 */
it('offers every environment-console page somewhere in its rail', function (): void {
    // Every module on, so a module page absent from the rail is a real omission rather
    // than a switched-off feature correctly hidden.
    config([
        'id-analytics.enabled' => true,
        'compliance.enabled' => true,
        'connectors.enabled' => true,
        'id-devices.enabled' => true,
    ]);

    $railed = collect(app(ConsoleNavigation::class)->environment()->areas)
        ->flatMap(fn ($area) => collect($area->pages)->map(fn ($page) => $page->route))
        ->all();

    // Detail and create pages hang off a list that IS on the rail — a rail entry per
    // route would be a menu nobody could read. The list pages are what must be reachable.
    //
    // GET ONLY. A page is something you navigate to; a POST, PATCH or DELETE is an action
    // performed on one, and it has no rail entry for the same reason `environment.open`
    // below has none. That distinction used to be free, because every console mutation
    // was a Livewire action on a shared endpoint and had no route name of its own.
    $unreachable = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array('GET', $route->methods(), true))
        ->map(fn ($route): ?string => $route->getName())
        ->filter(fn (?string $name): bool => is_string($name)
            && str_starts_with($name, 'environment.')
            && ! str_contains($name, '.create')
            && ! str_contains($name, '.show')
            && ! in_array($name, [
                // Chrome and ceremony rather than pages: the console's own front door, the
                // step-up screen, the sign-out post, the handoff.
                'environment.home', 'environment.sudo', 'environment.logout', 'environment.impersonate',
                // An ACTION, not a page: the signed handoff that opens this environment's
                // console from the account plane. It has no rail entry because it is not
                // somewhere you navigate to.
                'environment.open',
            ], true))
        ->reject(fn (string $name): bool => in_array($name, $railed, true))
        ->values()
        ->all();

    expect($unreachable)->toBe([]);
})->group('ux');
