<?php

declare(strict_types=1);

use App\Platform\Environments\ReservedEnvironmentSlugs;
use App\Platform\Environments\TenantBaseDomain;
use App\Platform\IntendedUrl;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Models\Environment;

/**
 * The tenant base domain is the FIRST listed base; an older base keeps resolving and sends
 * a browser to the canonical host.
 */
beforeEach(function (): void {
    installedDeployment();

    config([
        'cbox-id.tenancy.multi_tenant' => true,
        'cbox-id.tenancy.account_host' => 'app.cboxid.com',
        'cbox-id.environments.base_domains' => ['cboxauth.com', 'cboxid.com'],
    ]);

    platformRootEnvironment();

    Environment::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'type' => EnvironmentType::Production,
        'status' => EnvironmentStatus::Active,
        'is_default' => false,
        'settings' => [],
    ]);
});

it('reads the first base as canonical and the rest as legacy', function (): void {
    expect(TenantBaseDomain::canonical())->toBe('cboxauth.com')
        ->and(TenantBaseDomain::legacy())->toBe(['cboxid.com'])
        ->and(TenantBaseDomain::hostFor('Acme'))->toBe('acme.cboxauth.com')
        ->and(TenantBaseDomain::legacySlugOf('acme.cboxid.com'))->toBe('acme')
        ->and(TenantBaseDomain::legacySlugOf('acme.cboxauth.com'))->toBeNull()
        ->and(TenantBaseDomain::legacySlugOf('a.b.cboxid.com'))->toBeNull()
        ->and(TenantBaseDomain::legacySlugOf('cboxid.com'))->toBeNull();
});

it('sends a browser on the old base to the same path on the canonical host', function (): void {
    $this->get('https://acme.cboxid.com/login?hint=1')
        ->assertStatus(302)
        ->assertHeader('Location', 'https://acme.cboxauth.com/login?hint=1')
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('serves the canonical host itself', function (): void {
    $this->get('https://acme.cboxauth.com/login')->assertOk();
});

it('redirects the metadata on the old base to the canonical issuer', function (): void {
    $this->get('https://acme.cboxid.com/.well-known/openid-configuration')
        ->assertStatus(302)
        ->assertHeader('Location', 'https://acme.cboxauth.com/.well-known/openid-configuration');

    $this->getJson('https://acme.cboxauth.com/.well-known/openid-configuration')
        ->assertOk()
        ->assertJsonPath('issuer', 'https://acme.cboxauth.com');
});

it('does not redirect what cannot follow a redirect', function (): void {
    // A credentialed call, a fetch, and a signed link stay where they arrived.
    expect($this->get('https://acme.cboxid.com/login', ['Authorization' => 'Bearer x'])->headers->get('Location'))
        ->toBeNull();
    expect($this->get('https://acme.cboxid.com/login', ['Sec-Fetch-Mode' => 'cors'])->headers->get('Location'))
        ->toBeNull();
    expect($this->get('https://acme.cboxid.com/login?signature=abc')->headers->get('Location'))
        ->toBeNull();
    expect($this->post('https://acme.cboxid.com/login', [])->headers->get('Location') ?? '')
        ->not->toStartWith('https://acme.cboxauth.com');
});

it('keeps a sign-in that a SAML request started on the old host', function (): void {
    $this->withSession([IntendedUrl::KEY => 'https://acme.cboxid.com/sso/saml/idp/sso?SAMLRequest=x'])
        ->get('https://acme.cboxid.com/login')
        ->assertOk();
});

it('leaves the platform hosts and unmapped names on the old base alone', function (): void {
    expect($this->get('https://app.cboxid.com/login')->headers->get('Location') ?? '')
        ->not->toContain('cboxauth.com');
    expect($this->get('https://nope.cboxid.com/login')->headers->get('Location') ?? '')
        ->not->toContain('cboxauth.com');
});

it('can be turned off', function (): void {
    config(['cbox-id.tenancy.redirect_legacy_base_domains' => false]);

    $this->get('https://acme.cboxid.com/login')->assertOk();
});

it('reserves the platform host names and the deployment\'s extra ones', function (): void {
    foreach (['api', 'app', 'login', 'admin', 'www'] as $slug) {
        expect(ReservedEnvironmentSlugs::reserved($slug))->toBeTrue();
    }

    expect(ReservedEnvironmentSlugs::reserved('cbox'))->toBeFalse();

    config(['cbox-id.reserved_environment_slugs_extra' => ['cbox']]);

    expect(ReservedEnvironmentSlugs::reserved('CBOX'))->toBeTrue();

    $created = Environment::query()->create([
        'name' => 'Login',
        'slug' => 'login',
        'type' => EnvironmentType::Production,
        'status' => EnvironmentStatus::Active,
        'is_default' => false,
        'settings' => [],
    ]);

    expect($created->slug)->toBe('login-env');
});

it('does not disturb an environment that already holds a slug reserved later', function (): void {
    $cbox = Environment::query()->create([
        'name' => 'Cbox',
        'slug' => 'cbox',
        'type' => EnvironmentType::Production,
        'status' => EnvironmentStatus::Active,
        'is_default' => false,
        'settings' => [],
    ]);

    config(['cbox-id.reserved_environment_slugs_extra' => ['cbox']]);

    $cbox->update(['name' => 'Cbox ID']);

    expect($cbox->refresh()->slug)->toBe('cbox');
    $this->get('https://cbox.cboxauth.com/login')->assertOk();
});
