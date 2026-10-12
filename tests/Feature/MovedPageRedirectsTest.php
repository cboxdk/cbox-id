<?php

declare(strict_types=1);

use App\Http\Controllers\MovedPageController;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Models\Environment;
use Illuminate\Routing\Route;

/*
|--------------------------------------------------------------------------
| EVERY OLD SPELLING OF A PAGE LANDS ON A PAGE, ON EVERY HOST
|--------------------------------------------------------------------------
|
| The console's moved pages (`/admin/applications` → `/admin/apps`, `/clients` → `/apps`,
| …) were registered with no gate of their own, so they answered on every host — while
| the pages they point at do not. On cboxid.com, the platform root, there was no `/admin`
| at all then, so 24 old addresses answered `301 → /admin/… → 404` in production: a
| redirect whose only effect was to move the 404 somewhere else.
|
| There is an `/admin` on the root now — the root environment's own console, which its
| operators administer — so there the old addresses redirect like anywhere else. Where
| there is still no environment console (a name that merely resolves to the root, and a
| single-tenant install) they 404 with it.
|
| An old address now carries the same EXISTENCE gates as the family it moved into, so it
| answers on exactly the hosts its destination does, and 404s itself everywhere else.
| This follows every registered one, on the platform root, on an environment's host and
| on a single-tenant install, as a visitor with no session: the destination must answer
| with something other than a 404 — the page, or the sign-in it sends a visitor to.
*/

/**
 * Every registered moved page, as [old path, new path] with each `{parameter}` filled.
 *
 * @return list<array{0: string, 1: string}>
 */
function movedPages(): array
{
    $moved = [];

    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        /** @var Route $route */
        if ($route->getAction('controller') !== MovedPageController::class) {
            continue;
        }

        $fill = static fn (string $path): string => (string) preg_replace('/\{[^}]+\}/', '01JMOVEDPAGEPROBE0000000000', $path);

        $moved[] = ['/'.ltrim($fill($route->uri()), '/'), $fill((string) $route->defaults['to'])];
    }

    return $moved;
}

/**
 * Follow each moved page on $host and return the ones whose destination 404s (or errors).
 *
 * @return list<string>
 */
function deadMovedPages(string $host): array
{
    $dead = [];

    foreach (movedPages() as [$from, $to]) {
        $legacy = test()->get('https://'.$host.$from);

        if ($legacy->status() === 404) {
            // Not served on this host — which is right exactly when its destination isn't.
            continue;
        }

        if ($legacy->status() !== 301) {
            $dead[] = "{$host}{$from} → {$legacy->status()} (expected a 301 or a 404)";

            continue;
        }

        $location = (string) $legacy->headers->get('Location');
        $target = test()->get($location);

        if ($target->status() === 404 || $target->status() >= 500) {
            $dead[] = "{$host}{$from} → {$location} → {$target->status()}";
        }
    }

    return $dead;
}

beforeEach(function (): void {
    installedDeployment();
});

it('finds the moved pages it follows', function (): void {
    $from = array_map(static fn (array $pair): string => $pair[0], movedPages());

    expect($from)->toContain('/admin/applications')
        ->toContain('/clients')
        ->toContain('/platform/customers');
});

it('lands every moved page on a page that exists, on the platform root', function (): void {
    multiTenantDeployment();
    config()->set('cbox-id.environments.base_domains', ['cboxid.com']);
    platformRootEnvironment();

    expect(deadMovedPages('cboxid.com'))->toBe([]);

    // The console's own old addresses still redirect on the root, where its pages are.
    $this->get('https://cboxid.com/clients')->assertStatus(301)->assertRedirect('/apps');

    // …and so do the environment console's, now that the root environment has one.
    $this->get('https://cboxid.com/admin/applications')->assertStatus(301)->assertRedirect('/admin/apps');
});

it('lands every moved page on a page that exists, on an environment\'s host', function (): void {
    multiTenantDeployment();
    config()->set('cbox-id.environments.base_domains', ['cboxid.com']);
    platformRootEnvironment();

    Environment::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'type' => EnvironmentType::Production,
        'status' => EnvironmentStatus::Active,
        'is_default' => false,
        'settings' => [],
    ]);

    expect(deadMovedPages('acme.cboxid.com'))->toBe([]);

    // Not vacuous: here, where the environment console lives, the old address does redirect.
    $this->get('https://acme.cboxid.com/admin/applications')->assertStatus(301)->assertRedirect('/admin/apps');
});

it('lands every moved page on a page that exists, on a single-tenant install', function (): void {
    platformRootEnvironment();

    expect(deadMovedPages((string) parse_url((string) config('app.url'), PHP_URL_HOST)))->toBe([]);
});

it('does not serve the environment console\'s old addresses where there is no environment console', function (): void {
    multiTenantDeployment();
    config()->set('cbox-id.environments.base_domains', ['cboxid.com']);
    platformRootEnvironment();

    // A name under the base domain that is no environment's resolves to the root's context
    // — and is not the root's host, so it serves no environment console, old or new.
    $this->get('https://nope.cboxid.com/admin/applications')->assertNotFound();
    $this->get('https://nope.cboxid.com/admin/applications/01JMOVEDPAGEPROBE0000000000')->assertNotFound();
    $this->get('https://nope.cboxid.com/admin/apps')->assertNotFound();
});

