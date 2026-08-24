<?php

declare(strict_types=1);

use App\Http\Middleware\Authenticate;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * The patterns the framework actually verifies against, read from the static it fills at
 * bootstrap rather than from a resolved instance.
 *
 * @return list<string>
 */
function csrfExemptPatterns(): array
{
    $property = new ReflectionProperty(ValidateCsrfToken::class, 'neverVerify');
    $property->setAccessible(true);

    /** @var list<string> $patterns */
    $patterns = $property->getValue();

    return $patterns;
}

/**
 * THE ENDPOINT MUST SURVIVE THE REAL MIDDLEWARE STACK.
 *
 * `/frontend/v1/sign-in` is a deliberate cross-origin POST from a page on somebody else's
 * site. It carries no Laravel CSRF token and never could — and in production it answered
 * 419 while every test in the suite was green, because Laravel's test helpers disable CSRF
 * verification. The whole feature was unusable and nothing said so.
 *
 * So this asserts on the CONFIGURATION rather than on a request: whether the route is
 * exempt is a fact about `bootstrap/app.php`, and a test that sends a request through
 * Laravel's testing stack would be lying to itself the same way the last one did.
 *
 * CSRF is the wrong control here regardless. The attack it prevents is a request made with
 * a victim's ambient session; this one authenticates by the credentials in its body, and
 * what decides whether a caller may make it at all is the publishable key plus the origin
 * allow-list — a check no forged cross-site form can pass.
 */
it('exempts the browser-facing channel from CSRF, or it cannot be called at all', function (): void {
    // Read from the STATIC the framework fills at bootstrap. A freshly resolved instance
    // has an empty `except` — the exclusions never touch it — so asserting there would
    // pass on a deployment that verifies every one of these.
    expect(csrfExemptPatterns())->toContain('frontend/v1/*');
});

/**
 * And the mirror, so the exemption stays as narrow as it is: nothing that relies on an
 * ambient session may join it. A page that is signed in HERE and posts to one of these
 * without a token is exactly what CSRF is for.
 */
it('exempts nothing that authenticates by session alone', function (): void {
    $except = csrfExemptPatterns();

    $sessionAuthenticated = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! in_array('POST', $route->methods(), true)) {
            continue;
        }

        $isExempt = collect($except)->contains(
            fn (string $pattern): bool => Str::is(trim($pattern, '/'), $route->uri()),
        );

        if (! $isExempt) {
            continue;
        }

        // THE ALIAS AND THE CLASS. `gatherMiddleware()` returns what a route DECLARED,
        // and every route in this application declares the alias `platform.auth` — the
        // class string appears on none of them. So the previous version of this check
        // compared against something that could never match: `$sessionAuthenticated` was
        // unconditionally empty, and adding a session-authenticated POST under
        // `frontend/v1/*` — a real CSRF hole — left it green.
        //
        // Both are matched, because a route may legitimately name either, and the alias is
        // resolved rather than assumed: a rename in `bootstrap/app.php` must not silently
        // turn this guard back into one that cannot fail.
        $names = collect($route->gatherMiddleware());

        // REQUIRING a session, not merely resolving one. `platform.auth:optional`
        // populates `CurrentUser` when a valid session happens to be there and continues
        // anonymously otherwise — which is what `/oauth/authorize` needs, since the whole
        // point of that endpoint is to answer somebody who is not signed in yet.
        //
        // The distinction is the parameter, and it is deliberate: the exemption exists for
        // routes a cross-site caller must be able to reach, and a route that REFUSES an
        // anonymous caller is by definition not one of those. What protects the optional
        // ones is that they mint nothing on the request itself — /authorize re-validates
        // client, redirect_uri, scope and PKCE from scratch, and the code it issues can
        // only go to that client's registered redirect_uri.
        $sessionAuthenticating = $names->contains(
            fn (mixed $name): bool => $name === Authenticate::class
                || (is_string($name) && requiresASession($name)),
        );

        if ($sessionAuthenticating) {
            $sessionAuthenticated[] = $route->uri();
        }
    }

    expect($sessionAuthenticated)->toBe([]);
});

/**
 * THE GUARD ABOVE HAS TO BE ABLE TO SEE A HOLE, and for months it could not.
 *
 * Registering a session-authenticated POST inside the exempt prefix is the defect it
 * exists to catch, so this puts one there deliberately and asserts the check finds it.
 * Without this, the only thing proving the guard works is reading it.
 */
it('would notice a session-authenticated route joining the exemption', function (): void {
    Route::middleware(['web', 'platform.auth'])->post('frontend/v1/a-mistake', fn () => 'nope');

    $offenders = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! in_array('POST', $route->methods(), true) || $route->uri() !== 'frontend/v1/a-mistake') {
            continue;
        }

        $names = collect($route->gatherMiddleware());

        if ($names->contains(fn (mixed $n): bool => is_string($n) && requiresASession($n))) {
            $offenders[] = $route->uri();
        }
    }

    expect($offenders)->toBe(['frontend/v1/a-mistake']);
});

/**
 * Whether this middleware name REFUSES an anonymous caller.
 *
 * Resolved through the router's own alias map rather than a hardcoded string: every route
 * in this application declares the alias, the class string appears on none of them, and
 * comparing against the class was what made the guard above unable to fail for months.
 * A rename in `bootstrap/app.php` must not quietly do that again.
 *
 * `:optional` resolves a session without requiring one — see the caller.
 */
