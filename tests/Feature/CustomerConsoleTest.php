<?php

declare(strict_types=1);

use App\Http\Middleware\EnforceCustomerConsole;
use App\Platform\Console\ConsoleScope;
use App\Platform\Console\CustomerConsole;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

uses(RefreshDatabase::class);

/**
 * A CUSTOMER'S OWN CONSOLE — the organization console on a customer's environment host.
 *
 * The person signed in there administers their company's organization inside somebody
 * else's product. They get an admin portal — Members, Enterprise SSO, Domains, Directory
 * Sync, Roles and the Audit log, and App audit logs where their plan has them — and their
 * own pages; the product's
 * administration — apps, webhooks, the vault, branding and the rest — belongs to the
 * environment console at `/admin`, and is not there rather than unlinked.
 *
 * Every page withheld here stays served wherever the organization console is the only
 * console: a single-tenant install, and the platform root. That is the half most worth
 * holding, because deleting the routes would have passed every assertion about a customer.
 */

/**
 * The SaaS shape, standing on a customer's environment: a vendor's product environment on
 * its own host, and one of the vendor's customers administering their organization in it.
 *
 * @return array{environmentId: string, vendorId: string, organizationId: string}
 */
function aCustomerAdministrator(MembershipRole $role = MembershipRole::Owner): array
{
    multiTenantDeployment();

    // The vendor: a workspace, and the product environment it runs.
    $vendor = provisionAccount('vendor@product.example');

    serveOnTestHost($vendor['environment']);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($vendor['environment']->id));

    // …and their customer's administrator, a subject OF that environment.
    [, $organization] = actingAsRole($role);

    return [
        'environmentId' => $vendor['environment']->id,
        'vendorId' => $vendor['subjectId'],
        'organizationId' => $organization->id,
    ];
}

/** Every module on, so a module page is absent for this console's reason and no other. */
function everyConsoleModuleOn(): void
{
    config([
        'id-analytics.enabled' => true,
        'compliance.enabled' => true,
        'connectors.enabled' => true,
        'id-devices.enabled' => true,
    ]);
}

/** The rail a request was drawn with, as area key => that area's page routes. */
function customerRail(string $route): array
{
    $shell = (array) test()->get(route($route))->assertOk()->inertiaProps('shell');

    return collect($shell['areas'])
        ->mapWithKeys(fn (array $area): array => [$area['key'] => array_column($area['pages'], 'route')])
        ->all();
}

/**
 * What the product's administration is, by route — the pages a customer's console withholds,
 * module pages included. Each is served by the environment console under `/admin`.
 *
 * @return array<string, array{0: string}>
 */
function productAdministration(): array
{
    return [
        'apps' => ['clients'],
        'register an app' => ['clients.create'],
        'webhooks' => ['webhooks'],
        'inline hooks' => ['hooks'],
        'token vault' => ['vault'],
        'access reviews' => ['governance'],
        'role conflicts' => ['sod-policies'],
        'outbound provisioning' => ['provisioning'],
        'log streams' => ['audit-streams'],
        'social login' => ['social-providers'],
        'authentication policy' => ['auth-policy'],
        // Permissions are what an app enforces; writing new ones is the vendor's job. A
        // customer composes roles from the ones that exist, on the Roles page.
        'permissions' => ['permissions'],
        'branding' => ['branding'],
        'settings' => ['settings'],
        'usage' => ['usage'],
        'member API keys' => ['directory.api-keys'],
        'setup guide' => ['get-started'],
        // Module pages, withheld by the rail's own areas rather than by anything the
        // module said.
        'sign-in activity' => ['sign-in-activity'],
        'compliance audit trail' => ['compliance.audit'],
        'connectors' => ['connectors.catalog'],
        'trusted devices inventory' => ['devices.index'],
        'risk events' => ['risk-plus.events'],
    ];
}

