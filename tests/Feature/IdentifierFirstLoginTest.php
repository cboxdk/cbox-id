<?php

declare(strict_types=1);

use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\Testing\InteractsWithFederation;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Livewire\Volt\Volt;

// makeVerifiedDomain publishes the DNS challenge to an in-memory fake (makeConnection
// just creates/activates the connection), so home-realm discovery is exercised
// without touching the network.
uses(InteractsWithFederation::class);

/**
 * ROUTING IS NOT ENFORCEMENT, and this page used to answer both with one decision.
 *
 * Any verified domain with an active connection was redirected, whatever the organization
 * had set — so `Off` and `Prefer SSO` behaved exactly like `Require SSO` for everyone on
 * that domain. Two of the three settings on the auth-policy screen decided nothing, and a
 * tenant that had deliberately left enforcement off still had its people bounced to an IdP
 * with no way back. This platform ships passkeys; somebody who had enrolled one on a
 * verified domain could not reach it.
 *
 * The three now differ, and each is asserted below.
 */
function ssoOrganization(string $slug, ConnectionType $type, SsoEnforcement $sso): Connection
{
    $org = app(Organizations::class)->create(new NewOrganization('Acme', $slug));
    $connection = test()->makeConnection($org->id, $type, 'Acme IdP', active: true);
    test()->makeVerifiedDomain($org->id, 'acme.com');

    app(AuthPolicies::class)->setForOrganization($org->id, new AuthPolicy(sso: $sso));

    return $connection;
}

it('redirects an OIDC home-realm email to the IdP when the tenant requires SSO', function () {
    $connection = ssoOrganization('if-oidc', ConnectionType::Oidc, SsoEnforcement::Required);

    Volt::test('auth.login')
        ->set('email', 'jane@acme.com')
        ->call('continue')
        ->assertRedirect(url('/sso/oidc/'.$connection->id.'/redirect'));
});

it('redirects a SAML home-realm email to the IdP when the tenant requires SSO', function () {
    $connection = ssoOrganization('if-saml', ConnectionType::Saml, SsoEnforcement::Required);

    Volt::test('auth.login')
        ->set('email', 'jane@acme.com')
        ->call('continue')
        ->assertRedirect(url('/sso/saml/'.$connection->id.'/login'));
});

it('offers SSO first but keeps the password form when the tenant prefers it', function () {
    $connection = ssoOrganization('if-preferred', ConnectionType::Oidc, SsoEnforcement::Preferred);

    Volt::test('auth.login')
        ->set('email', 'jane@acme.com')
        ->call('continue')
        ->assertRenderedNotRedirected()
        ->assertSet('ssoOffer', url('/sso/oidc/'.$connection->id.'/redirect'))
        ->assertSet('ssoOfferLeads', true)
        ->assertSee('Continue with single sign-on')
        ->assertSee('Password');
});

it('leaves the password form leading when the tenant enforces nothing', function () {
    $connection = ssoOrganization('if-off', ConnectionType::Oidc, SsoEnforcement::Off);

    Volt::test('auth.login')
        ->set('email', 'jane@acme.com')
        ->call('continue')
        ->assertRenderedNotRedirected()
        ->assertSet('ssoOffer', url('/sso/oidc/'.$connection->id.'/redirect'))
        // The connection is still reachable — discoverable, not pushed.
        ->assertSet('ssoOfferLeads', false)
        ->assertSee('Continue with single sign-on instead')
        ->assertSee('Password');
});

it('shows the password form for a non-SSO email', function () {
    Volt::test('auth.login')
        ->set('email', 'jane@gmail.com')
        ->call('continue')
        ->assertRenderedNotRedirected()
        ->assertSet('identified', true)
        ->assertSee('Password');
});

/**
 * A DIRECT PASSWORD SUBMIT IS THE PATH THAT MATTERS under `Require SSO`: the form can be
 * reached without the identifier step, so the refusal has to be re-asserted at submit
 * rather than only at discovery.
 */
it('routes even a direct password submit to SSO when the tenant requires it', function () {
    $connection = ssoOrganization('if-pw', ConnectionType::Oidc, SsoEnforcement::Required);

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
        ->assertRenderedNotRedirected()
        ->assertSet('identified', true);
});
