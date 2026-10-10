<?php

declare(strict_types=1);

use App\Platform\Console\HandoffTarget;
use App\Platform\RouteLookup;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Tests\Support\ProductionShape;

/**
 * ASKING THE ROUTER A QUESTION, THE WAY PRODUCTION ANSWERS IT.
 *
 * On cboxid.com every environment-console page except its home, and every workspace page
 * that offers an environment (Audit log, Keys, Enterprise SSO, Sign-in rules), answered a
 * bare "400 Bad Request". The environment switcher asks {@see HandoffTarget} whether the
 * page you are on exists in the other environment, and that asked the router with
 * `Request::create('/admin/users')` — a request for `http://localhost/admin/users`. With
 * routes cached and the host allow-list enforced, as production runs
 * ({@see ProductionShape}), the compiled matcher reads that host and Symfony refuses it.
 *
 * Green in every test before this, for the three reasons ProductionShape names. These run
 * in production's shape, so the failure is visible here.
 */
beforeEach(function (): void {
    multiTenantDeployment('cboxid.com');
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);
});

afterEach(fn () => ProductionShape::reset());

it('is the trap: a path-only probe is a localhost request production refuses', function (): void {
    // Production's APP_URL. ProductionShape trusts APP_URL's host, and a local or CI .env
    // with APP_URL=http://localhost would trust the very host this probe is refused for.
    config(['app.url' => 'https://cboxid.com']);
    ProductionShape::cacheRoutes();
    ProductionShape::enforceTrustedHosts();

    expect(fn () => app(Router::class)->getRoutes()->match(Request::create('/admin/users', 'GET')))
        ->toThrow(SuspiciousOperationException::class);
})->group('security');

it('matches a path from the origin of the request being served', function (): void {
    ProductionShape::cacheRoutes();
    ProductionShape::enforceTrustedHosts();

    app()->instance('request', Request::create('https://acme.cboxid.com/admin'));

    expect(app(RouteLookup::class)->get('/admin/users')?->getName())->toBe('environment.users')
        ->and(app(HandoffTarget::class)->sanitize('/admin/users'))->toBe('/admin/users')
        // …and a path nothing serves is still "no route", not an exception.
        ->and(app(RouteLookup::class)->get('/admin/no-such-page'))->toBeNull();
});

it('renders the environment console pages that draw the environment switcher', function (): void {
    ['subjectId' => $subjectId, 'environment' => $environment] = provisionAccount();
    actAsEnvironmentAdmin($subjectId, $environment->id);
    $host = $environment->slug.'.cboxid.com';

    ProductionShape::cacheRoutes();
    ProductionShape::enforceTrustedHosts();

    foreach (['/admin/users', '/admin/organizations', '/admin/apps', '/admin/settings'] as $path) {
        $this->get("https://{$host}{$path}")->assertOk();
    }
});

it('renders the workspace pages that offer an environment', function (): void {
    ['subjectId' => $subjectId] = provisionAccount();
    signInAsSubject($subjectId);

    ProductionShape::cacheRoutes();
    ProductionShape::enforceTrustedHosts();

    $this->get('https://cboxid.com/audit')
        ->assertOk()
        // The switcher carries the page across: Audit log here is Audit log there.
        ->assertInertia(fn (AssertableInertia $page) => $page->component('console/audit'));
});
