<?php

declare(strict_types=1);

use App\Platform\FederatedLanding;
use App\Platform\PlatformAuth;
use Cbox\Id\Federation\Contracts\AssertionValidator;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Exceptions\InvalidAssertion;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\PlatformRoot;

/**
 * The platform-root host is not an identity provider.
 *
 * It used to serve half an IdP: `/.well-known/openid-configuration` returned 200 with
 * `issuer: https://cboxid.com` and `authorization_endpoint: https://cboxid.com/oauth/authorize`
 * — a URL that 404s, because the interactive consent screen lives only on tenant hosts.
 * Token, userinfo, JWKS, revoke, introspect, PAR, device_authorization, backchannel,
 * logout and SCIM all answered there too. A conformant client discovers that document and
 * dead-ends; half an IdP is worse than none, because it is discoverable.
 *
 * The whole protocol surface is confined to `plane:issuer` via `cbox-id.api.middleware`,
 * which the framework applies to its route group.
 *
 * `plane:issuer` is deliberately NOT the gate the console uses. The two were one plane,
 * and one gate serving two purposes is what left the root with no `/login` at all: the
 * root is a tenant whose subjects sign in there, it just is not an issuer. Both halves are
 * asserted here, on the same host, because the value of the split is that they disagree.
 */

/** Stand up the multi-tenant SaaS shape: a platform root plus one tenant environment. */
function saasShape(): array
{
    // STATED, not merely implied by the domain list. `PlaneResolver::isMultiTenant()` reads
    // the flag first and only falls back to deriving from `base_domains` when no deployment
    // has stated one — and the suite's baseline states single-tenant, where every bulkhead
    // below is off by design and the root host really does serve the whole IdP.
    multiTenantDeployment();

    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);

    $tenant = Environment::query()->create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active', 'is_default' => false]);
    $root = Environment::query()->create(['name' => 'Platform', 'slug' => 'platform', 'status' => 'active']);
    $root->makeDefault();

    return [$root, $tenant];
}

it('404s the whole IdP surface on the platform-root host', function (): void {
    saasShape();

    // An unmapped host (the apex itself) resolves to the platform-root environment —
    // exactly what SetEnvironment/ResolveEnvironment do in production.
    $this->getJson('http://cboxid.com/.well-known/openid-configuration')->assertNotFound();
    $this->getJson('http://cboxid.com/.well-known/jwks.json')->assertNotFound();
    $this->getJson('http://cboxid.com/.well-known/oauth-authorization-server')->assertNotFound();
    $this->getJson('http://cboxid.com/.well-known/oauth-protected-resource')->assertNotFound();
    $this->postJson('http://cboxid.com/oauth/token')->assertNotFound();
    $this->postJson('http://cboxid.com/oauth/introspect')->assertNotFound();
    $this->postJson('http://cboxid.com/oauth/revoke')->assertNotFound();
    $this->postJson('http://cboxid.com/oauth/par')->assertNotFound();
    $this->postJson('http://cboxid.com/oauth/device_authorization')->assertNotFound();
    $this->getJson('http://cboxid.com/oauth/userinfo')->assertNotFound();
    $this->get('http://cboxid.com/oauth/logout')->assertNotFound();
    $this->getJson('http://cboxid.com/sso/saml/idp/metadata')->assertNotFound();
    $this->getJson('http://cboxid.com/scim/v2/ServiceProviderConfig')->assertNotFound();

    // The IdP-ROLE SAML endpoints are part of the issuer surface and gated with it —
    // the app's own override of /sso/saml/idp/sso included, or it would be the one hole
    // left in the wall.
    $this->post('http://cboxid.com/sso/saml/idp/sso')->assertNotFound();
    $this->get('http://cboxid.com/sso/saml/idp/slo')->assertNotFound();

    // …and the environment-admin door, which asks the same host question for its own
    // reason: it is how an ACCOUNT reaches into an environment from the account plane, so
    // it does not exist on the account plane itself.
    $this->get('http://cboxid.com/admin/single-sign-on')->assertNotFound();
});

/**
 * The other half, and the reason the issuer question had to stop doing two jobs.
 *
 * `cboxid.com/login` was a 404. So was `/dashboard`, `/account`, `/settings` — the entire
 * console. One gate answered both "is this an issuer" and "does the console live here",
 * and the root is the one host where those answers differ: every account member already
 * holds a subject identity in the platform-root environment, and there was nowhere on that
 * host for them to use it.
 *
 * Asserted on the SAME host, in the same shape, as the refusals above — that contrast is
 * the whole of this change, and asserting it anywhere else would be asserting something
 * easier.
 */