it('draws a customer\'s console as an admin portal and the person\'s own pages', function (): void {
    everyConsoleModuleOn();
    aCustomerAdministrator();

    expect(app(ConsoleScope::class)->atCustomerAltitude())->toBeTrue();

    $rail = customerRail('dashboard');

    expect(array_keys($rail))->toBe(['overview', 'directory', 'authentication', 'audit', 'account'])
        // The landing page and the Approvals waiting on this person — their own.
        ->and($rail['overview'])->toBe(['dashboard', 'approvals'])
        // EXACTLY the admin portal: Members, Enterprise SSO, Domains, Directory Sync,
        // Roles, the Audit log — and App audit logs, which an open install grants.
        ->and($rail['directory'])->toBe(['directory.members', 'roles'])
        ->and($rail['authentication'])->toBe(['connections', 'domains', 'directories'])
        ->and($rail['audit'])->toBe(['audit', 'audit-logs'])
        // The person's own pages, a module's personal one among them.
        ->and($rail['account'])->toContain('account', 'account.activity', 'devices.mine');
})->group('ux');

it('names a customer\'s console in the console\'s own words', function (): void {
    aCustomerAdministrator();

    $shell = (array) $this->get(route('dashboard'))->assertOk()->inertiaProps('shell');

    $labels = collect($shell['areas'])
        ->mapWithKeys(fn (array $area): array => [$area['label'] => array_column($area['pages'], 'label')])
        ->all();

    expect($labels)->toMatchArray([
        'Overview' => ['Overview', 'Approvals'],
        'Members & roles' => ['Members', 'Roles'],
        'Sign-in' => ['Enterprise SSO', 'Domains', 'Directory Sync'],
        'Audit log' => ['Audit log', 'App audit logs'],
    ]);
})->group('ux');

/**
 * APP AUDIT LOGS ARE SOLD PER ORGANIZATION. Where the plan does not include them, the page
 * is not on a customer's rail and its routes answer 404 — the same list deciding both — and
 * the moment the entitlement lands, both open.
 */
it('offers a customer App audit logs only when their plan includes them', function (): void {
    config(['cbox-id.entitlements.mode' => 'metered']);
    ['organizationId' => $organizationId] = aCustomerAdministrator();

    expect(customerRail('dashboard')['audit'])->toBe(['audit']);
    $this->get(route('audit-logs'))->assertNotFound();
    $this->post(route('audit-logs.exports.store'))->assertNotFound();

    grantFeature($organizationId, (string) config('cbox-id.entitlements.audit_logs'));

    expect(customerRail('dashboard')['audit'])->toBe(['audit', 'audit-logs']);
    $this->get(route('audit-logs'))->assertOk();
});

it('serves every page a customer\'s console keeps', function (string $route): void {
    aCustomerAdministrator();

    $this->get(route($route))->assertOk();
})->with([
    'dashboard', 'approvals', 'directory.members', 'roles', 'connections',
    'connections.create', 'domains', 'directories', 'audit', 'audit-logs', 'account',
    'account.activity', 'account.api-keys', 'device',
]);

it('answers 404 for the product\'s administration on a customer\'s console', function (string $route): void {
    everyConsoleModuleOn();
    aCustomerAdministrator();

    $this->get(route($route))->assertNotFound();
})->with(productAdministration())->group('security');

it('refuses the product\'s WRITES on a customer\'s console too, before they run', function (): void {
    aCustomerAdministrator();

    // Not merely an unlinked page: the write is turned away at the door, so nothing is
    // registered even by an administrator who knows the route.
    $this->post(route('clients.store'), ['name' => 'Smuggled', 'kind' => 'web'])->assertNotFound();
    $this->post(route('dashboard.checklist.dismiss'))->assertNotFound();

    expect(Client::query()->where('name', 'Smuggled')->exists())->toBeFalse();
})->group('security');

it('gives a customer the minimal overview, without the product\'s cards and checklist', function (): void {
    everyConsoleModuleOn();
    aCustomerAdministrator();

    $props = (array) $this->get(route('dashboard'))->assertOk()->inertiaProps();

    // An administrator, so it is THIS console that took them away and not the member gate.
    expect($props['isAdmin'])->toBeTrue()
        ->and($props['cards'])->toBe([])
        ->and($props['checklist'])->toBeNull();
});

