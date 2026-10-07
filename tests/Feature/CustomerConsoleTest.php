<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use App\Platform\Console\CustomerConsole;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * A CUSTOMER'S OWN CONSOLE — the organization console on a customer's environment host.
 *
 * The person signed in there administers their company's organization inside somebody
 * else's product. They get an admin portal (members, single sign-on and its domains,
 * directory sync, roles and permissions, the audit log) and their own pages; the product's
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
        'log streaming' => ['audit-streams'],
        'social sign-in' => ['social-providers'],
        'sign-in rules' => ['auth-policy'],
        'appearance' => ['appearance'],
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
        'branding' => ['whitelabel.branding'],
    ];
}

it('draws a customer\'s console as an admin portal and the person\'s own pages', function (): void {
    everyConsoleModuleOn();
    aCustomerAdministrator();

    expect(app(ConsoleScope::class)->atCustomerAltitude())->toBeTrue();

    $rail = customerRail('dashboard');

    expect(array_keys($rail))->toBe(['overview', 'directory', 'authentication', 'audit', 'account'])
        ->and($rail['overview'])->toBe(['dashboard', 'approvals'])
        ->and($rail['directory'])->toBe(['directory.members', 'roles', 'permissions'])
        // Single sign-on carries its domains on the same page; "Sync users in" is
        // directory sync. Social sign-in, sign-in rules and outbound sync are the product's.
        ->and($rail['authentication'])->toBe(['connections', 'directories'])
        ->and($rail['audit'])->toBe(['audit'])
        // The person's own pages, a module's personal one among them.
        ->and($rail['account'])->toContain('account', 'account.activity', 'devices.mine');
})->group('ux');

it('serves every page a customer\'s console keeps', function (string $route): void {
    aCustomerAdministrator();

    $this->get(route($route))->assertOk();
})->with([
    'dashboard', 'approvals', 'directory.members', 'roles', 'permissions', 'connections',
    'connections.create', 'directories', 'audit', 'account', 'account.activity',
    'account.api-keys', 'device',
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
})->with(['clients', 'webhooks', 'hooks', 'governance', 'sod-policies', 'provisioning', 'audit-streams', 'appearance', 'settings', 'usage']);

it('keeps the whole organization console on a single-tenant install, where it is the only one', function (string $route): void {
    actingAsRole(MembershipRole::Owner);

    expect(app(ConsoleScope::class)->atCustomerAltitude())->toBeFalse();

    $this->get(route($route))->assertOk();
})->with(['clients', 'webhooks', 'hooks', 'governance', 'sod-policies', 'provisioning', 'audit-streams', 'appearance', 'settings', 'usage', 'get-started']);

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
        ->and(CustomerConsole::servesRoute('audit'))->toBeTrue();
});
