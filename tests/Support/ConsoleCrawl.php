<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Middleware\HandleInertiaRequests;
use Cbox\Id\Organization\Models\Environment;
use Closure;
use Database\Seeders\DemoEnvironmentSeeder;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;
use Throwable;

/**
 * THE PRODUCTION CRAWL, kept: every page a signed-in person can reach, and every link
 * those pages offer, requested the way production serves them.
 *
 * Built after a crawl of a production-shaped stack (two web replicas behind a
 * TLS-terminating proxy, PostgreSQL, Valkey, routes cached, hosts enforced) found a 400 on
 * every environment-console page, dead links to step-up-gated pages, module cards linking
 * to pages that were switched off and an Integration panel pointing at a discovery
 * document the apex refuses — all on a suite that was green. Each of those has its own
 * regression test; this is the net for the next one.
 *
 * WHAT IT DOES, per persona:
 *
 *  1. NAVIGATES to every GET route of the persona's console that it can name a URL for —
 *     parameters resolved from the seeded {@see DemoEnvironmentSeeder} world — as a plain
 *     browser request (a bookmark, a reload, a handoff landing), and fails on a 5xx, a 400,
 *     a 405 or a 419. A 403 or a 404 there may be the persona's authority speaking, so it
 *     is not a failure on its own.
 *  2. Collects every same-site URL the returned Inertia props offer — the rail, buttons,
 *     row links, tabs — and follows each as an Inertia visit (what a click does), failing
 *     on all of the above and on a 403 or a 404: a page must not offer what it refuses.
 *  3. Repeats 2 on the pages those links land on, a few levels deep.
 *
 * `CONSOLE_CRAWL_LOG=1` prints every request and its answer to stderr.
 *
 * UNDER {@see ProductionShape}: routes cached and the trusted-host list enforced, on a
 * root host and an environment host under one base domain, as cboxid.com runs.
 *
 * NOT A LINK: an action URL a form posts to (it matches a non-GET route more specifically
 * than any page), a placeholder template, an asset, a sign-out, and anything a page offers
 * that is not a web page — a protocol endpoint, a download — which is only held to "no
 * 5xx". Each exclusion is a rule below with its reason, never a list of URLs.
 */
final class ConsoleCrawl
{
    public const string ROOT = 'cboxid.test';

    /** How deep links are followed from the pages the route pass rendered. */
    private const int DEPTH = 3;

    /** Visits of one route by link, whatever its parameters — the crawl's time bound. */
    private const int PER_ROUTE = 2;

    /** Top-level props that are not links a page offers. */
    private const array SKIP_PROPS = ['i18n', 'errors', 'flash', 'apiEquivalents', 'help'];

    /** GET routes that change what the session points at, or leave it — never crawled. */
    private const string STATEFUL = '/(logout|sign-?out|impersonat|handoff|\.jump$|^environment\.open$|callback|\.redirect$|acting-organization|switch)/i';

    /** @var list<string> */
    public array $failures = [];

    /** @var array<string, int> */
    private array $visitsPerRoute = [];

    /** @var array<string, true> */
    private array $seen = [];

    /** Route matches by method and URL: the rail is the same sixty links on every page. @var array<string, Route|null> */
    private array $matches = [];

    /** @var array<string, bool> */
    private array $linkVerdicts = [];

    private string $version = '';

    public int $requests = 0;

    /** Every Inertia component a 200 rendered, so a test can say which pages it reached. @var array<string, true> */
    public array $components = [];

    public function __construct(private readonly TestCase $test) {}

    /**
     * The demo world on the SaaS shape, seeded cheaply: Argon2id at its production cost
     * hashes each of the seeder's dozen passwords for half a second, which is the whole
     * budget of this test, and the hash parameters are not what is under test.
     */
    public static function world(): DemoEnvironmentSeeder
    {
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        Hash::forgetDrivers();

        multiTenantDeployment(self::ROOT);
        config(['cbox-id.environments.base_domains' => [self::ROOT]]);

        $seeder = new DemoEnvironmentSeeder;
        $seeder->run();

        return $seeder;
    }