it('keeps the product\'s administration for the environment console on the same host', function (string $route): void {
    ['environmentId' => $environmentId, 'vendorId' => $vendorId] = aCustomerAdministrator();

    // The same browser, now the vendor administering their environment. The customer's
    // session is dropped first: a subject session wins the plane, by design.
    session()->flush();
    actAsEnvironmentAdmin($vendorId, $environmentId);

    $this->get(route('environment.'.$route))->assertOk();
})->with(['clients', 'webhooks', 'hooks', 'governance', 'sod-policies', 'provisioning', 'audit-streams', 'branding', 'settings', 'usage']);

it('keeps the whole organization console on a single-tenant install, where it is the only one', function (string $route): void {
    actingAsRole(MembershipRole::Owner);

    expect(app(ConsoleScope::class)->atCustomerAltitude())->toBeFalse();

    $this->get(route($route))->assertOk();
})->with(['clients', 'webhooks', 'hooks', 'governance', 'sod-policies', 'provisioning', 'audit-streams', 'branding', 'settings', 'usage', 'get-started']);

it('keeps the full rail on a single-tenant install', function (): void {
    actingAsRole(MembershipRole::Owner);

    $rail = customerRail('dashboard');

    expect($rail)->toHaveKeys(['developers', 'governance', 'settings'])
        ->and($rail['developers'])->toContain('clients', 'webhooks');
})->group('ux');

/**
 * THE GATE IS ON EVERY ROUTE OF THE CONSOLE, not on a list of them.
 *
 * Which pages a customer's console keeps is one list ({@see CustomerConsole}); the door
 * asks it by route name. That only holds if every organization-console route passes the
 * door — so a route group added beside the existing one, or a module stack built by hand,
 * would serve a customer the product's administration with nothing going red but this.
 */
it('puts every organization-console route behind the customer console\'s door', function (): void {
    $console = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => in_array('platform.auth', $route->middleware(), true));

    // A filter that matched nothing would pass the assertion below and prove nothing.
    expect($console->count())->toBeGreaterThan(100);

    $ungated = $console
        ->reject(fn (RoutingRoute $route): bool => in_array('console.customer', $route->middleware(), true))
        ->map(fn (RoutingRoute $route): string => (string) ($route->getName() ?? $route->uri()))
        ->values()
        ->all();

    expect($ungated)->toBe([]);
})->group('security');

/**
 * A ROUTE BELONGS TO THE PAGE IT EXTENDS, and the most specific page wins — which is the
 * whole of how one list of pages answers for every write a page makes.
 */
it('judges a route by the most specific rail page it belongs to', function (): void {
    expect(CustomerConsole::servesRoute('directory.members.invite'))->toBeTrue()
        ->and(CustomerConsole::servesRoute('connections.domains.verify'))->toBeTrue()
        ->and(CustomerConsole::servesRoute('account.api-keys.store'))->toBeTrue()
        // A ceremony no page owns — signing in, a step-up, approving a device — is served.
        ->and(CustomerConsole::servesRoute('sudo.confirm'))->toBeTrue()
        ->and(CustomerConsole::servesRoute('device.approve'))->toBeTrue()
        // …while a write belonging to a withheld page is withheld with it.
        ->and(CustomerConsole::servesRoute('clients.secrets.revoke'))->toBeFalse()
        ->and(CustomerConsole::servesRoute('directory.api-keys.revoke'))->toBeFalse()
        ->and(CustomerConsole::servesRoute('settings.organization.destroy'))->toBeFalse()
        // `audit-streams` is not a page of `audit`'s, whatever the spelling suggests.
        ->and(CustomerConsole::servesRoute('audit-streams.create'))->toBeFalse()
        ->and(CustomerConsole::servesRoute('audit'))->toBeTrue()
        // A role is composed of permissions on the Roles page; writing a permission is not.
        ->and(CustomerConsole::servesRoute('roles.permissions'))->toBeTrue()
        ->and(CustomerConsole::servesRoute('permissions.store'))->toBeFalse();
});

