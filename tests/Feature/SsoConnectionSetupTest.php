<?php

declare(strict_types=1);

use App\Platform\AdminPortal;
use App\Platform\Enums\PortalIntent;
use App\Platform\Enums\PortalScope;
use App\Platform\PlatformAuth;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FormOrganization;

/*
|--------------------------------------------------------------------------
| Setting a connection up: what an administrator has to bring, and what they get to paste.
|--------------------------------------------------------------------------
|
| AN OIDC PROVIDER NEEDS NO PASTED KEY. Its ID tokens are verified against the key set its
| discovery document publishes (`jwks_uri`), which the save already reads — the "signing
| key" the forms used to require was a second copy of something the provider serves, and
| one that went stale at its first rotation. Proven end to end here with real RS256 crypto
| against a faked JWKS endpoint: a connection made from an issuer, a client id and a secret
| completes, activates and signs a person in — and a token signed with any other key is
| refused.
|
| A SAML CONNECTION HAS A METADATA URL, and it works on a draft: an identity provider that
| imports SP metadata wants it before it hands out its own half.
*/

/** @return string a management key in the test environment holding $scopes */
function setupKey(array $scopes = ['sso:read', 'sso:write']): string
{
    return app(EnvironmentApiKeys::class)->issue('env_test', 'SSO setup', $scopes)->plaintext;
}

function setupOrg(): string
{
    return app(Organizations::class)->create(new NewOrganization('Acme', 'acme-setup-'.Str::lower(Str::random(4))))->id;
}

/** The provider's discovery document — with a key set, unless $jwks is false. */
function fakeIdpDiscovery(bool $jwks = true): void
{
    config(['cbox-id.federation.verify_url' => false]);

    Http::fake([
        'idp.setup.example/.well-known/openid-configuration' => Http::response(array_filter([
            'issuer' => 'https://idp.setup.example',
            'authorization_endpoint' => 'https://idp.setup.example/authorize',
            'token_endpoint' => 'https://idp.setup.example/token',
            'jwks_uri' => $jwks ? 'https://idp.setup.example/jwks' : null,
        ])),
        'api.pwnedpasswords.com/*' => Http::response('', 200),
    ]);
}

/**
 * A fresh RSA key: its JWK Set, and a signer for id_tokens.
 *
 * @return array{jwks: array<string, mixed>, sign: Closure(array<string, mixed>): string}
 */
function idpSigningKey(string $kid = 'idp-key'): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $details = openssl_pkey_get_details($key);
    $b64 = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

    return [
        'jwks' => ['keys' => [[
            'kty' => 'RSA', 'kid' => $kid, 'use' => 'sig', 'alg' => 'RS256',
            'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e']),
        ]]],
        'sign' => static fn (array $claims): string => JWT::encode($claims, $pem, 'RS256', $kid),
    ];
}

/** An OIDC connection made the way the forms now ask for it: issuer, client id, secret. */
function oidcWithoutKey(string $key, string $org): string
{
    return (string) test()->withToken($key)->postJson('/api/v1/sso/connections', [
        'organization_id' => $org,
        'name' => 'Setup IdP',
        'type' => 'oidc',
        'issuer' => 'https://idp.setup.example',
        'client_id' => 'setup-client',
        'client_secret' => 'setup-secret',
    ])->assertCreated()->json('data.id');
}

/**
 * Start a sign-in through $connection for real, then arm the provider: a token endpoint
 * answering $idToken (built with the nonce the application issued) and the JWKS endpoint
 * answering $jwks. Returns the callback URL.
 *
 * @param  Closure(string): string  $idToken  the id_token, given the nonce
 * @param  array<string, mixed>  $jwks
 */
function oidcSignInInProgress(string $connection, Closure $idToken, array $jwks): string
{
    $location = (string) test()->get("/sso/oidc/{$connection}/redirect")->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://idp.setup.example/authorize?');

    Http::fake([
        'idp.setup.example/token' => Http::response(['id_token' => $idToken((string) $query['nonce'])]),
        'idp.setup.example/jwks' => Http::response($jwks),
    ]);

    return "/sso/oidc/{$connection}/callback?".http_build_query(['state' => $query['state'], 'code' => 'the-code']);
}