    /**
     * A URL value for every route parameter the seeded world can name, in `$environment`.
     * A parameter with nothing behind it is null, and its routes are left to the links.
     *
     * @return array<string, string|null>
     */
    public static function parameters(DemoEnvironmentSeeder $world, Environment $environment): array
    {
        $first = static fn (string $table, string $column = 'id'): ?string => self::value(
            DB::table($table)->where('environment_id', $environment->id)->orderBy($column)->value($column),
        );

        return [
            'client' => $first('oauth_clients'),
            'organization' => $first('organizations'),
            'connection' => $first('connections'),
            'directory' => $first('directories'),
            'role' => $first('roles'),
            'user' => $first('users'),
            'project' => $world->project?->id,
            'environment' => $environment->id,
            'campaign' => $first('governance_campaigns'),
            'policy' => $first('governance_sod_policies'),
            'hook' => $first('external_action_endpoints'),
            'webhook' => $first('webhook_endpoints'),
            'stream' => $first('log_streams'),
            'secret' => $first('vault_secrets'),
            'sync' => $first('provisioning_connections'),
            'api' => $first('oauth_apis'),
            'provider' => $first('saml_service_providers'),
            'action' => self::value(DB::table('app_audit_schemas')->where('environment_id', $environment->id)->value('action')),
        ];
    }

