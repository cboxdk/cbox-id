<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * AN ENVIRONMENT ADMINISTRATOR'S SESSION SURVIVES THE TENANT'S OWN PAGES.
 *
 * Their session is a platform-root one anchored to this host, so the subject middleware —
 * which reads under the tenant's scope — never finds it, and used to forget it. The first
 * app an administrator opened from the environment host (every app signs in through
 * /oauth/authorize) signed them out of the console they had come from.
 */
function firstPartyEnvironmentApp(): string
{
    return app(ClientRegistry::class)->register(new NewClient(
        name: 'Parcels',
        type: ClientType::Confidential,
        redirectUris: ['https://parcels.test/callback'],
        grantTypes: ['authorization_code'],
        scopes: ['openid', 'email'],
        firstParty: true,
    ))->client->client_id;
}

it('keeps the console session when the administrator\'s browser opens an app', function (): void {
    crudSetup();
    $clientId = firstPartyEnvironmentApp();

    $this->get(route('environment.home'))->assertOk();

    // The administrator is nobody on this tenant, so the app's sign-in asks them to sign
    // in — as before. What changes is that the console is still theirs afterwards.
    authorizeRequest(['client_id' => $clientId, 'redirect_uri' => 'https://parcels.test/callback'])
        ->assertRedirect(route('login'));

    expect(session(PlatformAuth::SESSION_KEY))->toBeString();
    $this->get(route('environment.home'))->assertOk();
})->group('security');

it('still forgets an administrator session that has been revoked', function (): void {
    crudSetup();
    $clientId = firstPartyEnvironmentApp();

    $sessionId = (string) session(PlatformAuth::SESSION_KEY);
    app(PlatformRoot::class)->run(fn () => app(SessionManager::class)->revoke($sessionId));

    authorizeRequest(['client_id' => $clientId, 'redirect_uri' => 'https://parcels.test/callback']);

    // Only a LIVE administrator session is spared; a revoked one is gone, as a revoked
    // subject session always was.
    expect(session(PlatformAuth::SESSION_KEY))->toBeNull();
})->group('security');
