<?php

declare(strict_types=1);

use App\Platform\Health\LoginHostHealthCheck;
use App\Platform\IssuerUrl;
use App\Platform\PlaneResolver;
use App\Platform\Surfaces\Surface;
use App\Platform\Surfaces\Surfaces;
use App\Platform\TrustedHosts;
use Cbox\Id\Console\ValueObjects\HealthResult;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;

/**
 * LOGIN-HOST MODE: each host serves its own surface and nothing else.
 *
 * One representative route per surface, asked of every host. A surface that is absent on a
 * host answers 404 there, before any route, session or cookie.
 *
 * `loginHostMode()` and the host names live in tests/Pest.php.
 */

/** Whether the path exists on the host at all: anything but a 404. */
function served(string $host, string $path): bool
{
    return test()->get('https://'.$host.$path)->getStatusCode() !== 404;
}

it('names the surface of each host by exact match', function (): void {
    loginHostMode();
    $surfaces = app(Surfaces::class);

    expect($surfaces->split())->toBeTrue()
        ->and($surfaces->of(LOGIN_HOST))->toBe(Surface::Login)
        ->and($surfaces->of(APP_HOST))->toBe(Surface::Console)
        ->and($surfaces->of(API_HOST))->toBe(Surface::Api)
        ->and($surfaces->of(TENANT_HOST))->toBe(Surface::Tenant)
        // No environment holds the slug, a name under the old domain, a look-alike.
        ->and($surfaces->of('nobody.cboxauth.test'))->toBe(Surface::Unmapped)
        ->and($surfaces->of('cbox.cboxid.test'))->toBe(Surface::Unmapped)
        ->and($surfaces->of('login.cboxid.test.attacker.example'))->toBe(Surface::Unmapped);
})->group('security');

it('serves the issuer on the login host and not the console or the operator plane', function (): void {
    loginHostMode();

    expect(served(LOGIN_HOST, '/login'))->toBeTrue()
        ->and(served(LOGIN_HOST, '/.well-known/openid-configuration'))->toBeTrue()
        ->and(served(LOGIN_HOST, '/.well-known/jwks.json'))->toBeTrue()
        ->and(served(LOGIN_HOST, '/oauth/authorize'))->toBeTrue()
        ->and(served(LOGIN_HOST, '/dashboard'))->toBeFalse()
        ->and(served(LOGIN_HOST, '/admin'))->toBeFalse()
        ->and(served(LOGIN_HOST, '/admin/operators'))->toBeFalse()
        ->and(served(LOGIN_HOST, '/v1'))->toBeFalse();
})->group('security');

it('serves the console and the operator plane on the app host and no issuer', function (): void {
    loginHostMode();

    expect(served(APP_HOST, '/dashboard'))->toBeTrue()
        ->and(served(APP_HOST, '/admin/operators'))->toBeTrue()
        ->and(served(APP_HOST, '/login'))->toBeFalse()
        ->and(served(APP_HOST, '/signup'))->toBeFalse()
        ->and(served(APP_HOST, '/.well-known/openid-configuration'))->toBeFalse()
        ->and(served(APP_HOST, '/.well-known/jwks.json'))->toBeFalse()
        ->and(served(APP_HOST, '/oauth/authorize'))->toBeFalse()
        ->and(served(APP_HOST, '/settings/two-factor/enrol'))->toBeFalse()
        ->and(served(APP_HOST, '/v1'))->toBeFalse();

    test()->post('https://'.APP_HOST.'/oauth/token')->assertNotFound();
    test()->post('https://'.APP_HOST.'/login')->assertNotFound();
})->group('security');

it('serves the API on the API host and neither sign-in nor the console', function (): void {
    loginHostMode();

    expect(served(API_HOST, '/v1'))->toBeTrue()
        ->and(served(API_HOST, '/login'))->toBeFalse()
        ->and(served(API_HOST, '/oauth/authorize'))->toBeFalse()
        ->and(served(API_HOST, '/.well-known/openid-configuration'))->toBeFalse()
        ->and(served(API_HOST, '/dashboard'))->toBeFalse()
        ->and(served(API_HOST, '/admin/operators'))->toBeFalse();
})->group('security');

