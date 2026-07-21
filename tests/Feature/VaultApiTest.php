<?php

declare(strict_types=1);

use Cbox\Id\OAuthServer\Contracts\TokenIntrospector;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Cbox\Id\TokenVault\Contracts\SecretVault;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A fake resource-server introspector: it maps opaque test tokens to a client id
 * and scopes, so the API's auth path runs for real without minting signed JWTs.
 */
beforeEach(function (): void {
    $this->app->instance(TokenIntrospector::class, new class implements TokenIntrospector
    {
        public function introspect(string $token): Introspection
        {
            return match ($token) {
                'manage-tok' => Introspection::active(null, 'issuer-client', ['vault.manage'], []),
                'agent-tok' => Introspection::active(null, 'agent-1', ['vault.lease'], []),
                'other-agent-tok' => Introspection::active(null, 'agent-2', ['vault.lease'], []),
                'noscope-tok' => Introspection::active(null, 'issuer-client', [], []),
                'wrong-aud-tok' => Introspection::active(null, 'issuer-client', ['vault.manage'], ['aud' => 'https://mcp.example.com']),
                default => Introspection::inactive(),
            };
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
        'client_id' => 'agent-1',
    ], bearer('manage-tok'))->assertStatus(201)
        ->assertJson(['secret_id' => $id, 'client_id' => 'agent-1']);

    // The granted agent can lease the plaintext.
    $this->postJson("/api/v1/vault/secrets/{$id}/lease", [
        'purpose' => 'call openai',
    ], bearer('agent-tok'))->assertStatus(200)
        ->assertJson(['provider' => 'openai', 'secret' => 'sk-live-secret']);
});

it('denies a lease to an agent with no grant, uniformly', function (): void {
    $secret = app(SecretVault::class)->store('stripe', 'stripe', 'sk-stripe');

    // agent-2 was never granted this secret.
    $this->postJson("/api/v1/vault/secrets/{$secret->id}/lease", [
        'purpose' => 'charge',
    ], bearer('other-agent-tok'))
        ->assertStatus(403)
        ->assertExactJson(['error' => 'lease_denied']);
});

it('denies a lease after the grant is revoked', function (): void {
    $secret = app(SecretVault::class)->store('gh', 'github', 'ghp_x');

    $this->postJson("/api/v1/vault/secrets/{$secret->id}/grants", [
        'client_id' => 'agent-1',
    ], bearer('manage-tok'))->assertStatus(201);

    $this->postJson("/api/v1/vault/secrets/{$secret->id}/lease", [
        'purpose' => 'x',
    ], bearer('agent-tok'))->assertStatus(200);

    $this->deleteJson("/api/v1/vault/secrets/{$secret->id}/grants/agent-1", [], bearer('manage-tok'))
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
