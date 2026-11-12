<?php

declare(strict_types=1);

use App\Platform\AppCatalog\AppCatalog;
use App\Platform\AppCatalog\CatalogEntryData;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Inertia\Testing\AssertableInertia;

/**
 * RP-initiated logout with nowhere accepted to go used to answer one line of plain text.
 * Every app signs out through this endpoint, so it ends on the hosted signed-out page.
 */
beforeEach(function (): void {
    installedDeployment();
    // As in production. Under app.debug the framework explains a dropped redirect in the body.
    config(['app.debug' => false]);
});

it('ends an end-session request without a redirect on the hosted signed-out page', function (): void {
    $this->get('/oauth/logout')
        ->assertStatus(303)
        ->assertRedirect(route('signed-out'))
        ->assertHeader('Cache-Control', 'no-store, private');

    $this->get(route('signed-out'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/signed-out')->has('signInUrl'));
});

it('does the same for the POST binding and for a redirect that is not registered', function (): void {
    $clientId = app(ClientRegistry::class)->register(new NewClient(
        'Cbox Billing',
        ClientType::Public,
        redirectUris: ['https://billing.example.com/auth/callback'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
        postLogoutRedirectUris: ['https://billing.example.com/signed-out'],
    ))->client->client_id;

    $this->post('/oauth/logout', ['client_id' => $clientId, 'post_logout_redirect_uri' => 'https://evil.example/'])
        ->assertStatus(303)
        // The page is told which app it was, by id: it offers that app's own home.
        ->assertRedirect(route('signed-out', ['app' => $clientId]));
});

it('still sends the browser to a registered post-logout address', function (): void {
    $clientId = app(ClientRegistry::class)->register(new NewClient(
        'Cbox Billing',
        ClientType::Public,
        redirectUris: ['https://billing.example.com/auth/callback'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
        postLogoutRedirectUris: ['https://billing.example.com/signed-out'],
    ))->client->client_id;

    $this->get('/oauth/logout?'.http_build_query([
        'client_id' => $clientId,
        'post_logout_redirect_uri' => 'https://billing.example.com/signed-out',
        'state' => 'abc',
    ]))->assertRedirect('https://billing.example.com/signed-out?state=abc');
});

it('leaves the framework\'s explanation alone for a developer under app.debug', function (): void {
    config(['app.debug' => true]);

    $this->get('/oauth/logout?'.http_build_query(['post_logout_redirect_uri' => 'https://nowhere.example/']))
        ->assertOk()
        ->assertSee('post_logout_redirect_uri was ignored');
});

it('offers the way back to the app the person signed out of, and to no other address', function (): void {
    $register = fn (string $name, ?string $organizationId = null) => app(ClientRegistry::class)->register(new NewClient(
        $name,
        ClientType::Public,
        redirectUris: ['https://billing.example.com/auth/callback'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
        organizationId: $organizationId,
    ))->client->client_id;

    $clientId = $register('Cbox Billing');

    app(AppCatalog::class)->save('billing', CatalogEntryData::fromArray([
        'version' => 0, 'name' => 'Cbox Billing', 'launch_url' => 'https://billing.example.com/launch',
        'enabled' => true, 'position' => 1, 'audience' => 'everyone', 'client_ids' => [$clientId],
    ]));

    // Named by client_id; the address the request asked for is not registered and is dropped.
    $this->get('/oauth/logout?'.http_build_query(['client_id' => $clientId, 'post_logout_redirect_uri' => 'https://evil.example/']))
        ->assertStatus(303)
        ->assertRedirect(route('signed-out', ['app' => $clientId]));

    // Named by the audience of the id token the app sent along.
    $hint = 'e30.'.rtrim(strtr(base64_encode((string) json_encode(['aud' => $clientId])), '+/', '-_'), '=').'.sig';
    $this->get('/oauth/logout?'.http_build_query(['id_token_hint' => $hint]))
        ->assertRedirect(route('signed-out', ['app' => $clientId]));

    $this->get(route('signed-out', ['app' => $clientId]))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('backToApp.url', 'https://billing.example.com/launch')
            ->where('backToApp.label', 'Back to Cbox Billing'));

    // An organization's own app, an unknown id and an address are not followed.
    $organization = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-signed-out'));
    $theirs = $register('Their app', $organization->id);

    $this->get('/oauth/logout?'.http_build_query(['client_id' => $theirs]))->assertRedirect(route('signed-out'));

    foreach ([$theirs, 'cid_nobody', 'https://evil.example/'] as $named) {
        $this->get(route('signed-out', ['app' => $named]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('backToApp', null));
    }
});