    private static function value(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * Whether a route sits behind a middleware class — resolved, so an alias, a group and a
     * parameterised entry all answer the same. `Authenticate` with a parameter (`:optional`)
     * is not a guard and does not count.
     */
    public static function guardedBy(Route $route, string $middleware): bool
    {
        return in_array($middleware, app(Router::class)->gatherRouteMiddleware($route), true);
    }

    /** Routes cached and hosts enforced, once the world (and its custom domains) exists. */
    public static function productionShape(): void
    {
        ProductionShape::cacheRoutes();
        ProductionShape::enforceTrustedHosts();
    }

    /**
     * The persona's console: every GET route `$select` accepts, on `$host`, with what
     * `$parameters` can name for it. Routes it cannot name a URL for are not guessed.
     *
     * @param  Closure(Route): bool  $select
     * @param  array<string, string|null>  $parameters
     */
    public function crawl(string $host, Closure $select, array $parameters, array $starts = []): self
    {
        $this->version = (string) app(HandleInertiaRequests::class)->version(request());

        // [url, offered by, as, depth]. Every entry is a CLICK; the route pass below
        // navigates directly and only seeds this queue with what its pages offer.
        $clicks = [];

        foreach ($starts as $start) {
            $clicks[] = [$start, 'the test', 'start', 0];
        }

        // 1. DIRECT NAVIGATION — a bookmark, a reload, a handoff landing: a plain GET of
        //    every page the persona's console names. 403 and 404 may be the persona's
        //    authority, so only an answer no page should ever give is a failure.
        foreach ($this->routes($select) as $route) {
            $url = $this->urlFor($route, $host, $parameters);

            if ($url === null) {
                continue;
            }

            foreach ($this->visit($url, 'route '.$route->getName(), 'route', inertia: false, strict: false) as $link => $key) {
                $clicks[] = [$link, $url, 'prop:'.$key, 1];
            }
        }

        // 2. CLICKS — every link those pages offer, as an Inertia visit, held to "a page
        //    does not offer what it refuses"; and the links of what they land on.
        while ($clicks !== []) {
            [$url, $from, $via, $depth] = array_shift($clicks);

            if (isset($this->seen[$url])) {
                continue;
            }

            $this->seen[$url] = true;

            foreach ($this->visit($url, $from, $via, inertia: true, strict: true) as $link => $key) {
                if ($depth < self::DEPTH && ! isset($this->seen[$link])) {
                    $clicks[] = [$link, $url, 'prop:'.$key, $depth + 1];
                }
            }
        }

        return $this;
    }

    /**
     * Request one URL — as a click does (an Inertia visit) or as a direct navigation —
     * following redirects and the protocol's 409 the way the client does, and return the
     * links the final page offers.
     *
     * @return array<string, string> link => the prop key it was found under
     */
    private function visit(string $url, string $from, string $via, bool $inertia, bool $strict): array
    {
        $route = $this->routeFor($url);

        // A clicked route is visited a bounded number of times, whatever the parameters:
        // the second Users detail page proves what the first did.
        if ($strict && $route !== null) {
            $name = (string) ($route->getName() ?? $route->uri());
            $this->visitsPerRoute[$name] = ($this->visitsPerRoute[$name] ?? 0) + 1;

            if ($this->visitsPerRoute[$name] > self::PER_ROUTE) {
                return [];
            }
        }

        $current = $url;
        $response = null;

        for ($hop = 0; $hop < 6; $hop++) {
            $response = $this->request($current, $inertia);
            $status = $response->getStatusCode();

            if ($status === 409 && $response->headers->has('X-Inertia-Location')) {
                // The client's cue to leave the SPA: a full navigation from here on.
                $current = $this->absolute((string) $response->headers->get('X-Inertia-Location'), $current);
                $inertia = false;

                if (! $this->sameSite($current)) {
                    return [];
                }

                continue;
            }

            if ($status >= 300 && $status < 400 && $response->headers->has('Location')) {
                $current = $this->absolute((string) $response->headers->get('Location'), $current);

                if (! $this->sameSite($current)) {
                    return [];
                }

                continue;
            }

            break;
        }

        if ($response === null) {
            return [];
        }

        $status = $response->getStatusCode();
        $page = $this->isPage($url, $route);

        if (getenv('CONSOLE_CRAWL_LOG') !== false) {
            fwrite(STDERR, sprintf("%s %d %s%s\n", $inertia ? 'click' : 'nav  ', $status, $url, $current === $url ? '' : ' -> '.$current));
        }

        // Never right for a page, whoever asks: a server error, a malformed-request
        // refusal (the production 400 was one), a wrong verb, an expired form token.
        $broken = $status >= 500 || ($page && in_array($status, [400, 405, 419], true));

        // …and for a link a page OFFERED, a refusal is a dead link.
        $dead = $strict && $page && in_array($status, [403, 404, 429], true);

        if ($broken || $dead) {
            $landed = $current === $url ? '' : " (landed on {$current})";
            $this->failures[] = "{$status} {$url}{$landed} — offered by {$from} as {$via}";

            return [];
        }

        if ($status !== 200) {
            return [];
        }

        $object = $this->pageObject($response, $inertia);

        if (is_string($object['component'] ?? null)) {
            $this->components[$object['component']] = true;
        }

        return $this->links($object, $current);
    }

    private function request(string $url, bool $inertia): TestResponse
    {
        $this->requests++;

        // A real deployment drops scoped instances between requests; the test client does
        // not, and ConsoleScope memoises authority (see nextRequest() in tests/Pest.php).
        // Nor does it drop the CONTROLLER a route holds on to, which keeps the previous
        // request's scope through its constructor — so a second organization page in one
        // test was answered with the first one's (released) binding: a 404 no PHP-FPM
        // request can produce.
        app()->forgetScopedInstances();
        app(Router::class)->getCurrentRoute()?->flushController();

        $headers = $inertia
            ? ['X-Inertia' => 'true', 'X-Inertia-Version' => $this->version, 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html, application/xhtml+xml']
            : [];

        try {
            return $this->test->withHeaders($headers)->get($url);
        } finally {

            foreach (array_keys($headers) as $header) {
                $this->test->withoutHeader($header);
            }
        }
    }

    /**
     * The page object a response carries — the JSON body of an Inertia visit, or the one
     * embedded in a full HTML document.
     *
     * @return array<string, mixed>|null
     */
    private function pageObject(TestResponse $response, bool $inertia): ?array
    {
        if ($inertia && $response->headers->get('X-Inertia') === 'true') {
            $page = json_decode((string) $response->getContent(), true);

            return is_array($page) ? $page : null;
        }

        $html = (string) $response->getContent();

        if (preg_match('#<script data-page="app" type="application/json">(.*?)</script>#s', $html, $match) !== 1) {
            return null;
        }

        $page = json_decode(html_entity_decode($match[1]), true);

        return is_array($page) ? $page : null;
    }

    /**
     * Every same-site URL the page's props offer, by the prop key it was found under.
     *
     * @param  array<string, mixed>|null  $page
     * @return array<string, string>
     */
    private function links(?array $page, string $base): array
    {
        $props = is_array($page['props'] ?? null) ? $page['props'] : [];

        foreach (self::SKIP_PROPS as $key) {
            unset($props[$key]);
        }

        $found = [];

        array_walk_recursive($props, function (mixed $value, int|string $key) use (&$found, $base): void {
            if (! is_string($value) || $value === '' || preg_match('#\s#', $value) === 1) {
                return;
            }

            if (! str_starts_with($value, '/') && ! str_starts_with($value, 'https://') && ! str_starts_with($value, 'http://')) {
                return;
            }

            if (str_starts_with($value, '//')) {
                return;
            }

            $url = $this->absolute($value, $base);

            if ($this->isLink($url)) {
                $found[strtok($url, '#') ?: $url] = (string) $key;
            }
        });

        return $found;
    }

    private function isLink(string $url): bool
    {
        return $this->linkVerdicts[$url] ??= $this->decideLink($url);
    }

    private function decideLink(string $url): bool
    {
        if (! $this->sameSite($url)) {
            return false;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        // A template a page fills in itself (`/admin/organizations/__organization__/…`),
        // an asset, a placeholder.
        if (str_contains($url, '__') || str_contains($url, '{') || str_contains($url, '%7B')
            || preg_match('#^/(build|storage|brand-assets)/#', $path) === 1) {
            return false;
        }

        $route = $this->routeFor($url);

        if ($route !== null && preg_match(self::STATEFUL, (string) $route->getName()) === 1) {
            return false;
        }

        return ! $this->isAction($url, $route);
    }

    /**
     * Whether a URL is somewhere a form POSTS rather than a page — it matches a non-GET
     * route at least as specifically as any GET route. `/single-sign-on/import` is the
     * case: a POST action, also matched by the GET `/single-sign-on/{connection}` as a
     * connection called "import".
     */
    private function isAction(string $url, ?Route $get): bool
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $other = $this->match($url, $method);

            if ($other === null || $other->methods() === ['GET', 'HEAD']) {
                continue;
            }

            if ($get === null || substr_count($other->uri(), '{') < substr_count($get->uri(), '{')) {
                return true;
            }
        }

        return false;
    }

    /**
     * A page this application renders — a named web route — rather than a protocol
     * endpoint. Protocol URLs a page SHOWS (a SAML entity id, the SCIM base URL, the REST
     * base) are identifiers to copy, not links, and answer whatever their protocol says to
     * a bare GET; they are held to "no 5xx" only. A URL that matches nothing outside those
     * prefixes IS held to the page rule: a link to nowhere.
     */
    private function isPage(string $url, ?Route $route): bool
    {
        $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');

        if (preg_match('#^(\.well-known|oauth|sso|scim|api|mcp|frontend)(/|$)#', $path) === 1) {
            return false;
        }

        return $route === null
            || (in_array('web', $route->gatherMiddleware(), true) && $route->getName() !== null);
    }

    private function routeFor(string $url): ?Route
    {
        return $this->match($url, 'GET');
    }

    private function match(string $url, string $method): ?Route
    {
        $key = $method.' '.$url;

        if (! array_key_exists($key, $this->matches)) {
            try {
                $this->matches[$key] = app(Router::class)->getRoutes()->match(Request::create($url, $method));
            } catch (HttpExceptionInterface|SuspiciousOperationException|Throwable) {
                $this->matches[$key] = null;
            }
        }

        return $this->matches[$key];
    }

    /**
     * The persona's GET routes, in route-file order.
     *
     * @param  Closure(Route): bool  $select
     * @return list<Route>
     */
    private function routes(Closure $select): array
    {
        $routes = [];

        foreach (app(Router::class)->getRoutes()->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true) || $route->getName() === null) {
                continue;
            }

            if (preg_match(self::STATEFUL, (string) $route->getName()) === 1) {
                continue;
            }

            if ($select($route)) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    /** @param  array<string, string|null>  $parameters */
    private function urlFor(Route $route, string $host, array $parameters): ?string
    {
        $values = [];

        foreach ($route->parameterNames() as $name) {
            $value = $parameters[$name] ?? null;

            if ($value === null) {
                return null;
            }

            $values[$name] = $value;
        }

        $path = preg_replace_callback('#\{(\w+)\??\}#', static fn (array $m): string => rawurlencode($values[$m[1]] ?? ''), $route->uri());

        return 'https://'.$host.'/'.ltrim((string) $path, '/');
    }

    private function sameSite(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && ($host === self::ROOT || str_ends_with($host, '.'.self::ROOT));
    }

    private function absolute(string $url, string $base): string
    {
        if (preg_match('#^https?://#', $url) === 1) {
            return $url;
        }

        $origin = (string) parse_url($base, PHP_URL_SCHEME).'://'.(string) parse_url($base, PHP_URL_HOST);

        return $origin.'/'.ltrim($url, '/');
    }
}
