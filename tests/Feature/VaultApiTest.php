<?php

declare(strict_types=1);

use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\TokenIntrospector;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\TokenVault\Contracts\SecretVault;
use Cbox\Id\TokenVault\ValueObjects\VaultOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A fake resource-server introspector: it maps opaque test tokens to a client id
 * and scopes, so the API's auth path runs for real without minting signed JWTs.
 *
 * The client ids are REAL apps registered here, because the vault decides whose secrets
 * a token reaches from the app it was issued to: an unknown app reaches nothing.
 */
beforeEach(function (): void {
    $register = fn (string $name, ?string $organizationId = null): string => app(ClientRegistry::class)
        ->register(new NewClient($name, organizationId: $organizationId))->client->client_id;

    $this->acme = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-vault'));
    $this->globex = app(Organizations::class)->create(new NewOrganization('Globex', 'globex-vault'));

    $this->issuer = $register('Issuer');
    $this->agent1 = $register('Agent one');
    $this->agent2 = $register('Agent two');
    $this->acmeApp = $register('Acme backend', $this->acme->id);

    $tokens = [
        'manage-tok' => Introspection::active(null, $this->issuer, ['vault.manage'], []),
        'agent-tok' => Introspection::active(null, $this->agent1, ['vault.lease'], []),
        'other-agent-tok' => Introspection::active(null, $this->agent2, ['vault.lease'], []),
        'noscope-tok' => Introspection::active(null, $this->issuer, [], []),
        'wrong-aud-tok' => Introspection::active(null, $this->issuer, ['vault.manage'], ['aud' => 'https://mcp.example.com']),
        'unknown-app-tok' => Introspection::active(null, 'no-such-app', ['vault.manage'], []),
        // Acme's own app, machine token: bound to Acme.
        'acme-app-tok' => Introspection::active(null, $this->acmeApp, ['vault.manage'], ['org' => $this->acme->id]),
        // Acme's app, used by a member of GLOBEX who consented to it: the token's `org`
        // is the person's organization, not the app's.
        'acme-app-globex-user-tok' => Introspection::active('sub-globex', $this->acmeApp, ['vault.manage'], ['org' => $this->globex->id]),
        // The environment's own app, acting for a person in Globex.
        'issuer-globex-user-tok' => Introspection::active('sub-globex', $this->issuer, ['vault.manage'], ['org' => $this->globex->id]),
    ];

    $this->app->instance(TokenIntrospector::class, new readonly class($tokens) implements TokenIntrospector
    {
        /** @param  array<string, Introspection>  $tokens */
        public function __construct(private array $tokens) {}

        public function introspect(string $token): Introspection
        {
            return $this->tokens[$token] ?? Introspection::inactive();
        }

        public function revoke(string $jti): void {}
    });
});

function bearer(string $token): array
{
    return ['Authorization' => "Bearer {$token}"];
}

it('rejects a request with no token', function (): void {
    $this->postJson('/api/v1/vault/secrets', [])
        ->assertStatus(401)
        ->assertHeader('WWW-Authenticate');
});

it('rejects a token missing the required scope', function (): void {
    $this->postJson('/api/v1/vault/secrets', [
        'name' => 'openai', 'provider' => 'openai', 'secret' => 'sk-x',
    ], bearer('noscope-tok'))
        ->assertStatus(403)
        ->assertJson(['error' => 'insufficient_scope']);
});

it('rejects a token audienced for another resource (RFC 8707)', function (): void {
    // The token is valid and scoped, but minted for a different resource server —
    // it must not be replayable against this first-party API.
    $this->postJson('/api/v1/vault/secrets', [
        'name' => 'openai', 'provider' => 'openai', 'secret' => 'sk-x',
    ], bearer('wrong-aud-tok'))
        ->assertStatus(401)
        ->assertJson(['error' => 'invalid_token']);
});

it('stores, grants, and leases a secret end to end', function (): void {
    // Provision with a manage token.
    $created = $this->postJson('/api/v1/vault/secrets', [
        'name' => 'openai',
        'provider' => 'openai',
        'secret' => 'sk-live-secret',
    ], bearer('manage-tok'))->assertStatus(201)->json();

    expect($created['provider'])->toBe('openai')
        ->and($created)->not->toHaveKey('secret'); // never echoes the plaintext

    $id = $created['id'];

    // Grant the agent client.
    $this->postJson("/api/v1/vault/secrets/{$id}/grants", [
        'client_id' => $this->agent1,
    ], bearer('manage-tok'))->assertStatus(201)
        ->assertJson(['secret_id' => $id, 'client_id' => $this->agent1]);

    // The granted agent can lease the plaintext.
    $this->postJson("/api/v1/vault/secrets/{$id}/lease", [
        'purpose' => 'call openai',
    ], bearer('agent-tok'))->assertStatus(200)
        ->assertJson(['provider' => 'openai', 'secret' => 'sk-live-secret']);
});

