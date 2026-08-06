<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * Four protocol endpoints are cross-site or back-channel BY DEFINITION, and all four
 * sat in Laravel's `web` group inheriting PreventRequestForgery.
 *
 * A conformant third party has no Laravel CSRF token to send, so each answered 419 with
 * an HTML session-expired page — or, for /authorize, 405. The SAML case is the worst of
 * them: the IdP metadata this platform PUBLISHES advertises the HTTP-POST binding for
 * Single Logout, so a service provider reads the metadata, picks POST, and every logout
 * fails while the IdP session stays live.
 *
 * NOTE ON HOW THIS IS ASSERTED. Driving a request through the test client cannot prove
 * the exemption: `PreventRequestForgery::handle()` short-circuits on
 * `runningUnitTests()`, so CSRF never fires under Pest and such a test passes whether or
 * not the endpoint is exempt. The first version of this file did exactly that and was
 * green with the exemptions deleted. So the exemption is asserted against the
 * middleware's own `inExceptArray()`, which is the code that decides it in production.
 */
function exemptFromCsrf(string $uri): bool
{
    $middleware = app(PreventRequestForgery::class);
    $check = new ReflectionMethod($middleware, 'inExceptArray');

    return (bool) $check->invoke($middleware, Request::create($uri, 'POST'));
}

it('exempts every cross-site protocol endpoint from CSRF', function (string $uri, array $body): void {
    expect(exemptFromCsrf($uri))->toBeTrue(
        "POST {$uri} is not CSRF-exempt — a conformant third party has no Laravel token to send"
    );
})->with([
    // SAML Single Logout, IdP role. Advertised in the metadata; Okta and ADFS prefer it.
    'saml slo (idp)' => ['/sso/saml/idp/slo', ['SAMLRequest' => 'not-a-real-request']],
    // OIDC RP-Initiated Logout §5 makes POST mandatory at the end-session endpoint.
    'oidc end_session' => ['/oauth/logout', ['id_token_hint' => 'not-a-real-token']],
    // OIDC Core §3.1.2.1: the authorization endpoint MUST support GET and POST.
    'oidc authorize' => ['/oauth/authorize', ['response_type' => 'code', 'client_id' => 'nope']],
]);

it('exempts dynamic client registration, including the management URLs', function (): void {
    // A back-channel JSON API: the credential is the registration access token, not a
    // session. Latent while registration is disabled — and a 419 the day it is enabled.
    expect(exemptFromCsrf('/oauth/register'))->toBeTrue()
        ->and(exemptFromCsrf('/oauth/register/abc123'))->toBeTrue();
});

/**
 * The verb half of the same problem, and the one a request test CAN prove: Volt::route
 * registers GET only, so /authorize answered 405 to the form-POST that OIDC Core
 * §3.1.2.1 makes mandatory.
 */
it('routes the authorization endpoint over POST as well as GET', function (): void {
    $methods = collect(Route::getRoutes())
        ->filter(fn ($r): bool => $r->uri() === 'oauth/authorize')
        ->flatMap(fn ($r): array => $r->methods())
        ->unique()
        ->values();

    expect($methods)->toContain('GET')->toContain('POST');
});

/**
 * The GET binding must keep working — the exemption is about the verb, not about
 * loosening the endpoint.
 */
it('still serves the authorization endpoint over GET', function (): void {
    expect($this->get('/oauth/authorize?response_type=code&client_id=nope')->status())
        ->not->toBe(405);
});
