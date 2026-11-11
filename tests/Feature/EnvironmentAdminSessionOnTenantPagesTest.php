<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
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
 * The console lives on the app host now, under its own host-only session; the tenant's own
 * pages (every app signs in through its /oauth/authorize) run on the environment's host
 * with a session of their own. Opening an app there used to sign the administrator out of
 * the console they came from; now it cannot reach that session at all.
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
    $console = (string) session(PlatformAuth::SESSION_KEY);

    // The administrator is nobody on this tenant, so the app's sign-in asks them to sign
    // in — on the environment's host, in that host's own session.
    hostJar('environment');
    authorizeRequest(['client_id' => $clientId, 'redirect_uri' => 'https://parcels.test/callback'])
        ->assertRedirect(route('login'));
    expect(session(PlatformAuth::SESSION_KEY))->toBeNull();

    // The console is still theirs afterwards.
    hostJar('console');
    expect(session(PlatformAuth::SESSION_KEY))->toBe($console);
    $this->get(route('environment.home'))->assertOk();
})->group('security');

it('still refuses an administrator session that has been revoked', function (): void {
    crudSetup();

    $sessionId = (string) session(PlatformAuth::SESSION_KEY);
    app(PlatformRoot::class)->run(fn () => app(SessionManager::class)->revoke($sessionId));

    // Only a LIVE administrator session opens the console; a revoked one is refused by the
    // console's own gate, which re-reads the row on every request.
    nextRequest();
    $this->get(route('environment.home'))->assertRedirect();
    expect(app(EnvironmentAdminAuth::class)->check())->toBeFalse();
})->group('security');