it('serves the console on the platform-root host', function (): void {
    saasShape();

    $this->get('http://cboxid.com/login')->assertOk();
    $this->get('http://cboxid.com/forgot-password')->assertOk();

    // Authenticated console pages exist here too — a redirect to sign-in, not a 404. The
    // distinction is the whole point: 404 means the surface is absent on this host, which
    // is what it used to mean, and no amount of signing in would have changed it.
    $this->get('http://cboxid.com/dashboard')->assertRedirect('http://cboxid.com/login');
    $this->get('http://cboxid.com/account')->assertRedirect('http://cboxid.com/login');
});

/**
 * …but only under the platform root's OWN name.
 *
 * `SetEnvironment` answers an unmapped host with the platform root, deliberately, and
 * `TrustHosts` admits every wildcard name under `base_domains` — so the resolved CONTEXT
 * is the root environment for `nope.cboxid.com` exactly as it is for `cboxid.com`. A
 * console gate that read the context would have put a working sign-in form, with a working
 * password-reset mailer behind it, on every name pointed at the deployment. It reads the
 * HOST; this is what says so.
 */
it('refuses the console on a wildcard name that is not the platform root', function (): void {
    saasShape();

    $this->get('http://nope.cboxid.com/login')->assertNotFound();
    $this->get('http://nope.cboxid.com/dashboard')->assertNotFound();
})->group('security');

/**
 * ...but INBOUND federation is not issuer surface, and the account plane genuinely uses it.
 *
 * An account's organization lives in the platform-root environment, so home-realm
 * discovery on `/login` and `/signup` — both `plane:account`, both root-host
 * only — resolves the account org's connection and redirects to `/sso/.../{connection}/...`
 * on the host the member is already standing on. Gating those as issuer surface 404'd the
 * redirect, its callback, and the SP metadata the IdP admin imports to set the connection
 * up at all. For an account org with SsoEnforcement::Required and a verified domain that
 * is a lockout: `workspace/login` refuses the password AND the SSO door does not exist.
 */
function rootSsoConnection(string $type = 'saml'): Connection
{
    return app(PlatformRoot::class)->run(function () use ($type): Connection {
        $org = app(Organizations::class)->create(new NewOrganization('Account Co', 'account-co-'.$type));
        $connections = app(Connections::class);

        $connection = $connections->create(
            $org->id,
            $type === 'saml' ? ConnectionType::Saml : ConnectionType::Oidc,
            'Okta',
            $type === 'saml' ? [] : [
                'issuer' => 'https://idp.example.com',
                'client_id' => 'rp-client',
                'client_secret' => 'rp-secret',
                'authorization_endpoint' => 'https://idp.example.com/authorize',
                'token_endpoint' => 'https://idp.example.com/token',
                'jwks_uri' => 'https://idp.example.com/jwks',
            ],
        );

        $connections->activate($org->id, $connection->id);

        return $connection->refresh();
    });
}

it('serves the account-plane SSO callbacks on the platform-root host', function (): void {
    saasShape();

    $saml = rootSsoConnection('saml');

    // The assertion comes back to the same host it left from.
    expect($this->post('http://cboxid.com/sso/saml/'.$saml->id.'/acs', ['SAMLResponse' => 'x'])->status())
        ->not->toBe(404);

    $oidc = rootSsoConnection('oidc');

    expect($this->get('http://cboxid.com/sso/oidc/'.$oidc->id.'/callback')->status())->not->toBe(404);

    // The ENTRY half — /sso/oidc/{c}/redirect, /sso/saml/{c}/login and the SP metadata
    // an IdP admin imports — is registered by the framework package, not by this app, so
    // it is pinned there (tests/Feature/Api/SurfaceMiddlewareTest.php). It reaches this
    // app on the next cboxdk/laravel-id release.
});

/**
 * A VERIFIED assertion lands a session on the platform-root host.
 *
 * This used to assert the opposite, and the opposite was a plane talking rather than a
 * decision: {@see FederatedLanding} forked on `onAccountPlane()` and refused any subject
 * that carried no account membership, because the root served the account console and
 * nothing else. The root is a tenant. A subject of it who authenticates at their IdP is
 * signed in, exactly as they would be on any other host, and what their organization
 * OWNS decides what the console then contains.
 */
it('lands a verified assertion in the console on the platform-root host', function (): void {
    saasShape();

    $connection = rootSsoConnection('saml');

    app()->bind(AssertionValidator::class, fn () => new class implements AssertionValidator
    {
        public function validate(Connection $connection, string $rawResponse): FederatedPrincipal
        {
            return new FederatedPrincipal(
                provider: 'saml',
                subject: 'idp|account-member',
                email: 'member@account.example',
                name: 'Account Member',
            );
        }
    });

    $response = $this->post('http://cboxid.com/sso/saml/'.$connection->id.'/acs', ['SAMLResponse' => 'x']);

    $response->assertRedirect(route('dashboard'));
    $response->assertSessionHasNoErrors();

    expect(session(PlatformAuth::SESSION_KEY))->not->toBeNull();
});