it('serves a tenant its own issuer and never the operator plane', function (): void {
    loginHostMode();

    expect(served(TENANT_HOST, '/login'))->toBeTrue()
        ->and(served(TENANT_HOST, '/.well-known/openid-configuration'))->toBeTrue()
        ->and(served(TENANT_HOST, '/.well-known/jwks.json'))->toBeTrue()
        ->and(served(TENANT_HOST, '/admin/operators'))->toBeFalse()
        ->and(served(TENANT_HOST, '/v1'))->toBeFalse();
})->group('security');

it('issues for nobody on a host that is none of the configured ones', function (string $host): void {
    loginHostMode();

    expect(served($host, '/login'))->toBeFalse()
        ->and(served($host, '/signup'))->toBeFalse()
        ->and(served($host, '/.well-known/openid-configuration'))->toBeFalse()
        ->and(served($host, '/.well-known/jwks.json'))->toBeFalse()
        ->and(served($host, '/oauth/authorize'))->toBeFalse()
        ->and(served($host, '/dashboard'))->toBeFalse()
        ->and(served($host, '/admin/operators'))->toBeFalse()
        ->and(served($host, '/'))->toBeFalse()
        // The probes answer on any host.
        ->and(served($host, '/up'))->toBeTrue();

    test()->post('https://'.$host.'/oauth/token')->assertNotFound();
})->with([
    'a name under the tenant domain no environment holds' => 'nobody.cboxauth.test',
    'the old root name' => 'cbox.cboxid.test',
    'the bare old domain' => 'cboxid.test',
    'a stranger' => 'evil.example',
])->group('security');

it('answers every plane question from the exact host', function (): void {
    $tenant = loginHostMode();
    $planes = app(PlaneResolver::class);

    expect($planes->onOperatorPlane(APP_HOST))->toBeTrue()
        ->and($planes->onOperatorPlane(LOGIN_HOST))->toBeFalse()
        ->and($planes->onOperatorPlane(TENANT_HOST))->toBeFalse()
        ->and($planes->onOperatorPlane('evil.example'))->toBeFalse()
        ->and($planes->isPlatformRootHost(APP_HOST))->toBeTrue()
        ->and($planes->isPlatformRootHost(LOGIN_HOST))->toBeFalse()
        ->and($planes->isPlatformRootHost('evil.example'))->toBeFalse()
        ->and($planes->servesConsole('evil.example'))->toBeFalse()
        ->and($planes->servesEnvironmentAdmin(LOGIN_HOST))->toBeFalse()
        ->and($planes->servesRootMcpOAuth(APP_HOST))->toBeFalse();

    // A console path names a tenant environment on the app host. The context then says
    // "tenant"; the host still says "console", and the host is what decides.
    app(EnvironmentContext::class)->set($tenant);
    app('request')->headers->set('HOST', APP_HOST);

    expect($planes->servesIssuer())->toBeFalse()
        ->and($planes->servesVerificationKeys())->toBeFalse()
        ->and($planes->servesSignup())->toBeFalse()
        ->and($planes->servesFirstPartyIssuer('any-client'))->toBeFalse();

    app('request')->headers->set('HOST', LOGIN_HOST);

    expect($planes->servesIssuer())->toBeTrue()
        ->and($planes->servesVerificationKeys())->toBeTrue()
        ->and($planes->servesSignup())->toBeTrue();
})->group('security');

it('builds links to the other surface', function (): void {
    loginHostMode();
    $surfaces = app(Surfaces::class);

    $this->get('https://'.APP_HOST.'/dashboard');

    expect($surfaces->loginUrl('/login', ['return_to' => '/x y']))->toBe('https://'.LOGIN_HOST.'/login?return_to=%2Fx+y')
        ->and($surfaces->loginUrl())->toBe('https://'.LOGIN_HOST)
        ->and($surfaces->consoleUrl('dashboard'))->toBe('https://'.APP_HOST.'/dashboard')
        ->and($surfaces->loginOrigin())->toBe('https://'.LOGIN_HOST)
        ->and($surfaces->consoleOrigin())->toBe('https://'.APP_HOST);

    // The default environment's issuer is the login host, whichever host asks.
    app(EnvironmentContext::class)->set(platformRootEnvironment());

    expect(IssuerUrl::to('oauth/authorize'))->toBe('https://'.LOGIN_HOST.'/oauth/authorize')
        ->and(IssuerUrl::to())->toBe('https://'.LOGIN_HOST);
});