/**
 * THE ROUTE WALK — every organization-console route, asked at the door on a customer's
 * environment, and the whole of what gets through accounted for.
 *
 * The rail tests above read the rail; this reads the DOOR, which is what actually stops a
 * request. Each route is handed to {@see EnforceCustomerConsole} exactly as the router would
 * hand it, and what it lets through must be one of two things:
 *
 *  - a route of a page the admin portal keeps — Members, Roles, Enterprise SSO, Domains,
 *    Directory Sync, Audit log, App audit logs — or of the person's own (the landing page,
 *    their Approvals, My account);
 *  - a CEREMONY no rail page owns, named below one by one: signing in again, a step-up,
 *    approving a device, switching account. The door serves those because every console
 *    needs them, which is exactly why a route that belongs to no page must be listed here
 *    by name: an organization write added without a page would otherwise walk through.
 *
 * Then every write the door refuses is SENT, and must answer 404 — so the refusal is the
 * door's, ahead of validation and the controller, not a test-side opinion of it.
 */
it('lets nothing through on a customer\'s console but the admin portal, the person\'s own pages and named ceremonies', function (): void {
    everyConsoleModuleOn();
    aCustomerAdministrator();

    $keptPages = [
        'dashboard', 'approvals',
        'directory.members', 'roles',
        'connections', 'domains', 'directories',
        'audit', 'audit-logs',
    ];

    $ceremonies = [
        'accounts', 'accounts.add', 'accounts.switch',
        'activity',
        'device', 'device.lookup', 'device.approve', 'device.deny', 'device.verify',
        'environment.open',
        'link.confirm', 'link.connect', 'link.decline',
        'organization.switch',
        'passkeys.register', 'passkeys.register.options',
        'password.change', 'password.change.update',
        'search',
        'social.connect', 'social.connect.callback',
        'sudo', 'sudo.confirm',
    ];

    $door = app(EnforceCustomerConsole::class);
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => in_array('console.customer', $route->middleware(), true));

    // A walk over nothing proves nothing.
    expect($routes->count())->toBeGreaterThan(200);

    $through = [];
    $refusedWrites = [];

    foreach ($routes as $route) {
        $name = (string) $route->getName();
        $method = collect($route->methods())->first(fn (string $m): bool => $m !== 'HEAD') ?? 'GET';
        $uri = '/'.ltrim((string) preg_replace('/\{[^}]+\}/', '01hzzzzzzzzzzzzzzzzzzzzzzz', $route->uri()), '/');

        $request = Request::create($uri, $method);
        $request->setRouteResolver(fn (): RoutingRoute => $route);

        try {
            $door->handle($request, fn () => response('through'));
            $through[] = $name;
        } catch (HttpExceptionInterface $refusal) {
            expect($refusal->getStatusCode())->toBe(404);

            if (! in_array($method, ['GET', 'HEAD'], true)) {
                $refusedWrites[] = [$method, $uri, $name];
            }
        }
    }

    $unaccounted = collect($through)
        ->reject(function (string $name) use ($keptPages, $ceremonies): bool {
            $owner = CustomerConsole::pageOf($name);

            return $owner === null
                ? in_array($name, $ceremonies, true)
                : in_array($owner[1], $keptPages, true) || $owner[0] === 'account';
        })
        ->values()
        ->all();

    expect($unaccounted)->toBe([], "a customer's console lets through routes no admin-portal page owns:\n".implode("\n", $unaccounted))
        // Every ceremony named is a real route the door serves — a stale name here would
        // be an exemption for nothing.
        ->and(array_values(array_diff($ceremonies, $through)))->toBe([])
        // …and the product's administration really is on the other side of it.
        ->and($refusedWrites)->not->toBe([]);

    foreach ($refusedWrites as [$method, $uri, $name]) {
        expect($this->call($method, $uri)->status())->toBe(404, "{$method} {$uri} ({$name}) reached past the door");
    }
})->group('security');
