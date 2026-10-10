<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Platform\InstallationOrganization;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Testing\TestResponse;
use Tests\Support\ConsoleCrawl;
use Tests\Support\ProductionShape;
use Tests\TestCase;

/**
 * FINDING YOUR WAY — every task a new environment administrator comes to the console to do
 * is a few clicks from wherever they start, by the links the console actually draws.
 *
 * The crawl beside this file proves every link works. It does not prove anybody can FIND
 * the page a task needs: a page three areas away from where a person looks, reachable only
 * by a URL in the docs, passes the crawl. So this walks the console the way a person does —
 * breadth-first from the environment's home, counting clicks — and holds each task's
 * destination to a click budget. The tasks are the ones docs/getting-started/finding-your-way.md
 * walks through, in the same order.
 *
 * WHAT COUNTS AS ONE CLICK, so the budget is honest:
 *
 *  - an AREA on the rail (it lands on the area's first page);
 *  - a PAGE in the sub-nav, but only the active area's — the sub-nav shows one area at a
 *    time, so a page in another area costs the area click first;
 *  - any link the page itself offers: a row, a button, a tab, a breadcrumb, a card.
 *
 * ⌘K is deliberately NOT a click here. It reaches every page in one keystroke, which would
 * make every budget pass; the point is that the console is findable without it.
 *
 * The budgets are what the console takes today, so a change that buries a task fails here
 * and has to say why.
 *
 * Links are read from the Inertia props, as the crawl reads them — every href a page draws
 * is resolved by the server and handed over in its props — and only GET pages of the
 * environment console are followed.
 */
afterEach(fn () => ProductionShape::reset());

/** route name => [the most clicks it may take, what the task is]. */
const FINDING_YOUR_WAY = [
    // a. Social login for the environment.
    'environment.social-providers' => [2, 'Turn on Google or GitHub login'],
    // b. Enterprise SSO for a customer organization, its domain, and an Admin Portal link
    //    (the button is on every page of the organization's hub).
    'environment.connections' => [2, 'See every Enterprise SSO connection'],
    'environment.connections.create' => [2, 'Create an SSO connection'],
    'environment.organizations.sso' => [3, 'Open one organization\'s SSO'],
    'environment.organizations.show' => [2, 'Open one organization (and its Admin Portal link)'],
    'environment.domains' => [2, 'See every claimed domain'],
    'environment.organizations.domains' => [3, 'Verify an organization\'s domain'],
    // c. Directory Sync, SCIM or an HR system.
    'environment.directories' => [2, 'See every directory'],
    'environment.directories.create' => [2, 'Connect SCIM or an HR system'],
    // d. A user: their organizations, sessions, MFA — and why a sign-in was challenged.
    'environment.users.show' => [2, 'Open a user'],
    'environment.radar' => [2, 'See Radar\'s sign-in decisions'],
    // e. Organizations, invitations, a member's role.
    'environment.organizations.create' => [2, 'Create an organization'],
    'environment.organizations.members' => [3, 'Change a member\'s role'],
    'environment.organizations.invitations' => [3, 'Invite a member'],
    // f. Roles, permissions, and what they are not.
    'environment.roles.create' => [2, 'Create a custom role'],
    'environment.permissions' => [2, 'Write a permission'],
    'environment.fga' => [2, 'Model fine-grained authorization'],
    'environment.feature-flags' => [2, 'Switch a feature flag'],
    // g. An app, its redirect URIs and keys, and an AI agent over MCP.
    'environment.clients.create' => [2, 'Register an app'],
    'environment.clients.settings' => [3, 'Change an app\'s redirect URIs'],
    'environment.keys.frontend' => [2, 'Get API keys'],
    'environment.agent-connect' => [2, 'Connect an AI agent over MCP'],
    // h. Where every sign-in method is configured.
    'environment.sign-in-methods' => [1, 'See every sign-in method'],
    'environment.auth-policy' => [2, 'Change the password and MFA policy'],
    // i. The sign-in page's look.
    'environment.branding' => [1, 'Brand the sign-in page'],
];

/**
 * Tasks whose destination is one STATE of a page rather than the page — the setup form a
 * link on it opens — by path and query, with the same budget rule.
 *
 * "Turn on Google for my app" is the most common thing a new administrator comes to do:
 * Authentication (1) › Social login (2) › Google (3), and the form that opens offers the
 * whole environment without being asked — which the test below holds as well.
 */
const FINDING_YOUR_WAY_STATES = [
    '/admin/social-sign-in?provider=google' => [3, 'Turn on Google for the environment'],
];

