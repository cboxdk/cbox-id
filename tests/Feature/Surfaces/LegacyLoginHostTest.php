<?php

declare(strict_types=1);

use App\Platform\Api\Problem;
use App\Platform\Health\LoginHostHealthCheck;
use App\Platform\Surfaces\Surface;
use App\Platform\Surfaces\Surfaces;
use App\Platform\TrustedHosts;
use Cbox\Id\Console\ValueObjects\HealthResult;
use Illuminate\Testing\TestResponse;

/**
 * A NAME THE DEFAULT ENVIRONMENT USED TO BE ISSUED UNDER ONLY REDIRECTS
 * (`cbox-id.hosts.legacy_login`, App\Http\Middleware\RedirectLegacyLoginHost).
 *
 * One test per rule. `loginHostMode()` and the host names live in tests/Pest.php.
 */
const LEGACY_HOST = 'cbox.cboxid.test';

function legacyLoginHostMode(): void
{
    loginHostMode();
    config(['cbox-id.hosts.legacy_login' => [LEGACY_HOST]]);
    TrustedHosts::forget();
}

function legacy(string $method, string $path, array $headers = []): TestResponse
{
    return test()->call($method, 'https://'.LEGACY_HOST.$path, server: test()->transformHeadersToServerVars($headers));
}

it('names a listed host the legacy surface, ahead of the environment its slug resolves to', function (): void {
    legacyLoginHostMode();
    $surfaces = app(Surfaces::class);

    expect($surfaces->of(LEGACY_HOST))->toBe(Surface::Legacy)
        ->and($surfaces->of(strtoupper(LEGACY_HOST)))->toBe(Surface::Legacy)
        ->and(Surface::Legacy->issues())->toBeFalse()
        // The hosts in use keep their surface even when somebody lists them.
        ->and($surfaces->of(LOGIN_HOST))->toBe(Surface::Login)
        ->and($surfaces->of('other.cboxid.test'))->toBe(Surface::Unmapped);

    config(['cbox-id.hosts.legacy_login' => [LEGACY_HOST, LOGIN_HOST, APP_HOST]]);

    expect($surfaces->of(LOGIN_HOST))->toBe(Surface::Login)
        ->and($surfaces->of(APP_HOST))->toBe(Surface::Console);
})->group('security');

it('redirects the pages people hold links to, to the same path and query on the login host', function (string $path): void {
    legacyLoginHostMode();

    legacy('GET', $path)
        ->assertStatus(308)
        ->assertHeader('Location', 'https://'.LOGIN_HOST.$path)
        ->assertHeaderMissing('Set-Cookie');
})->with([
    '/login',
    '/login?login_hint=ada%40example.com&return_to=%2Fsettings',
    '/signup',
    '/forgot-password',
    '/device?user_code=BCDF-GHJK',
    // A person's own pages are the login host's.
    '/settings',
    '/settings/devices',
    '/settings/two-factor',
    '/settings/passkeys/01hx',
    '/account',
    '/invitations/abc',
    '/app/oauth/callback?code=x&state=y',
    '/authenticator/privacy',
    '/oauth/logout?post_logout_redirect_uri=https%3A%2F%2Fapp.example%2F',
]);

it('redirects HEAD as it redirects GET', function (): void {
    legacyLoginHostMode();

    legacy('HEAD', '/login')->assertStatus(308)->assertHeader('Location', 'https://'.LOGIN_HOST.'/login');
});

it('sends a console page people bookmarked to the app host', function (string $path): void {
    legacyLoginHostMode();

    legacy('GET', $path)->assertStatus(308)->assertHeader('Location', 'https://'.APP_HOST.$path);
})->with(['/approvals', '/dashboard', '/settings/branding']);

it('redirects discovery and the keys, so a client reads a document naming the login host', function (string $path): void {
    legacyLoginHostMode();

    legacy('GET', $path)->assertStatus(308)->assertHeader('Location', 'https://'.LOGIN_HOST.$path);
})->with([
    '/.well-known/openid-configuration',
    '/.well-known/oauth-authorization-server',
    '/.well-known/jwks.json',
    '/.well-known/cbox-cli',
    '/.well-known/cbox-authenticator',
]);

