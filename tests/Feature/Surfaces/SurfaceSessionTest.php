<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use App\Platform\Surfaces\SessionSurface;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * LOGIN-HOST MODE: one session per surface.
 *
 * Its own cookie name per surface, a marker in the session that the surface it was minted
 * on is the only one to accept it, and no write to the login host from another origin.
 * `loginHostMode()` and the host names are SurfaceFenceTest's.
 */
function sessionCookie(TestResponse $response): ?Cookie
{
    return array_find(
        $response->headers->getCookies(),
        static fn (Cookie $cookie): bool => str_contains($cookie->getName(), 'cbox-s') || str_contains($cookie->getName(), 'cbox-console') || str_ends_with($cookie->getName(), 'session'),
    );
}

it('gives each surface its own host-only session cookie', function (string $host, string $path, string $name): void {
    loginHostMode();

    $cookie = sessionCookie($this->get('https://'.$host.$path));

    expect($cookie?->getName())->toBe($name)
        ->and($cookie?->isSecure())->toBeTrue()
        ->and($cookie?->isHttpOnly())->toBeTrue()
        ->and($cookie?->getDomain())->toBeNull()
        ->and($cookie?->getPath())->toBe('/');
})->with([
    'the login host' => [LOGIN_HOST, '/login', '__Host-cbox-sso'],
    'the app host' => [APP_HOST, '/dashboard', '__Host-cbox-console'],
    'a tenant host' => [TENANT_HOST, '/login', '__Host-cbox-session'],
])->group('security');

it('drops the prefix a browser would refuse on plain http', function (): void {
    loginHostMode();

    expect(sessionCookie($this->get('http://'.LOGIN_HOST.'/login'))?->getName())->toBe('cbox-sso');
});

it('keeps the configured cookie name while no login host is configured', function (): void {
    installedDeployment();
    platformRootEnvironment();

    expect(sessionCookie($this->get('/login'))?->getName())->toBe(config('session.cookie'))
        ->and(config('session.cookie'))->not->toContain('__Host-');
});

it('accepts a session on the surface that minted it and on no other', function (): void {
    loginHostMode();
    $account = provisionAccount();

    // A session begun on the app host, then signed in.
    $this->get('https://'.APP_HOST.'/dashboard')->assertRedirect();
    expect(session(SessionSurface::KEY))->toBe('console');

    signInAsSubject($account['subjectId']);
    $signedIn = session(PlatformAuth::SESSION_KEY);

    $this->get('https://'.APP_HOST.'/dashboard');
    expect(session(PlatformAuth::SESSION_KEY))->toBe($signedIn);

    // The same session presented to the login host: started over, signed in as nobody.
    $this->get('https://'.LOGIN_HOST.'/sudo')->assertRedirect();

    expect(session(PlatformAuth::SESSION_KEY))->toBeNull()
        ->and(session(SessionSurface::KEY))->toBe('login');
})->group('security');

it('refuses a session that carries no surface or another one', function (): void {
    loginHostMode();
    $account = provisionAccount();
    $surface = app(SessionSurface::class);

    // Signed in with no marker: a session from before the split.
    signInAsSubject($account['subjectId']);
    expect(session(SessionSurface::KEY))->toBeNull();

    $request = Request::create('https://'.APP_HOST.'/dashboard');
    $request->setLaravelSession(app('session.store'));

    expect($surface->admits($request))->toBeFalse();

    session()->put(SessionSurface::KEY, 'login');
    expect($surface->admits($request))->toBeFalse();

    session()->put(SessionSurface::KEY, 'console');
    expect($surface->admits($request))->toBeTrue();

    // A tenant's marker names its host: another environment's session is another surface's.
    $tenant = Request::create('https://'.TENANT_HOST.'/login');
    $tenant->setLaravelSession(app('session.store'));
    session()->put(SessionSurface::KEY, 'tenant:other.cboxauth.test');

    expect($surface->admits($tenant))->toBeFalse()
        ->and($surface->marker($tenant))->toBe('tenant:'.TENANT_HOST);

    // And through the door: the unmarked, signed-in session is dropped.
    session()->forget(SessionSurface::KEY);
    $this->get('https://'.APP_HOST.'/dashboard')->assertRedirect();

    expect(session(PlatformAuth::SESSION_KEY))->toBeNull();
})->group('security');

it('refuses a write to the login host that another origin made', function (array $headers, bool $refused): void {
    loginHostMode();

    $status = $this->withHeaders($headers)
        ->post('https://'.LOGIN_HOST.'/login', ['email' => 'a@example.com', 'password' => 'x'])
        ->getStatusCode();

    expect($status === 403)->toBe($refused);
})->with([
    'the app host, by fetch metadata' => [['Sec-Fetch-Site' => 'same-site'], true],
    'another site, by fetch metadata' => [['Sec-Fetch-Site' => 'cross-site'], true],
    'the app host, by origin' => [['Origin' => 'https://'.APP_HOST], true],
    'a sandboxed page' => [['Origin' => 'null'], true],
    'the login host itself, by fetch metadata' => [['Sec-Fetch-Site' => 'same-origin'], false],
    'the login host itself, by origin' => [['Origin' => 'https://'.LOGIN_HOST], false],
    'no browser at all' => [[], false],
])->group('security');

it('lets the protocol endpoints take a cross-site post, and reads through', function (): void {
    loginHostMode();
    $crossSite = ['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://rp.example'];

    expect($this->withHeaders($crossSite)->post('https://'.LOGIN_HOST.'/oauth/authorize')->getStatusCode())->not->toBe(403)
        ->and($this->withHeaders($crossSite)->post('https://'.LOGIN_HOST.'/oauth/logout')->getStatusCode())->not->toBe(403)
        ->and($this->withHeaders($crossSite)->get('https://'.LOGIN_HOST.'/login')->getStatusCode())->toBe(200)
        // The consent answers are the login host's own pages' to give.
        ->and($this->withHeaders($crossSite)->post('https://'.LOGIN_HOST.'/oauth/authorize/abc/approve')->getStatusCode())->toBe(403);
})->group('security');

it('checks no origin in single-host mode', function (): void {
    installedDeployment();
    platformRootEnvironment();

    $status = $this->withHeaders(['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://elsewhere.example'])
        ->post('/login', ['email' => 'a@example.com', 'password' => 'x'])
        ->getStatusCode();

    expect($status)->not->toBe(403);
});