/**
 * The REJECTED branch, pinned HERE because this file is the one that resolves the plane
 * the way production does — from the request host, through SetEnvironment/
 * ResolveEnvironment — rather than by setting the environment context directly.
 * {@see FederatedLanding::failed()} is exercised in
 * tests/Feature/InboundSsoBrowserLoginTest.php; what is proven here is that the host
 * which serves the ACS also serves the page the ACS sends a failure to.
 *
 * A 404 on this branch is worse than a lockout: it lands AFTER the member has
 * successfully authenticated at their IdP, so they have every reason to believe SSO
 * worked and no way to discover it did not. So the redirect is FOLLOWED — a redirect to
 * a route that does not exist on this host IS the bug, and only following it catches that.
 */
it('lands a rejected assertion on a sign-in page that exists on the platform-root host', function (): void {
    saasShape();

    $connection = rootSsoConnection('saml');

    app()->bind(AssertionValidator::class, fn () => new class implements AssertionValidator
    {
        public function validate(Connection $connection, string $rawResponse): FederatedPrincipal
        {
            throw InvalidAssertion::make('signature mismatch');
        }
    });

    $response = $this->post('http://cboxid.com/sso/saml/'.$connection->id.'/acs', ['SAMLResponse' => 'forged']);

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors('email');

    // Readable, and not an assertion-forgery oracle: the reason is logged, not shown.
    $error = session('errors')->first('email');
    expect($error)->toContain('could not verify')
        ->and($error)->not->toContain('signature');

    // The destination must be served by THIS host. `/login` used to 404 here and that 404
    // was the whole bug: it lands AFTER a successful authentication at the IdP, so the
    // person has every reason to believe SSO worked and no way to discover it did not.
    $this->get('http://cboxid.com/login')->assertOk();
});

it('serves the whole IdP surface on a tenant host', function (): void {
    saasShape();

    $discovery = $this->getJson('http://acme.cboxid.com/.well-known/openid-configuration')
        ->assertOk();

    // The document a client is handed must be one it can actually follow: the
    // authorization endpoint is advertised on the SAME host that serves it.
    expect($discovery->json('issuer'))->toContain('acme.cboxid.com')
        ->and($discovery->json('authorization_endpoint'))->toContain('acme.cboxid.com/oauth/authorize');

    $this->getJson('http://acme.cboxid.com/.well-known/jwks.json')->assertOk();
    $this->getJson('http://acme.cboxid.com/.well-known/oauth-authorization-server')->assertOk();

    // /oauth/token rejects an empty request on its own terms (invalid_request) — the
    // point is that it is REACHABLE here, unlike on the root host.
    expect($this->postJson('http://acme.cboxid.com/oauth/token')->status())->not->toBe(404);
    expect($this->getJson('http://acme.cboxid.com/scim/v2/ServiceProviderConfig')->status())->not->toBe(404);
});

it('serves everything on the one host in a single-tenant / self-hosted deployment', function (): void {
    // No base domains → no account door, no plane split: the lone host IS the IdP and
    // the bulkheads must not take any of it away.
    config(['cbox-id.environments.base_domains' => []]);

    $only = Environment::query()->create(['name' => 'Platform', 'slug' => 'platform', 'status' => 'active']);
    $only->makeDefault();

    $this->getJson('/.well-known/openid-configuration')->assertOk();
    $this->getJson('/.well-known/jwks.json')->assertOk();
    $this->getJson('/.well-known/oauth-authorization-server')->assertOk();
    $this->getJson('/.well-known/oauth-protected-resource')->assertOk();

    expect($this->postJson('/oauth/token')->status())->not->toBe(404)
        ->and($this->getJson('/scim/v2/ServiceProviderConfig')->status())->not->toBe(404);
});

it('keeps the account-plane API on the platform-root host', function (): void {
    saasShape();

    // routes/api.php is the account/buyer plane and is MEANT to be served on the root
    // host — the IdP bulkhead must not catch it.
    $this->get('http://cboxid.com/api/v1/openapi.yaml')->assertOk();
});

it('serves the JSON liveness probe on every host', function (): void {
    saasShape();

    // Laravel's HTML health page used to shadow this (bootstrap/app.php `health: '/up'`),
    // and it loads a CDN script + remote fonts that the app's own CSP refuses.
    foreach (['http://cboxid.com/up', 'http://acme.cboxid.com/up', '/up'] as $url) {
        $response = $this->get($url)->assertOk();

        expect($response->headers->get('content-type'))->toContain('application/json');
        $response->assertExactJson(['status' => 'ok']);
    }
});