it('answers 410 with a problem naming the new issuer to a protocol call, in any method', function (string $method, string $path): void {
    legacyLoginHostMode();

    $response = legacy($method, $path);

    $response->assertStatus(410)->assertHeaderMissing('Location')->assertHeaderMissing('Set-Cookie');

    expect($response->headers->get('Content-Type'))->toBe(Problem::CONTENT_TYPE)
        ->and($response->json('error'))->toBe('issuer_moved')
        ->and($response->json('status'))->toBe(410)
        ->and($response->json('issuer'))->toBe('https://'.LOGIN_HOST)
        ->and($response->json('api_base'))->toBe('https://'.API_HOST.'/v1')
        ->and($response->json('detail'))->toContain('https://'.LOGIN_HOST.'/.well-known/openid-configuration');
})->with([
    ['POST', '/oauth/token'],
    ['POST', '/oauth/device_authorization'],
    ['POST', '/oauth/backchannel_authentication'],
    ['POST', '/oauth/revoke'],
    ['POST', '/oauth/register'],
    ['GET', '/oauth/authorize?client_id=x&response_type=code'],
    ['GET', '/oauth/userinfo'],
    ['GET', '/api/v1/users'],
    ['POST', '/api/v1/devices'],
    ['GET', '/v1/users'],
    ['POST', '/mcp'],
    ['GET', '/scim/v2/Users'],
    ['POST', '/frontend/v1/sign-in'],
    // A page, written to: a 308 would replay the form at the other host.
    ['POST', '/login'],
    ['POST', '/logout'],
    ['DELETE', '/settings/passkeys/01hx'],
])->group('security');

it('never redirects a request that carries a credential', function (): void {
    legacyLoginHostMode();

    legacy('GET', '/.well-known/openid-configuration', ['Authorization' => 'Bearer abc'])
        ->assertStatus(410)
        ->assertHeaderMissing('Location');
})->group('security');

it('serves neither the operator plane nor the console under the old name, and redirects neither', function (string $method, string $path): void {
    legacyLoginHostMode();

    legacy($method, $path)->assertNotFound()->assertHeaderMissing('Location')->assertHeaderMissing('Set-Cookie');
})->with([
    ['GET', '/admin'],
    ['GET', '/admin/operators'],
    ['POST', '/admin/operators'],
    ['GET', '/apps'],
    ['GET', '/organizations'],
    ['GET', '/auth/start'],
])->group('security');

it('keeps answering the health probes', function (): void {
    legacyLoginHostMode();

    legacy('GET', '/up')->assertOk();
});

it('trusts the legacy host so it can be answered, and stops when it is taken off the list', function (): void {
    legacyLoginHostMode();
    config(['cbox-id.hosts.legacy_login' => ['old.example.org']]);

    expect(app(TrustedHosts::class)->patterns())->toContain('^old\.example\.org$');

    // Retiring the name is the config change alone: the host is unmapped again.
    config(['cbox-id.hosts.legacy_login' => []]);

    expect(app(TrustedHosts::class)->patterns())->not->toContain('^old\.example\.org$')
        ->and(app(Surfaces::class)->of(LEGACY_HOST))->toBe(Surface::Unmapped);

    legacy('GET', '/login')->assertNotFound();
    legacy('POST', '/oauth/token')->assertNotFound();
});

it('ignores the list in single-host mode', function (): void {
    installedDeployment();
    config(['cbox-id.hosts.legacy_login' => [LEGACY_HOST]]);

    $surfaces = app(Surfaces::class);

    expect($surfaces->split())->toBeFalse()
        ->and($surfaces->legacyLoginHosts())->toBe([])
        ->and($surfaces->of(LEGACY_HOST))->toBeNull();

    expect(test()->get('http://'.LEGACY_HOST.'/login')->getStatusCode())->not->toBe(308);
});

it('reports the legacy hosts in the doctor, and a listed host that is in use', function (): void {
    legacyLoginHostMode();

    $names = static fn (): array => array_map(
        static fn (HealthResult $r): string => $r->status->value.':'.$r->label,
        app(LoginHostHealthCheck::class)->run(),
    );

    expect($names())->toContain('ok:Legacy login host');

    config(['cbox-id.hosts.legacy_login' => [APP_HOST]]);

    expect($names())->toContain('fail:A legacy login host is one of the hosts in use');
});
