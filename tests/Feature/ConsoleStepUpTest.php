<?php

declare(strict_types=1);

use App\Platform\EnvironmentSudo;
use Cbox\Id\Directory\Contracts\Directories;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\TokenVault\Contracts\SecretVault;
use Cbox\Id\TokenVault\Models\VaultSecret;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;
use Cbox\Id\Webhooks\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\PersistentMiddleware;

uses(RefreshDatabase::class);

/**
 * @group security
 *
 * The environment console's step-up, asserted where it actually runs.
 *
 * TWO THINGS WERE WRONG. `env.sudo` was applied to the token vault and to nothing else,
 * while three other environment-plane pages mint or reveal a live credential — an OAuth
 * app's secret, a directory's SCIM bearer token, a webhook's signing secret — on a plane
 * where `mayManage()` returns an unconditional true for every tenant in the environment.
 * And the vault's own gate had no test that could see it: every write test drove the
 * component through `Volt::test()`, which never touches the update endpoint, so
 * {@see PersistentMiddleware} short-circuits and NO route
 * middleware runs. Deleting `RequireEnvironmentSudo` from the persistent list turned
 * exactly one structural assertion red and left 52 write tests passing.
 *
 * So every test below goes through the REAL endpoint ({@see livewireUpdate()}), and each
 * asserts both directions: the write happens with the window open, and does not without.
 * A gate is only proven by the refusal.
 */
beforeEach(function (): void {
    ['envId' => $this->envId] = crudSetup();
});

/**
 * The vault, which had the gate but no test that ran it.
 *
 * The snapshot is captured WITH the window open and replayed after it closes, which is
 * both the only way to reach the page (its route middleware gates the GET too) and the
 * precise threat the persistent-middleware list exists for: a retained snapshot must not
 * keep working once the confirmation lapses, or the step-up is a one-time formality for
 * the life of the session.
 */
it('refuses a vault write on a snapshot whose step-up window has closed', function (): void {
    $secret = app(SecretVault::class)->store('K', 'stripe', 'sk_x');
    $url = route('environment.vault.show', ['secret' => $secret->id]);

    app(EnvironmentSudo::class)->confirm();
    $page = $this->get($url)->assertSuccessful();

    // The window closes — the administrator walks away, the 15 minutes lapse — while the
    // page, and the snapshot embedded in it, are still sitting in the browser.
    app(EnvironmentSudo::class)->forget();

    $snapshot = snapshotFor((string) $page->getContent(), 'console.vault.show');

    replaySnapshot($url, $snapshot, 'revoke')
        ->assertForbidden()
        ->assertJsonPath('sudo', route('environment.sudo'));

    expect(VaultSecret::query()->whereKey($secret->id)->value('revoked_at'))
        ->toBeNull('a replayed snapshot revoked a secret with no live step-up');
})->group('security');

it('allows a vault write while the step-up window is open', function (): void {
    $secret = app(SecretVault::class)->store('K', 'stripe', 'sk_x');

    app(EnvironmentSudo::class)->confirm();

    livewireUpdate(
        route('environment.vault.show', ['secret' => $secret->id]),
        'console.vault.show',
        'revoke',
    )->assertSuccessful();

    expect(VaultSecret::query()->whereKey($secret->id)->value('revoked_at'))->not->toBeNull();
})->group('security');

/**
 * And the three pages that had no gate at all.
 *
 * These are gated on the ACTION rather than the route, because each page is also where
 * ordinary, harmless detail is read — an app's redirect URIs, a directory's group
 * mappings, a webhook's deliveries — and a password prompt to read those is a gate people
 * learn to click through. So the page still serves; the button is what stops.
 */
it('refuses to rotate an app secret with no step-up, and rotates with one', function (): void {
    $client = app(ClientRegistry::class)->register(new NewClient(
        name: 'Tenant App',
        type: ClientType::Confidential,
        redirectUris: ['https://tenant.example/cb'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
    ))->client;

    $url = route('environment.clients.show', ['client' => $client->id]);
    $before = Client::query()->whereKey($client->id)->value('secret_hash');

    // Refused: sent to the step-up rather than answered with a credential.
    livewireUpdate($url, 'console.clients.show', 'rotateSecret')
        ->assertSuccessful()
        ->assertJsonPath('components.0.effects.redirect', route('environment.sudo'));

    expect(Client::query()->whereKey($client->id)->value('secret_hash'))
        ->toBe($before, 'an app secret was rotated with no step-up');

    app(EnvironmentSudo::class)->confirm();

    livewireUpdate($url, 'console.clients.show', 'rotateSecret')->assertSuccessful();

    expect(Client::query()->whereKey($client->id)->value('secret_hash'))->not->toBe($before);
})->group('security');

it('refuses to rotate a SCIM bearer token with no step-up, and rotates with one', function (): void {
    $org = app(Organizations::class)->create(new NewOrganization('Tenant', 'tenant-scim'));
    grantFeature($org->id, 'scim');
    $directory = app(Directories::class)->register($org->id, 'Okta')->directory;

    $url = route('environment.directories.show', ['directory' => $directory->id]);
    $before = $directory->bearer_token_hash;

    livewireUpdate($url, 'console.directories.show', 'regenerateToken')
        ->assertSuccessful()
        ->assertJsonPath('components.0.effects.redirect', route('environment.sudo'));

    expect($directory->fresh()?->bearer_token_hash)
        ->toBe($before, 'a live SCIM bearer token was minted with no step-up');

    app(EnvironmentSudo::class)->confirm();

    livewireUpdate($url, 'console.directories.show', 'regenerateToken')->assertSuccessful();

    expect($directory->fresh()?->bearer_token_hash)->not->toBe($before);
})->group('security');

it('refuses to rotate a webhook signing secret with no step-up, and rotates with one', function (): void {
    $org = app(Organizations::class)->create(new NewOrganization('Tenant', 'tenant-hook'));
    $endpoint = app(WebhookRegistry::class)->register(
        $org->id,
        'https://hooks.tenant.example/events',
        ['user.created'],
    )->endpoint;

    $url = route('environment.webhooks.show', ['webhook' => $endpoint->id]);
    $before = WebhookEndpoint::query()->whereKey($endpoint->id)->value('secret_encrypted');

    livewireUpdate($url, 'console.webhooks.show', 'rotateSecret')
        ->assertSuccessful()
        ->assertJsonPath('components.0.effects.redirect', route('environment.sudo'));

    expect(WebhookEndpoint::query()->whereKey($endpoint->id)->value('secret_encrypted'))
        ->toBe($before, 'a webhook signing secret was rotated with no step-up');

    app(EnvironmentSudo::class)->confirm();

    livewireUpdate($url, 'console.webhooks.show', 'rotateSecret')->assertSuccessful();

    expect(WebhookEndpoint::query()->whereKey($endpoint->id)->value('secret_encrypted'))
        ->not->toBe($before);
})->group('security');
