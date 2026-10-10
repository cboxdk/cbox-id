<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
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
    'environment.appearance' => [1, 'Brand the sign-in page'],
];

it('puts every task an environment administrator comes for a few clicks from home', function (): void {
    $world = ConsoleCrawl::world();
    $environment = $world->environment;
    expect($environment)->not->toBeNull();

    actAsEnvironmentAdmin($world->ownerId, $environment->id);
    ConsoleCrawl::productionShape();

    $origin = 'https://'.$environment->slug.'.'.ConsoleCrawl::ROOT;
    $version = (string) app(HandleInertiaRequests::class)->version(request());
    $depths = findingYourWayWalk($this, $origin.'/admin', $version, maxDepth: 5);

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

    expect($over)->toBe([], "Tasks a new administrator cannot find in their budget:\n".implode("\n", $over));
});

/**
 * Breadth-first from $start, one level per click; returns route name => the fewest clicks
 * that reached it.
 *
 * @return array<string, int>
 */
function findingYourWayWalk(TestCase $test, string $start, string $version, int $maxDepth): array
{
    $depths = [];
    $seen = [$start => true];
    $level = [$start];
    $visitsPerRoute = [];

    for ($depth = 0; $depth <= $maxDepth && $level !== []; $depth++) {
        $next = [];

        foreach ($level as $url) {
            $route = findingYourWayRoute($url);

            if ($route === null) {
                continue;
            }

            $name = (string) $route->getName();
            $depths[$name] ??= $depth;

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

/** The environment-console GET page a URL is, or null for anything else. */
function findingYourWayRoute(string $url): ?Route
{
    try {
        $route = app(Router::class)->getRoutes()->match(Request::create($url, 'GET'));
    } catch (Throwable) {
        return null;
    }

    $name = (string) $route->getName();

    return str_starts_with($name, 'environment.')
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
