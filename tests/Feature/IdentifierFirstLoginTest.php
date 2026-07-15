<?php

declare(strict_types=1);

use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Testing\InteractsWithFederation;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Livewire\Volt\Volt;

// makeVerifiedDomain publishes the DNS challenge to an in-memory fake (makeConnection
// just creates/activates the connection), so home-realm discovery is exercised
// without touching the network.
uses(InteractsWithFederation::class);

it('redirects an OIDC home-realm email to the IdP on continue', function () {
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'if-oidc'));
    $connection = $this->makeConnection($org->id, ConnectionType::Oidc, 'Acme IdP', active: true);
    $this->makeVerifiedDomain($org->id, 'acme.com');

    Volt::test('auth.login')
        ->set('email', 'jane@acme.com')
        ->call('continue')
        ->assertRedirect(url('/sso/oidc/'.$connection->id.'/redirect'));
});

it('redirects a SAML home-realm email to the IdP on continue', function () {
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'if-saml'));
    $connection = $this->makeConnection($org->id, ConnectionType::Saml, 'Acme IdP', active: true);
    $this->makeVerifiedDomain($org->id, 'acme.com');

    Volt::test('auth.login')
        ->set('email', 'jane@acme.com')
        ->call('continue')
        ->assertRedirect(url('/sso/saml/'.$connection->id.'/login'));
});

it('shows the password form for a non-SSO email', function () {
    Volt::test('auth.login')
        ->set('email', 'jane@gmail.com')
        ->call('continue')
        ->assertNoRedirect()
        ->assertSet('identified', true)
        ->assertSee('Password');
});

it('routes even a direct password submit to SSO for a home-realm domain', function () {
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'if-pw'));
    $connection = $this->makeConnection($org->id, ConnectionType::Oidc, 'Acme IdP', active: true);
    $this->makeVerifiedDomain($org->id, 'acme.com');

    Volt::test('auth.login')
        ->set('email', 'jane@acme.com')
        ->set('password', 'irrelevant-because-sso')
        ->call('login')
        ->assertRedirect(url('/sso/oidc/'.$connection->id.'/redirect'));
});

it('falls through to the normal password flow for a domain with no verified claim', function () {
    // A connection exists but the domain is NOT verified → deny-by-default, no redirect.
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'if-unverified'));
    $this->makeConnection($org->id, ConnectionType::Oidc, 'Acme IdP', active: true);
    $this->makeVerifiedDomain($org->id, 'acme.com', verified: false);

    Volt::test('auth.login')
        ->set('email', 'jane@acme.com')
        ->call('continue')
        ->assertNoRedirect()
        ->assertSet('identified', true);
});