/** @return array<string, mixed> */
function idTokenClaims(string $nonce, array $changes = []): array
{
    return [
        'iss' => 'https://idp.setup.example',
        'aud' => 'setup-client',
        'sub' => 'idp|dana',
        'email' => 'dana@setup.example',
        'name' => 'Dana',
        'nonce' => $nonce,
        'iat' => time(),
        'exp' => time() + 300,
        ...$changes,
    ];
}

// ── OIDC without a pasted key ────────────────────────────────────────────────

it('completes and activates an OIDC connection from an issuer, a client id and a secret alone', function (): void {
    fakeIdpDiscovery();
    $key = setupKey();
    $id = oidcWithoutKey($key, setupOrg());

    $this->withToken($key)->getJson("/api/v1/sso/connections/{$id}")
        ->assertOk()
        ->assertJsonPath('data.complete', true)
        ->assertJsonPath('data.service_provider.redirect_uri', route('sso.oidc.callback', $id));

    $config = app(Connections::class)->config(Connection::query()->findOrFail($id));

    expect($config['jwks_uri'] ?? null)->toBe('https://idp.setup.example/jwks')
        ->and($config)->not->toHaveKey('signing_key');

    $this->withToken($key)->postJson("/api/v1/sso/connections/{$id}/activate")->assertOk()->assertJsonPath('data.active', true);
});

it('signs a person in through it with an id_token verified against the provider\'s published keys', function (): void {
    fakeIdpDiscovery();
    $id = oidcWithoutKey(setupKey(), $org = setupOrg());
    app(Connections::class)->activate($org, $id);
    $this->flushHeaders();

    $idp = idpSigningKey();
    $callback = oidcSignInInProgress($id, fn (string $nonce): string => ($idp['sign'])(idTokenClaims($nonce)), $idp['jwks']);

    $response = $this->get($callback)->assertRedirect();

    expect($response->headers->get('Location'))->not->toContain('/login')
        ->and(session(PlatformAuth::SESSION_KEY))->not->toBeNull()
        ->and(app(Subjects::class)->findByEmail('dana@setup.example'))->not->toBeNull();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://idp.setup.example/jwks');
})->group('security');

it('refuses an id_token signed with any key the provider does not publish', function (): void {
    fakeIdpDiscovery();
    $id = oidcWithoutKey(setupKey(), $org = setupOrg());
    app(Connections::class)->activate($org, $id);
    $this->flushHeaders();

    $published = idpSigningKey();
    // Same kid, same claims, same nonce — another key.
    $forger = idpSigningKey();
    $callback = oidcSignInInProgress($id, fn (string $nonce): string => ($forger['sign'])(idTokenClaims($nonce)), $published['jwks']);

    $response = $this->get($callback)->assertRedirect();

    expect($response->headers->get('Location'))->toContain('/login')
        ->and(session(PlatformAuth::SESSION_KEY))->toBeNull()
        ->and(app(Subjects::class)->findByEmail('dana@setup.example'))->toBeNull();
})->group('security');

it('asks for a signing key only of a provider that publishes none', function (): void {
    fakeIdpDiscovery(jwks: false);
    $key = setupKey();
    $org = setupOrg();
    $body = [
        'organization_id' => $org,
        'name' => 'No JWKS',
        'type' => 'oidc',
        'issuer' => 'https://idp.setup.example',
        'client_id' => 'setup-client',
        'client_secret' => 'setup-secret',
    ];

    $this->withToken($key)->postJson('/api/v1/sso/connections', $body)
        ->assertUnprocessable()
        ->assertJsonPath('error', 'incomplete_connection')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'jwks_uri'));

    expect(Connection::query()->count())->toBe(0);

    $this->withToken($key)->postJson('/api/v1/sso/connections', [...$body, 'signing_key' => "-----BEGIN PUBLIC KEY-----\nMIIB\n-----END PUBLIC KEY-----"])
        ->assertCreated()
        ->assertJsonPath('data.complete', true);
});

it('takes an OIDC connection without a signing key from the console form', function (): void {
    // The console's form: the key is no longer a required field.
    crudSetup();
    FormOrganization::$id = app(Organizations::class)->create(new NewOrganization('Form Co', 'form-co-oidc'))->id;
    fakeIdpDiscovery();

    createConnection([
        'type' => 'oidc',
        'name' => 'Form OIDC',
        'issuer' => 'https://idp.setup.example',
        'client_id' => 'setup-client',
        'client_secret' => 'setup-secret',
        'signing_key' => '',
    ], 'environment.connections')->assertSessionHasNoErrors();

    expect(Connection::query()->where('name', 'Form OIDC')->exists())->toBeTrue();
});