function requiresASession(string $name): bool
{
    $alias = Str::before($name, ':');
    $parameters = Str::contains($name, ':') ? Str::after($name, ':') : '';

    if ((app('router')->getMiddleware()[$alias] ?? null) !== Authenticate::class) {
        return false;
    }

    return ! in_array('optional', array_map(trim(...), explode(',', $parameters)), true);
}

/*
 * EVERY ADVERTISED MACHINE ENDPOINT, not just the one that broke last time.
 *
 * `frontend/v1/*` is exempt because a production 419 taught us to. The same reasoning
 * covers a whole class the test did not: every endpoint this deployment ADVERTISES to
 * machines is called by software that has no Laravel session and cannot carry a CSRF
 * token — a conformant DCR client posting to `/oauth/register`, an SP posting a SAML
 * AuthnRequest, an RP posting to `end_session_endpoint`.
 *
 * They are all exempt today. Nothing held them there: remove `oauth/register` from
 * `bootstrap/app.php` and every Dynamic Client Registration in the world starts answering
 * 419, with the whole suite still green — because `ValidateCsrfToken` skips itself under
 * `runningUnitTests()`, so no request-level test in this framework can ever see it.
 *
 * Derived from the discovery document rather than hand-listed, so an endpoint advertised
 * TOMORROW is covered without anyone remembering to add it here.
 */
it('exempts every machine endpoint it advertises to the world', function (): void {
    // DCR advertised, because discovery omits `registration_endpoint` when the mode is
    // `disabled` — and a dataset that quietly empties itself is a test that cannot fail.
    // Found by mutation: with this line absent, deleting `oauth/register` from the
    // exemption list left this green.
    config()->set('cbox-id.oauth.dynamic_registration.mode', 'protected');

    $discovery = $this->getJson('/.well-known/openid-configuration')->assertOk()->json();
    $except = csrfExemptPatterns();

    // The advertised endpoints that machines POST to. `authorization_endpoint` is a
    // browser redirect and `jwks_uri`/`userinfo_endpoint` are GETs, so they are not in
    // scope — this is about POSTs arriving without an ambient session.
    $machinePosts = array_filter([
        $discovery['registration_endpoint'] ?? null,
        $discovery['end_session_endpoint'] ?? null,
    ]);

    // Both, by name — `not->toBeEmpty()` was satisfied by whichever one happened to be
    // advertised, which is how the register case slipped through.
    expect($discovery)->toHaveKey('registration_endpoint')
        ->and($discovery)->toHaveKey('end_session_endpoint')
        ->and($machinePosts)->toHaveCount(2);

    $unprotected = [];

    foreach ($machinePosts as $url) {
        $path = trim((string) parse_url((string) $url, PHP_URL_PATH), '/');

        $exempt = collect($except)->contains(fn (string $pattern): bool => Str::is(trim($pattern, '/'), $path));

        if (! $exempt) {
            $unprotected[] = $path;
        }
    }

    expect($unprotected)->toBe([]);
})->group('security');

/*
 * And the SAML bindings, which discovery does not describe because they are not an OAuth
 * concern. Same class, same failure: a cross-site POST from the SP's own origin, carrying
 * an XML signature and no CSRF token.
 */
it('exempts the SAML POST bindings in both directions', function (): void {
    $except = csrfExemptPatterns();

    foreach (['sso/saml/idp/sso', 'sso/saml/idp/slo', 'sso/saml/acme/acs', 'sso/saml/acme/slo'] as $path) {
        $exempt = collect($except)->contains(fn (string $pattern): bool => Str::is(trim($pattern, '/'), $path));

        expect($exempt)->toBeTrue();
    }
})->group('security');

/**
 * A LIVEWIRE REQUEST HAS TO BE TOLD, not redirected.
 *
 * Two clocks disagree by design: Laravel's session cookie lives for SESSION_LIFETIME
 * while the platform's session record expires on its own idle window. So the cookie —
 * and the CSRF token with it — is still valid when the record is already gone, which
 * means no 419 either, and 419 was the one status the console's error surface handled.
 *
 * What the middleware answered was a 302. Livewire's fetch follows it, gets a 200 of
 * login HTML, cannot parse it as a Livewire response, and has no failure status to
 * report — so clicking around a console whose session had expired did nothing at all.
 * No error, no prompt, no redirect. The page had simply stopped working.
 */
it('answers an expired Livewire request with something the browser can act on', function (): void {
    // No session: exactly the state an idle-expired console is in on its next click.
    // Provisioned but signed OUT — exactly the state an idle-expired console is in on
    // its next click. Without this the first-run guard answers before auth is reached.
    installedDeployment();

    $response = $this->withHeaders(['X-Livewire' => 'true'])->get('/settings');

    expect($response->getStatusCode())->toBe(401);

    // And it names where to go, so the front end can offer "Sign in" rather than a
    // reload that only reaches the login page by a redirect nobody sees.
    expect($response->json('redirect'))->toContain('/login');
})->group('security');

/**
 * The browser navigation keeps its redirect. A person typing the URL should land on the
 * sign-in page, not read a JSON body.
 */
it('still redirects an ordinary navigation to sign in', function (): void {
    installedDeployment();

    $this->get('/settings')->assertRedirect(route('login'));
})->group('security');
