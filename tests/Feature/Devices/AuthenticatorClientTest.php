<?php

declare(strict_types=1);

use Cbox\Id\Devices\Support\AuthenticatorClient;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('provisions a public first-party client with the device scopes', function (): void {
    $this->artisan('cbox-id:devices:client')->assertSuccessful();

    $client = AuthenticatorClient::find();

    expect($client)->toBeInstanceOf(Client::class)
        // Public: a mobile binary cannot keep a secret, so it holds none.
        ->and($client?->type)->toBe(ClientType::Public)
        // First-party, so enrolling the platform's own authenticator does not ask the
        // user to consent to an app the platform ships.
        ->and((bool) $client?->first_party)->toBeTrue()
        ->and($client?->scopes)->toContain('devices.manage')
        ->and($client?->scopes)->toContain('approvals.write')
        // Without offline_access the token endpoint issues no refresh token at all.
        ->and($client?->scopes)->toContain('offline_access');
});

it('registers both a claimed HTTPS and a reverse-domain redirect URI', function (): void {
    $this->artisan('cbox-id:devices:client', ['--host' => 'acme.cboxid.com'])->assertSuccessful();

    // RFC 8252 §7.2 prefers the HTTPS form because a private-use scheme can be squatted
    // by another app on the handset; both are registered so the deep-link spike's
    // outcome is a config change rather than a re-registration.
    expect(AuthenticatorClient::find()?->redirect_uris)
        ->toContain('https://acme.cboxid.com/app/oauth/callback')
        ->toContain('dk.cbox.id.authenticator:/oauth/callback');
});

it('is idempotent so re-running never strands enrolled handsets', function (): void {
    $this->artisan('cbox-id:devices:client')->assertSuccessful();
    $first = AuthenticatorClient::find()?->client_id;

    $this->artisan('cbox-id:devices:client')->assertSuccessful();

    // A second client would silently orphan every device that enrolled against the first.
    expect(Client::query()->where('name', AuthenticatorClient::NAME)->count())->toBe(1)
        ->and(AuthenticatorClient::find()?->client_id)->toBe($first);
});

it('serves the bootstrap document once provisioned', function (): void {
    config()->set('id-devices.enabled', true);
    $this->artisan('cbox-id:devices:client', ['--host' => 'acme.cboxid.com'])->assertSuccessful();

    $response = $this->getJson('/.well-known/cbox-authenticator')->assertOk();

    // The app discovers its per-environment client_id here, because oauth_clients
    // .client_id is globally unique and cannot be compiled into one binary.
    expect($response->json('client_id'))->toBe(AuthenticatorClient::find()?->client_id)
        ->and($response->json('scopes'))->toBe(AuthenticatorClient::SCOPES)
        ->and($response->json('api_base'))->toEndWith('/api/v1');
});

it('answers 404 the same way whether unprovisioned or disabled', function (): void {
    config()->set('id-devices.enabled', true);

    $unprovisioned = $this->getJson('/.well-known/cbox-authenticator')->assertStatus(404);

    config()->set('id-devices.enabled', false);
    $this->artisan('cbox-id:devices:client')->assertSuccessful();

    $disabled = $this->getJson('/.well-known/cbox-authenticator')->assertStatus(404);

    // Probing hosts must not reveal whether the feature exists but is unconfigured.
    expect($disabled->json())->toBe($unprovisioned->json());
});

it('never exposes a client secret', function (): void {
    config()->set('id-devices.enabled', true);
    $this->artisan('cbox-id:devices:client')->assertSuccessful();

    $body = $this->getJson('/.well-known/cbox-authenticator')->assertOk()->json();

    expect($body)->not->toHaveKey('client_secret')
        ->and(json_encode($body))->not->toContain('secret');
});