it('puts every task an environment administrator comes for a few clicks from home', function (): void {
    $world = ConsoleCrawl::world();
    $environment = $world->environment;
    expect($environment)->not->toBeNull();

    actAsEnvironmentAdmin($world->ownerId, $environment->id);
    ConsoleCrawl::productionShape();

    $origin = 'https://'.$environment->slug.'.'.ConsoleCrawl::ROOT;
    $version = (string) app(HandleInertiaRequests::class)->version(request());
    $urls = [];
    $depths = findingYourWayWalk($this, $origin.'/admin', $version, maxDepth: 5, urls: $urls);

    // `FINDING_YOUR_WAY_LOG=1` prints how many clicks every page took.
    if (getenv('FINDING_YOUR_WAY_LOG') !== false) {
        ksort($depths);
        fwrite(STDERR, (string) json_encode($depths, JSON_PRETTY_PRINT));
    }

    $over = [];

    foreach (FINDING_YOUR_WAY as $route => [$budget, $task]) {
        $took = $depths[$route] ?? null;

        if ($took === null || $took > $budget) {
            $over[] = sprintf('%s (%s): %s clicks, budget %d', $task, $route, $took === null ? 'not reachable in 5' : (string) $took, $budget);
        }
    }

    foreach (FINDING_YOUR_WAY_STATES as $path => [$budget, $task]) {
        $took = $urls[$origin.$path] ?? null;

        if ($took === null || $took > $budget) {
            $over[] = sprintf('%s (%s): %s clicks, budget %d', $task, $path, $took === null ? 'not reachable in 5' : (string) $took, $budget);
        }
    }

    expect($over)->toBe([], "Tasks a new administrator cannot find in their budget:\n".implode("\n", $over));

    // …and where "Turn on Google" lands is a form for the WHOLE environment, with the real
    // redirect URI to copy — not a form that first asks which organization.
    $google = findingYourWayPage($this, $origin.'/admin/social-sign-in?provider=google', $version);

    expect($google['props']['template']['key'] ?? null)->toBe('google')
        ->and($google['props']['organization']['allowsEnvironment'] ?? null)->toBeTrue()
        ->and(array_key_exists('selected', $google['props']['organization']) && $google['props']['organization']['selected'] === null)->toBeTrue()
        ->and($google['props']['template']['redirectUri'] ?? '')->toEndWith('/sso/oidc/'.($google['props']['template']['reservedId'] ?? '?').'/callback');
});

/**
 * THE SAME TASKS ON A SINGLE-TENANT INSTALL, whose whole administration is the organization
 * console: there is no environment console to send anybody to, so the environment's own
 * sign-in settings are changed here — and "turn on Google" lands on a form for every sign-in
 * page, exactly as on the environment console.
 */
const FINDING_YOUR_WAY_SINGLE_TENANT = [
    'sign-in-methods' => [2, 'See every sign-in method'],
    'auth-policy' => [2, 'Turn passkeys or magic links off, or shorten sessions'],
    'social-providers' => [2, 'Manage social login'],
];

it('puts the environment\'s sign-in settings a few clicks from home on a single-tenant install', function (): void {
    installedDeployment();
    [, $home] = actingAsRole(MembershipRole::Owner);
    // The install's own organization, whose owners administer its environment.
    app(InstallationOrganization::class)->set($home->id);

    $origin = rtrim(url('/'), '/');
    $version = (string) app(HandleInertiaRequests::class)->version(request());
    $urls = [];
    $depths = findingYourWayWalk($this, $origin.'/dashboard', $version, maxDepth: 4, urls: $urls, environmentConsole: false);

    $over = [];

    foreach (FINDING_YOUR_WAY_SINGLE_TENANT as $route => [$budget, $task]) {
        $took = $depths[$route] ?? null;

        if ($took === null || $took > $budget) {
            $over[] = sprintf('%s (%s): %s clicks, budget %d', $task, $route, $took === null ? 'not reachable in 4' : (string) $took, $budget);
        }
    }

    $google = $urls[$origin.'/social-sign-in?provider=google'] ?? null;

    if ($google === null || $google > 3) {
        $over[] = 'Turn on Google for the environment (/social-sign-in?provider=google): '.($google ?? 'not reachable in 4').' clicks, budget 3';
    }

    expect($over)->toBe([], "Tasks a single-tenant administrator cannot find in their budget:\n".implode("\n", $over));

    // The form offers every sign-in page first, and the policy page draws the environment's panels.
    $form = findingYourWayPage($this, $origin.'/social-sign-in?provider=google', $version);
    $policy = findingYourWayPage($this, $origin.'/sign-in-rules', $version);

    expect($form['props']['ownerChoice'] ?? null)->toBeTrue()
        ->and($policy['props']['signInMethods'] ?? null)->not->toBeNull()
        ->and($policy['props']['smsFactor'] ?? null)->not->toBeNull();
});

