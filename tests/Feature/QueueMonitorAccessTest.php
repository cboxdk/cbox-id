<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateOperator;
use App\Http\Middleware\EnforcePlane;
use App\Platform\Queues\Contracts\ManagerHeartbeat;
use App\Platform\Queues\QueueMonitorAccess;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Models\Environment;
use Cbox\LaravelQueueMonitor\Http\Middleware\EnsureQueueMonitorEnabled;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| The queue monitor answers to platform operators, on the platform root, and nobody else.
|--------------------------------------------------------------------------
|
| It shows every job the deployment runs for every customer — class names, failure
| messages, traces — and can delete records. The package's own default is "allowed when
| APP_ENV is local", on every host, at /queue-monitor. Three locks replace that, and each
| test below holds with any one of the other two removed:
|
|   plane:operator        the platform root's HOST, or 404
|   AuthenticateOperator  a signed-in platform operator, or 404 (sign-in if nobody)
|   QueueMonitorAccess    the package's auth callback, asking both questions again
*/

const MONITOR = '/platform/queues/monitor';

/** A customer environment served on a host of its own, on a multi-tenant deployment. */
function tenantHost(): string
{
    config()->set('cbox-id.tenancy.multi_tenant', true);
    config()->set('cbox-id.tenancy.account_host', 'cboxid.com');

    $host = 'acme-'.Str::lower(Str::random(6)).'.example.test';

    Environment::query()->create([
        'name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(6)),
        'type' => EnvironmentType::Production, 'status' => EnvironmentStatus::Active,
        'domain' => $host, 'domain_verified_at' => now(), 'settings' => [],
    ]);

    return $host;
}

it('opens the monitor for a platform operator', function (): void {
    actAsOperator();

    $this->get(MONITOR)->assertOk()->assertSee('Queue Monitor', false);
    // The dashboard's own data endpoints sit behind the same gate.
    $this->getJson(MONITOR.'/metrics')->assertOk();
})->group('security');

it('sends a signed-out visitor to the one sign-in', function (): void {
    platformRootDeployment();
    installedDeployment();

    $this->get(MONITOR)->assertRedirect(route('login'));
    $this->getJson(MONITOR.'/jobs')->assertRedirect(route('login'));
})->group('security');

it('404s a signed-in subject who does not run this deployment', function (): void {
    platformRootDeployment();

    $subject = app(Subjects::class)->create('ordinary@acme.test', 'Ordinary', 'supersecret123');
    signInAsSubject($subject->id);

    // 404, not 403: the monitor does not confirm to a customer that it exists.
    $this->get(MONITOR)->assertNotFound();
    $this->getJson(MONITOR.'/jobs')->assertNotFound();
    $this->post(MONITOR.'/stuck-jobs/resolve-all')->assertNotFound();
})->group('security');

it('does not exist on a customer environment\'s host, even for an operator', function (): void {
    actAsOperator();
    $host = tenantHost();

    // The operator is signed in and would be let in on the platform root…
    $this->get('https://cboxid.com'.MONITOR)->assertOk();

    // …and a staff tool is still not served on a customer's origin.
    $this->get('https://'.$host.MONITOR)->assertNotFound();
    $this->getJson('https://'.$host.MONITOR.'/jobs')->assertNotFound();
})->group('security');

it('refuses on its own authorization callback, with the route locks out of the way', function (): void {
    // The package's `EnsureQueueMonitorEnabled` asks `LaravelQueueMonitor::check()`, which
    // is our QueueMonitorAccess. Mounted here WITHOUT plane:operator or
    // AuthenticateOperator, so the callback is the only lock left standing.
    Route::middleware(['web', 'platform.auth:optional', EnsureQueueMonitorEnabled::class.':ui'])
        ->get('/_callback-probe', fn (): string => 'in');

    actAsOperator();
    $host = tenantHost();

    $this->get('https://cboxid.com/_callback-probe')->assertOk();
    // On a customer's host the same browser is refused too. (Their session does not
    // resolve there, so this is the callback refusing nobody — the host question it also
    // asks is pinned by the test below.)
    $this->get('https://'.$host.'/_callback-probe')->assertForbidden();

    $subject = app(Subjects::class)->create('ordinary@acme.test', 'Ordinary', 'supersecret123');
    signInAsSubject($subject->id);

    $this->get('https://cboxid.com/_callback-probe')->assertForbidden();
})->group('security');