it('does not serve the environment console\'s old addresses on a single-tenant install', function (): void {
    platformRootEnvironment();
    $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

    $this->get('https://'.$host.'/admin/applications')->assertNotFound();
    $this->get('https://'.$host.'/admin/apps')->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| PLATFORM ADMIN MOVED INTO `/admin` — every old address, deep links included
|--------------------------------------------------------------------------
|
| `/platform/…` was a console of its own; it is the Platform area of `/admin` now. Operators
| have these in bookmarks, runbooks and the replies support sent, and an email can carry a
| workspace's or an organization's page by its id with a query string on the end. Each one
| must land on the page itself, in one hop, with its query intact — on the root's host of a
| multi-tenant deployment and on a single-tenant install, the two places platform admin is.
*/

/**
 * Every old platform-admin address, by the id it carries and with a query where one makes
 * sense, => the path and query it must land on.
 *
 * @return array<string, string>
 */
function platformMoves(string $workspace, string $organization): array
{
    $job = '9b2f7c1e-4d5a-4c3b-8e6f-0a1b2c3d4e5f';

    return [
        '/operator' => '/admin/platform',
        '/platform' => '/admin/platform/environments',
        // The query is kept whole — normalised to the order PHP sorts it in, as every
        // moved page's is.
        '/platform?q=prod&page=2' => '/admin/platform/environments?page=2&q=prod',
        '/platform/workspaces' => '/admin/platform/workspaces',
        '/platform/workspaces?q=acme' => '/admin/platform/workspaces?q=acme',
        '/platform/workspaces/'.$workspace => '/admin/platform/workspaces/'.$workspace,
        '/platform/workspaces/'.$workspace.'?utm_source=email' => '/admin/platform/workspaces/'.$workspace.'?utm_source=email',
        '/platform/customers' => '/admin/platform/workspaces',
        '/platform/customers/'.$workspace.'?ref=ticket-4271' => '/admin/platform/workspaces/'.$workspace.'?ref=ticket-4271',
        '/platform/organizations' => '/admin/platform/organizations',
        '/platform/organizations?q=acme' => '/admin/platform/organizations?q=acme',
        '/platform/organizations/'.$organization => '/admin/platform/organizations/'.$organization,
        '/platform/operators' => '/admin/platform/operators',
        '/platform/usage' => '/admin/platform/usage',
        '/platform/queues' => '/admin/platform/queues',
        '/platform/queues/monitor' => '/admin/platform/queues/monitor',
        '/platform/queues/monitor/job/'.$job => '/admin/platform/queues/monitor/job/'.$job,
        '/platform/search?q=acme' => '/admin/platform/search?q=acme',
        '/platform/search/jump/'.$organization => '/admin/platform/search/jump/'.$organization,
    ];
}

/**
 * Follow every old platform address on $host as the signed-in operator: one 301 to exactly
 * the new address, which then answers them. Returns what did not.
 *
 * @param  array<string, string>  $moves
 * @return list<string>
 */
function strandedPlatformMoves(string $host, array $moves): array
{
    $stranded = [];

    foreach ($moves as $from => $to) {
        $legacy = test()->get('https://'.$host.$from);
        $location = (string) $legacy->headers->get('Location');
        $landed = (string) parse_url($location, PHP_URL_PATH).(($query = parse_url($location, PHP_URL_QUERY)) ? '?'.$query : '');

        if ($legacy->status() !== 301 || $landed !== $to) {
            $stranded[] = "{$from} → {$legacy->status()} {$location} (expected 301 → {$to})";

            continue;
        }

        // The monitor's job page answers for a job that exists; this one does not.
        if (str_contains($to, '/monitor/job/')) {
            continue;
        }

        $status = test()->get($location)->status();

        if (! in_array($status, [200, 302], true)) {
            $stranded[] = "{$from} → {$to} → {$status}";
        }
    }

    return $stranded;
}

it('names every platform-admin page in the moved-pages table', function (): void {
    $moved = collect(movedPages())->pluck(1)->map(static fn (string $to): string => (string) preg_replace('/01JMOVEDPAGEPROBE0000000000/', '{}', $to));

    $pages = collect(app('router')->getRoutes()->getRoutes())
        ->filter(static fn (Route $route): bool => str_starts_with((string) $route->getName(), 'platform.')
            && in_array('GET', $route->methods(), true)
            && str_starts_with($route->uri(), 'admin/platform/'))
        ->map(static fn (Route $route): string => '/'.preg_replace('/\{[^}]+\}/', '{}', $route->uri()));

    expect($pages->count())->toBeGreaterThanOrEqual(9)
        ->and($pages->diff($moved)->values()->all())->toBe([], 'a platform-admin page with no old address pointing at it');
});

it('lands every old platform-admin address on its page, on the platform root', function (): void {
    multiTenantDeployment();
    config()->set('cbox-id.environments.base_domains', ['cboxid.com']);
    ['organization' => $workspace] = provisionAccount('owner@acme.example');
    actAsOperator('ops@platform.test');
    [$organization] = impersonationMember();

    expect(strandedPlatformMoves('cboxid.com', platformMoves($workspace->id, $organization->id)))->toBe([]);
})->group('security');

it('lands every old platform-admin address on its page, on a single-tenant install', function (): void {
    actAsOperator('ops@platform.test');
    [$organization] = impersonationMember();
    $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

    // A single-tenant install has no workspaces of its own; an organization stands in for
    // the id the old address carried.
    expect(strandedPlatformMoves($host, platformMoves($organization->id, $organization->id)))->toBe([]);
})->group('security');

it('serves no old platform-admin address on a host that has no platform admin', function (): void {
    multiTenantDeployment();
    config()->set('cbox-id.environments.base_domains', ['cboxid.com']);
    platformRootEnvironment();

    Environment::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'type' => EnvironmentType::Production,
        'status' => EnvironmentStatus::Active,
        'is_default' => false,
        'settings' => [],
    ]);

    foreach (array_keys(platformMoves('01JMOVEDPAGEPROBE0000000000', '01JMOVEDPAGEPROBE0000000000')) as $from) {
        $this->get('https://acme.cboxid.com'.$from)->assertNotFound();
    }
})->group('security');