it('completes an OIDC connection in the Admin Portal from the three values its copy asks for', function (): void {
    fakeIdpDiscovery();
    $org = setupOrg();
    $token = app(AdminPortal::class)->generate($org, PortalScope::only(PortalIntent::Sso), 'sub_creator');
    $this->post(route('portal.enter.store', $token))->assertRedirect(route('portal.setup'));

    $this->post(route('portal.connections.store'), ['provider' => 'oidc'])->assertSessionHasNoErrors();
    $connection = Connection::query()->where('organization_id', $org)->sole();

    $this->patch(route('portal.connections.update', $connection->id), [
        'issuer' => 'https://idp.setup.example',
        'client_id' => 'setup-client',
        'client_secret' => 'setup-secret',
    ])->assertSessionHasNoErrors();

    $this->get(route('portal.sso', ['provider' => 'oidc']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('connections.0.complete', true));

    $this->post(route('portal.connections.activate', $connection->id))->assertSessionHasNoErrors();

    expect($connection->refresh()->isActive())->toBeTrue();
});

// ── The SAML metadata URL ────────────────────────────────────────────────────

it('serves a SAML draft\'s SP metadata before the identity provider\'s half is known, and says where', function (): void {
    $key = setupKey();

    $draft = $this->withToken($key)->postJson('/api/v1/sso/connections', [
        'organization_id' => setupOrg(),
        'name' => 'Okta',
        'type' => 'saml',
        'pending_idp' => true,
    ])->assertCreated()->json('data');

    $url = $draft['service_provider']['sp_metadata_url'];

    expect($url)->toBe(url('/sso/saml/'.$draft['id'].'/metadata'));

    $xml = (string) $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/samlmetadata+xml')->getContent();

    expect($xml)->toContain('entityID="'.$draft['service_provider']['sp_entity_id'].'"')
        ->and($xml)->toContain('Location="'.$draft['service_provider']['sp_acs_url'].'"');

    // Where it is served from, not a setting: the sealed config does not carry it.
    $config = app(Connections::class)->config(Connection::query()->findOrFail($draft['id']));

    expect($config)->not->toHaveKey('sp_metadata_url');

    // An OIDC connection, or an id that is not one, has no SAML metadata — and the IdP-role
    // metadata at the literal `idp` segment is not shadowed by the connection route.
    $this->get('/sso/saml/01JQZZZZZZZZZZZZZZZZZZZZZZ/metadata')->assertNotFound();
    expect($this->get('/sso/saml/idp/metadata')->getContent())->not->toBe('Unknown SAML connection.');
});

it('shows the SP metadata URL in the Admin Portal\'s step 2 and on the console\'s connection page', function (): void {
    $org = setupOrg();
    $token = app(AdminPortal::class)->generate($org, PortalScope::only(PortalIntent::Sso), 'sub_creator');
    $this->post(route('portal.enter.store', $token));
    $this->post(route('portal.connections.store'), ['provider' => 'pingfederate'])->assertSessionHasNoErrors();
    $connection = Connection::query()->where('organization_id', $org)->sole();
    $metadata = route('sso.saml.metadata', $connection->id);

    $this->get(route('portal.sso', ['provider' => 'pingfederate']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('connections.0.values.sp_metadata_url', $metadata)
            // PingFederate takes it by URL, under its own name for the field and where it sits.
            ->where('guides', fn ($guides): bool => collect($guides)->toArray()[array_search('pingfederate', array_column(collect($guides)->toArray(), 'key'), true)]['fields'][0] === [
                'ours' => 'sp_metadata_url',
                'theirs' => 'Metadata URL',
                'optional' => true,
                'location' => 'Import Metadata → URL (add it under Manage Partner Metadata URLs)',
            ]));

    crudSetup();
    $mine = app(Connections::class)->create(null, ConnectionType::Saml, 'Env SAML', []);

    $this->get(route('environment.connections.show', $mine->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('serviceProvider.sp_metadata_url', route('sso.saml.metadata', $mine->id)));
});
