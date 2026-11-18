<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\RelyingParties;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;

/**
 * The public website on the apex host, in LOGIN-HOST MODE as production runs it: sign-in
 * and the issuer on LOGIN_HOST, the console on APP_HOST, a tenant on TENANT_HOST
 * (`loginHostMode()` and the host names live in tests/Pest.php). The website's links
 * and redirects go to the login host, the only one that serves /login, /start and /oauth/*.
 */
const MARKETING_HOST = 'cboxid.test';

beforeEach(function (): void {
    $this->tenant = loginHostMode();
    config([
        'app.url' => 'https://'.APP_HOST,
        'marketing.host' => MARKETING_HOST,
        'marketing.app_url' => 'https://'.LOGIN_HOST,
    ]);
});

it('serves a public website without a login session and links to the login host', function (): void {
    $response = $this->get('https://'.MARKETING_HOST.'/')->assertOk()
        ->assertSee('One identity layer.')
        ->assertSee('https://'.LOGIN_HOST.'/login')
        ->assertSee('https://'.LOGIN_HOST.'/start')
        ->assertDontSee('https://'.APP_HOST.'/login')
        ->assertHeader('X-Frame-Options', 'DENY');

    expect($response->headers->getCookies())->toBeEmpty();
    expect($response->headers->get('Cache-Control'))->toContain('public');
});

it('links to pages the login host serves', function (): void {
    $this->get('https://'.LOGIN_HOST.'/login')->assertOk()->assertDontSee('One identity layer.');
    $this->get('https://'.LOGIN_HOST.'/start')->assertOk()->assertDontSee('One identity layer.');
});

it('moves old interactive URLs to the login host without losing query parameters', function (): void {
    $this->get('https://'.MARKETING_HOST.'/login?return_to=%2Faccount&mode=passkey')
        ->assertStatus(307)
        ->assertRedirect('https://'.LOGIN_HOST.'/login?return_to=%2Faccount&mode=passkey')
        ->assertHeader('Cache-Control', 'no-store, private');

    $this->post('https://'.MARKETING_HOST.'/login', ['email' => 'alex@example.com'])
        ->assertStatus(307)->assertRedirect('https://'.LOGIN_HOST.'/login');
});

it('starts authorization on the login host before creating pending session state', function (): void {
    $this->get('https://'.MARKETING_HOST.'/oauth/authorize?client_id=existing&state=opaque')
        ->assertStatus(307)
        ->assertRedirect('https://'.LOGIN_HOST.'/oauth/authorize?client_id=existing&state=opaque');
});

it('keeps tenant discovery and sign-in on the tenant origin', function (): void {
    $this->getJson('https://'.TENANT_HOST.'/.well-known/openid-configuration')
        ->assertOk()->assertJsonPath('issuer', 'https://'.TENANT_HOST)
        ->assertJsonPath('authorization_endpoint', 'https://'.TENANT_HOST.'/oauth/authorize');
    $this->get('https://'.TENANT_HOST.'/login')->assertOk()->assertDontSee('One identity layer.');
});

it('redirects old protocol addresses to the login host issuer', function (): void {
    $this->getJson('https://'.MARKETING_HOST.'/.well-known/oauth-authorization-server')
        ->assertStatus(307)->assertRedirect('https://'.LOGIN_HOST.'/.well-known/oauth-authorization-server');
    $this->getJson('https://'.LOGIN_HOST.'/.well-known/oauth-authorization-server')
        ->assertOk()->assertJsonPath('issuer', 'https://'.LOGIN_HOST);
    $this->getJson('https://'.LOGIN_HOST.'/.well-known/jwks.json')->assertOk();
    $this->postJson('https://'.MARKETING_HOST.'/oauth/token')->assertStatus(307)
        ->assertRedirect('https://'.LOGIN_HOST.'/oauth/token');
    $this->getJson('https://'.MARKETING_HOST.'/up')->assertOk();
    // The console host issues nothing.
    $this->getJson('https://'.APP_HOST.'/.well-known/openid-configuration')->assertNotFound();
});

it('keeps the root passkey scope on the login host origin and isolates tenant passkeys', function (): void {
    config([
        'cbox-id.webauthn.rp_id' => MARKETING_HOST,
        'cbox-id.webauthn.origin' => 'https://'.LOGIN_HOST,
    ]);
    app(EnvironmentContext::class)->set(GenericEnvironment::of(platformRootEnvironment()->environmentKey()));
    $root = app(RelyingParties::class)->current();
    expect($root->id)->toBe(MARKETING_HOST)->and($root->origin)->toBe('https://'.LOGIN_HOST);

    app(EnvironmentContext::class)->set(GenericEnvironment::of($this->tenant->environmentKey()));
    $party = app(RelyingParties::class)->current();
    expect($party->id)->toBe(TENANT_HOST)->and($party->origin)->toBe('https://'.TENANT_HOST);
});

it('canonicalizes www and publishes a sitemap for the public homepage', function (): void {
    $this->get('https://www.'.MARKETING_HOST.'/?ref=docs')->assertStatus(308)
        ->assertRedirect('https://'.MARKETING_HOST.'/?ref=docs');
    $this->get('https://'.MARKETING_HOST.'/robots.txt')->assertOk()->assertSee('https://'.MARKETING_HOST.'/sitemap.xml');
    $this->get('https://'.MARKETING_HOST.'/sitemap.xml')->assertOk()->assertSee('<loc>https://'.MARKETING_HOST.'/</loc>', false);
});

it('leaves the console homepage alone', function (): void {
    $this->get('https://'.APP_HOST.'/')->assertRedirect()->assertDontSee('One identity layer.');
    config(['marketing.host' => null]);
    $this->get('https://'.APP_HOST.'/')->assertRedirect()->assertDontSee('One identity layer.');
});

it('does not serve marketing or redirect on unrelated wildcard hosts', function (): void {
    $this->get('https://unknown.'.MARKETING_HOST.'/login')->assertNotFound();
});