it('denies a lease to an agent with no grant, uniformly', function (): void {
    $secret = app(SecretVault::class)->store('stripe', 'stripe', 'sk-stripe');

    // agent-2 was never granted this secret.
    // The API's one error envelope, and NOTHING else: the message is a constant, so a
    // refusal for "no grant" is byte-identical to one for "no such secret" — the
    // no-enumeration property survives carrying `message`.
    $this->postJson("/api/v1/vault/secrets/{$secret->id}/lease", [
        'purpose' => 'charge',
    ], bearer('other-agent-tok'))
        ->assertStatus(403)
        ->assertJsonPath('error', 'lease_denied')
        ->assertJsonPath('message', 'The lease was denied.')
        // Those two and the request's own id, nothing else.
        ->assertJsonCount(3);

    // …and a lease against a secret that does not exist at all is the same response.
    $this->postJson('/api/v1/vault/secrets/does-not-exist/lease', [
        'purpose' => 'charge',
    ], bearer('other-agent-tok'))
        ->assertStatus(403)
        ->assertJsonPath('error', 'lease_denied')
        ->assertJsonPath('message', 'The lease was denied.')
        // Those two and the request's own id, nothing else.
        ->assertJsonCount(3);
});

it('denies a lease after the grant is revoked', function (): void {
    $secret = app(SecretVault::class)->store('gh', 'github', 'ghp_x');

    $this->postJson("/api/v1/vault/secrets/{$secret->id}/grants", [
        'client_id' => $this->agent1,
    ], bearer('manage-tok'))->assertStatus(201);

    $this->postJson("/api/v1/vault/secrets/{$secret->id}/lease", [
        'purpose' => 'x',
    ], bearer('agent-tok'))->assertStatus(200);

    $this->deleteJson("/api/v1/vault/secrets/{$secret->id}/grants/{$this->agent1}", [], bearer('manage-tok'))
        ->assertStatus(204);

    $this->postJson("/api/v1/vault/secrets/{$secret->id}/lease", [
        'purpose' => 'x',
    ], bearer('agent-tok'))->assertStatus(403);
});

it('returns 404 rotating an unknown secret', function (): void {
    $this->postJson('/api/v1/vault/secrets/nope/rotate', [
        'secret' => 'x',
    ], bearer('manage-tok'))->assertStatus(404)->assertJson(['error' => 'not_found']);
});

it('validates the store payload', function (): void {
    $this->postJson('/api/v1/vault/secrets', [
        'provider' => 'openai',
    ], bearer('manage-tok'))->assertStatus(422);
});

/*
 * WHOSE VAULT A TOKEN REACHES IS THE APP'S DECISION, NOT THE TOKEN'S `org` CLAIM.
 *
 * The owner used to be the `org` claim and nothing else. For a user-delegated token that
 * claim is the person's organization, so a plain member of Globex consenting to Acme's
 * app gave Acme's app rotate/revoke/grant over Globex's vault.
 */
it('keeps an organization\'s app inside that organization\'s vault', function (): void {
    $created = $this->postJson('/api/v1/vault/secrets', [
        'name' => 'stripe', 'provider' => 'stripe', 'secret' => 'sk-acme',
    ], bearer('acme-app-tok'))->assertStatus(201)->json();

    expect($created['owner_type'])->toBe('organization')
        ->and($created['owner_id'])->toBe($this->acme->id);
})->group('security');

it('refuses an organization\'s app a token that names another organization', function (): void {
    $globexSecret = app(SecretVault::class)->store('gh', 'github', 'ghp_globex', VaultOwner::organization($this->globex->id));

    $this->postJson("/api/v1/vault/secrets/{$globexSecret->id}/rotate", [
        'secret' => 'attacker-chosen',
    ], bearer('acme-app-globex-user-tok'))->assertStatus(403);

    $this->postJson('/api/v1/vault/secrets', [
        'name' => 'planted', 'provider' => 'x', 'secret' => 'x',
    ], bearer('acme-app-globex-user-tok'))->assertStatus(403);

    $this->postJson("/api/v1/vault/secrets/{$globexSecret->id}/grants", [
        'client_id' => $this->acmeApp,
    ], bearer('acme-app-globex-user-tok'))->assertStatus(403);
})->group('security');

it('refuses a token whose app this environment does not know', function (): void {
    $this->postJson('/api/v1/vault/secrets', [
        'name' => 'x', 'provider' => 'x', 'secret' => 'x',
    ], bearer('unknown-app-tok'))->assertStatus(403);
})->group('security');

it('lets the environment\'s own app act in the organization its token names', function (): void {
    $created = $this->postJson('/api/v1/vault/secrets', [
        'name' => 'slack', 'provider' => 'slack', 'secret' => 'xoxb',
    ], bearer('issuer-globex-user-tok'))->assertStatus(201)->json();

    expect($created['owner_id'])->toBe($this->globex->id);
});

it('keeps a machine token of the environment\'s own app in the environment\'s unowned secrets', function (): void {
    $created = $this->postJson('/api/v1/vault/secrets', [
        'name' => 'openai', 'provider' => 'openai', 'secret' => 'sk',
    ], bearer('manage-tok'))->assertStatus(201)->json();

    expect($created['owner_type'])->toBeNull()
        ->and($created['owner_id'])->toBeNull();
});