it('asks about the host in the callback too, not only on the route', function (): void {
    // An operator's session does not resolve on a customer's host at all, so a request
    // there cannot show the callback's host question on its own — the route gate or the
    // missing session answers first. Asked directly instead, from inside a request where
    // the operator IS resolved: same person, two hosts.
    actAsOperator();
    $host = tenantHost();

    Route::middleware(['web', 'platform.auth:optional'])->get('/_host-probe', fn (): string => implode(',', [
        app(QueueMonitorAccess::class)->allows(Request::create('https://cboxid.com'.MONITOR)) ? 'root:yes' : 'root:no',
        app(QueueMonitorAccess::class)->allows(Request::create('https://'.$host.MONITOR)) ? 'tenant:yes' : 'tenant:no',
    ]));

    $this->get('https://cboxid.com/_host-probe')->assertOk()->assertSeeText('root:yes,tenant:no');
})->group('security');

it('serves nothing at the package\'s default address', function (): void {
    actAsOperator();

    $this->get('/queue-monitor')->assertNotFound();
    $this->get('/api/queue-monitor/jobs')->assertNotFound();
})->group('security');

it('puts every monitor route behind every lock', function (): void {
    // From the ROUTING TABLE, so a route a package upgrade adds is held to it without
    // this test knowing the route exists.
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'queue-monitor.'));

    expect($routes->count())->toBeGreaterThan(20, 'the sweep found almost no monitor routes — did the name prefix change?');

    foreach ($routes as $route) {
        $middleware = $route->gatherMiddleware();

        expect(str_starts_with($route->uri(), 'platform/queues/'))->toBeTrue("{$route->uri()} is outside /platform/queues")
            ->and(in_array('plane:operator', $middleware, true) || in_array(EnforcePlane::class.':operator', $middleware, true))
            ->toBeTrue("{$route->uri()} has no host bulkhead")
            ->and(in_array(AuthenticateOperator::class, $middleware, true))->toBeTrue("{$route->uri()} has no operator gate");
    }
})->group('security');

it('keeps its REST API switched off', function (): void {
    actAsOperator();

    expect(config('queue-monitor.api.enabled'))->toBeFalse();

    $this->getJson('/platform/queues/api/jobs')->assertNotFound();
})->group('security');

it('gives the API the dashboard\'s locks, not the package\'s bare api group, if it is ever switched on', function (): void {
    // The routes are decided at boot, so the application is rebuilt with it on.
    putenv('QUEUE_MONITOR_API_ENABLED=true');
    $_SERVER['QUEUE_MONITOR_API_ENABLED'] = $_ENV['QUEUE_MONITOR_API_ENABLED'] = 'true';

    $this->refreshApplication();

    try {
        $api = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'platform/queues/api/'));

        expect($api->count())->toBeGreaterThan(10);

        foreach ($api as $route) {
            $middleware = $route->gatherMiddleware();

            expect($middleware)->toContain('plane:operator')
                ->and($middleware)->toContain(AuthenticateOperator::class)
                ->and($middleware)->not->toContain('api');
        }
    } finally {
        putenv('QUEUE_MONITOR_API_ENABLED');
        unset($_SERVER['QUEUE_MONITOR_API_ENABLED'], $_ENV['QUEUE_MONITOR_API_ENABLED']);
    }
})->group('security');

it('widens the script policy for the monitor and nowhere else', function (): void {
    actAsOperator();

    // The dashboard is Alpine.js: its attributes compile with `new Function`, and its
    // bootstrap is an inline script. Without these it renders as a dead page.
    $monitor = (string) $this->get(MONITOR)->headers->get('Content-Security-Policy');

    expect($monitor)->toContain("script-src 'self' 'unsafe-inline' 'unsafe-eval'")
        ->and($monitor)->toContain("frame-ancestors 'none'")
        ->and($monitor)->toContain("connect-src 'self'")
        ->and($monitor)->not->toContain('https:');

    // The console page beside it keeps the strict policy.
    $console = (string) $this->get(route('platform.queues'))->headers->get('Content-Security-Policy');

    expect($console)->not->toContain('unsafe-eval')
        ->and($console)->not->toContain("'unsafe-inline' 'unsafe-eval'");
})->group('security');

it('shows the operator the queue workers\' state and links the monitor', function (): void {
    actAsOperator();
    app(ManagerHeartbeat::class)->beat('mgr-1', 'app-host');

    $this->get(route('platform.queues'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/platform/queues')
            ->where('help.topic', 'platform-queues')
            // The suite runs jobs inline, so nothing is supervised — and that is reported
            // as such rather than as a dead manager.
            ->where('manager.state', 'not-supervised')
            ->where('manager.host', 'app-host')
            ->where('healthy', true)
            ->where('monitorHref', route('queue-monitor.dashboard')));
});

it('404s the queues page for anyone who does not run the deployment', function (): void {
    platformRootDeployment();

    $subject = app(Subjects::class)->create('ordinary@acme.test', 'Ordinary', 'supersecret123');
    signInAsSubject($subject->id);

    $this->get(route('platform.queues'))->assertNotFound();
})->group('security');