it('trusts the login host and lets a form end on it', function (): void {
    loginHostMode();

    $patterns = app(TrustedHosts::class)->patterns();
    $trusted = static fn (string $host): bool => array_any($patterns, static fn (string $p): bool => preg_match('{'.$p.'}i', $host) === 1);

    expect($trusted(LOGIN_HOST))->toBeTrue()
        ->and($trusted(APP_HOST))->toBeTrue()
        ->and($trusted('login.cboxid.test.attacker.example'))->toBeFalse()
        ->and(app(PlaneResolver::class)->formActionHosts())->toContain(LOGIN_HOST, APP_HOST);

    $csp = (string) $this->get('https://'.LOGIN_HOST.'/login')->headers->get('Content-Security-Policy');

    expect($csp)->toContain('form-action')->toContain(APP_HOST)->toContain(LOGIN_HOST);
})->group('security');

it('changes nothing while no login host is configured', function (): void {
    // The suite's pinned shape: single-tenant, one host.
    installedDeployment();
    platformRootEnvironment();
    $surfaces = app(Surfaces::class);

    expect($surfaces->split())->toBeFalse()
        ->and($surfaces->current())->toBeNull()
        ->and($surfaces->of('anything.example'))->toBeNull()
        ->and($surfaces->is(Surface::Login, Surface::Console))->toBeFalse()
        ->and($surfaces->loginOrigin())->toBeNull()
        ->and(served('localhost', '/login'))->toBeTrue()
        ->and(served('localhost', '/dashboard'))->toBeTrue()
        ->and(served('localhost', '/.well-known/openid-configuration'))->toBeTrue()
        ->and(served('localhost', '/admin/operators'))->toBeTrue()
        ->and($surfaces->loginUrl('/login'))->toBe(url('/login'))
        ->and($surfaces->consoleUrl('/dashboard'))->toBe(url('/dashboard'))
        ->and(app(PlaneResolver::class)->formActionHosts())->toBe([]);
});

it('keeps the multi-tenant single-host shape as it was', function (): void {
    config([
        'cbox-id.tenancy.multi_tenant' => true,
        'cbox-id.tenancy.account_host' => 'cboxid.test',
        'cbox-id.environments.base_domains' => ['cboxid.test'],
    ]);
    installedDeployment();
    platformRootEnvironment();

    // The root serves its console and sign-in door and is nobody's identity provider; an
    // unmapped name gets neither.
    expect(served('cboxid.test', '/login'))->toBeTrue()
        ->and(served('cboxid.test', '/dashboard'))->toBeTrue()
        ->and(served('cboxid.test', '/.well-known/openid-configuration'))->toBeFalse()
        ->and(served('nope.cboxid.test', '/login'))->toBeFalse()
        ->and(app(Surfaces::class)->consoleUrl('/dashboard'))->toBe('https://cboxid.test/dashboard');
});
it('has the doctor name a login host the rest of the configuration disagrees with', function (): void {
    $labels = static fn (): array => array_map(
        static fn (HealthResult $result): string => $result->status->value.': '.$result->label,
        app(LoginHostHealthCheck::class)->run(),
    );

    loginHostMode();

    expect(implode("\n", $labels()))->not->toContain('fail:');

    config(['cbox-id.issuer' => 'https://'.APP_HOST]);
    expect($labels())->toContain('fail: The issuer is not the login host');

    config(['cbox-id.issuer' => 'https://'.LOGIN_HOST, 'cbox-id.tenancy.account_host' => LOGIN_HOST]);
    expect($labels())->toContain('fail: The login host and the app host are the same host');

    config(['cbox-id.tenancy.account_host' => APP_HOST, 'cbox-id.hosts.login' => 'acme.cboxauth.test']);
    config(['cbox-id.issuer' => 'https://acme.cboxauth.test']);
    expect($labels())->toContain("fail: An environment answers on the login host's name");

    config(['cbox-id.hosts.login' => null]);
    expect($labels())->toBe(['ok: Sign-in host']);
});
