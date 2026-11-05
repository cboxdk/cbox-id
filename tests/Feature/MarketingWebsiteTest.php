<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\RelyingParties;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Models\Environment;

beforeEach(function (): void {
    multiTenantDeployment();
    platformRootEnvironment();
    installedDeployment();
    config([
        'app.url' => 'https://app.cboxid.com',
        'marketing.host' => 'cboxid.com',
        'marketing.app_url' => 'https://app.cboxid.com',
        'cbox-id.tenancy.account_host' => 'app.cboxid.com',
        'cbox-id.environments.base_domains' => ['cboxid.com'],
        'cbox-id.issuer' => 'https://app.cboxid.com',
    ]);
});

it('serves a public website without a login session and links to the application', function (): void {
    $response = $this->get('https://cboxid.com/')->assertOk()
        ->assertSee('One identity layer.')
        ->assertSee('https://app.cboxid.com/login')
        ->assertSee('https://app.cboxid.com/signup')
        ->assertHeader('X-Frame-Options', 'DENY');

    expect($response->headers->getCookies())->toBeEmpty();
    expect($response->headers->get('Cache-Control'))->toContain('public');
});

it('moves old interactive URLs to the application without losing query parameters', function (): void {
    $this->get('https://cboxid.com/login?return_to=%2Faccount&mode=passkey')
        ->assertStatus(307)
        ->assertRedirect('https://app.cboxid.com/login?return_to=%2Faccount&mode=passkey')
        ->assertHeader('Cache-Control', 'no-store, private');

    $this->post('https://cboxid.com/login', ['email' => 'alex@example.com'])
        ->assertStatus(307)->assertRedirect('https://app.cboxid.com/login');
});

it('starts authorization on the application host before creating pending session state', function (): void {
    $this->get('https://cboxid.com/oauth/authorize?client_id=existing&state=opaque')
        ->assertStatus(307)
        ->assertRedirect('https://app.cboxid.com/oauth/authorize?client_id=existing&state=opaque');
});

it('keeps tenant discovery and sign-in on the existing tenant origin', function (): void {
    Environment::query()->create(['name' => 'Cbox', 'slug' => 'cbox', 'status' => 'active', 'is_default' => false]);

    $this->getJson('https://cbox.cboxid.com/.well-known/openid-configuration')
        ->assertOk()->assertJsonPath('issuer', 'https://cbox.cboxid.com')
        ->assertJsonPath('authorization_endpoint', 'https://cbox.cboxid.com/oauth/authorize');
    $this->get('https://cbox.cboxid.com/login')->assertOk()->assertDontSee('One identity layer.');
    $this->get('https://app.cboxid.com/login')->assertOk()->assertDontSee('One identity layer.');
});

it('uses the new application issuer and redirects old protocol addresses', function (): void {
    $this->getJson('https://cboxid.com/.well-known/oauth-authorization-server')
        ->assertStatus(307)->assertRedirect('https://app.cboxid.com/.well-known/oauth-authorization-server');
    $this->getJson('https://app.cboxid.com/.well-known/oauth-authorization-server')
        ->assertOk()->assertJsonPath('issuer', 'https://app.cboxid.com');
    $this->getJson('https://app.cboxid.com/.well-known/jwks.json')->assertOk();
    $this->postJson('https://cboxid.com/oauth/token')->assertStatus(307)
        ->assertRedirect('https://app.cboxid.com/oauth/token');
    $this->getJson('https://cboxid.com/up')->assertOk();
    $this->getJson('https://app.cboxid.com/.well-known/openid-configuration')->assertNotFound();
});

it('keeps the root passkey scope while moving its browser origin and isolates tenant passkeys', function (): void {
    config([
        'cbox-id.webauthn.rp_id' => 'cboxid.com',
        'cbox-id.webauthn.origin' => 'https://app.cboxid.com',
    ]);
    app(EnvironmentContext::class)->set(GenericEnvironment::of(platformRootEnvironment()->environmentKey()));
    $root = app(RelyingParties::class)->current();
    expect($root->id)->toBe('cboxid.com')->and($root->origin)->toBe('https://app.cboxid.com');

    $tenant = Environment::query()->create(['name' => 'Cbox', 'slug' => 'cbox', 'status' => 'active', 'is_default' => false]);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($tenant->environmentKey()));
    $party = app(RelyingParties::class)->current();
    expect($party->id)->toBe('cbox.cboxid.com')->and($party->origin)->toBe('https://cbox.cboxid.com');
});

it('canonicalizes www and publishes a sitemap for the public homepage', function (): void {
    $this->get('https://www.cboxid.com/?ref=docs')->assertStatus(308)
        ->assertRedirect('https://cboxid.com/?ref=docs');
    $this->get('https://cboxid.com/robots.txt')->assertOk()->assertSee('https://cboxid.com/sitemap.xml');
    $this->get('https://cboxid.com/sitemap.xml')->assertOk()->assertSee('<loc>https://cboxid.com/</loc>', false);
});

it('leaves the existing homepage alone when marketing is not configured or shares the app host', function (): void {
    config(['marketing.host' => null]);
    $this->get('https://app.cboxid.com/')->assertRedirect('https://app.cboxid.com/login');
    config(['marketing.host' => 'app.cboxid.com']);
    $this->get('https://app.cboxid.com/')->assertRedirect('https://app.cboxid.com/login');
});

it('does not serve marketing or redirect on unrelated wildcard hosts', function (): void {
    $this->get('https://unknown.cboxid.com/login')->assertNotFound();
});
