<?php

declare(strict_types=1);

use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
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
        ->assertRedirect(route('signed-out'));
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