/**
 * Breadth-first from $start, one level per click; returns route name => the fewest clicks
 * that reached it, and fills $urls with every URL => the fewest clicks that reached IT.
 *
 * @param  array<string, int>  $urls
 * @return array<string, int>
 */
function findingYourWayWalk(TestCase $test, string $start, string $version, int $maxDepth, array &$urls = [], bool $environmentConsole = true): array
{
    $depths = [];
    $seen = [$start => true];
    $level = [$start];
    $visitsPerRoute = [];

    for ($depth = 0; $depth <= $maxDepth && $level !== []; $depth++) {
        $next = [];

        foreach ($level as $url) {
            $route = findingYourWayRoute($url, $environmentConsole);

            if ($route === null) {
                continue;
            }

            $name = (string) $route->getName();
            $depths[$name] ??= $depth;
            $urls[$url] ??= $depth;

            // The second Users detail page proves what the first did.
            $visitsPerRoute[$name] = ($visitsPerRoute[$name] ?? 0) + 1;

            if ($depth === $maxDepth || $visitsPerRoute[$name] > 2) {
                continue;
            }

            $page = findingYourWayPage($test, $url, $version);

            if ($page === null) {
                continue;
            }

            foreach (findingYourWayClicks($page, $start) as $link) {
                if (! isset($seen[$link])) {
                    $seen[$link] = true;
                    $next[] = $link;
                }
            }
        }

        $level = $next;
    }

    return $depths;
}

/** The console GET page a URL is — the environment console's, or the organization console's — or null. */
function findingYourWayRoute(string $url, bool $environmentConsole = true): ?Route
{
    try {
        $route = app(Router::class)->getRoutes()->match(Request::create($url, 'GET'));
    } catch (Throwable) {
        return null;
    }

    $name = (string) $route->getName();

    $ours = $environmentConsole
        ? str_starts_with($name, 'environment.')
        : $name !== '' && ! str_starts_with($name, 'environment.') && ! str_starts_with($name, 'platform.');

    return $ours
        && preg_match('/(impersonat|handoff|switch|acting-organization|lookup|search|sudo|\.jump$)/', $name) !== 1
        ? $route
        : null;
}

/** @return array<string, mixed>|null */
function findingYourWayPage(TestCase $test, string $url, string $version): ?array
{
    app()->forgetScopedInstances();
    app(Router::class)->getCurrentRoute()?->flushController();

    /** @var TestResponse $response */
    $response = $test->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
        'X-Requested-With' => 'XMLHttpRequest',
    ])->get($url);

    $test->withoutHeader('X-Inertia')->withoutHeader('X-Inertia-Version')->withoutHeader('X-Requested-With');

    if ($response->getStatusCode() !== 200 || $response->headers->get('X-Inertia') !== 'true') {
        return null;
    }

    $page = json_decode((string) $response->getContent(), true);

    return is_array($page) ? $page : null;
}

/**
 * What a person can click on this page: the rail's areas, the ACTIVE area's sub-nav, and
 * every link the page's own props offer.
 *
 * @param  array<string, mixed>  $page
 * @return list<string>
 */
function findingYourWayClicks(array $page, string $base): array
{
    $props = is_array($page['props'] ?? null) ? $page['props'] : [];
    $links = [];

    /** @var array{areas?: list<array{href: string, active: bool, pages: list<array{href: string}>}>} $shell */
    $shell = is_array($props['shell'] ?? null) ? $props['shell'] : [];

    foreach ($shell['areas'] ?? [] as $area) {
        $links[] = $area['href'];

        if ($area['active']) {
            foreach ($area['pages'] as $navPage) {
                $links[] = $navPage['href'];
            }
        }
    }

    unset($props['shell'], $props['i18n'], $props['errors'], $props['flash'], $props['apiEquivalents'], $props['help']);

    array_walk_recursive($props, function (mixed $value) use (&$links): void {
        if (is_string($value) && preg_match('#^(https?://|/)[^\s]*$#', $value) === 1 && ! str_starts_with($value, '//')) {
            $links[] = $value;
        }
    });

    $origin = (string) parse_url($base, PHP_URL_SCHEME).'://'.(string) parse_url($base, PHP_URL_HOST);

    return array_values(array_unique(array_filter(array_map(
        static function (string $link) use ($origin): ?string {
            $absolute = str_starts_with($link, '/') ? $origin.$link : $link;

            return str_starts_with($absolute, $origin.'/') ? (strtok($absolute, '#') ?: $absolute) : null;
        },
        $links,
    ))));
}
