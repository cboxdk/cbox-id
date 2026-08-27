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
use Inertia\Testing\AssertableInertia;

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

    test()->from(route('login'))->post(route('login.identify'), ['email' => 'jane@acme.com'])
        ->assertRedirect(url('/sso/oidc/'.$connection->id.'/redirect'));
});

it('redirects a SAML home-realm email to the IdP when the tenant requires SSO', function () {
    $connection = ssoOrganization('if-saml', ConnectionType::Saml, SsoEnforcement::Required);

    test()->from(route('login'))->post(route('login.identify'), ['email' => 'jane@acme.com'])
        ->assertRedirect(url('/sso/saml/'.$connection->id.'/login'));
});

it('offers SSO first but keeps the password form when the tenant prefers it', function () {
    $connection = ssoOrganization('if-preferred', ConnectionType::Oidc, SsoEnforcement::Preferred);

    test()->from(route('login'))->post(route('login.identify'), ['email' => 'jane@acme.com'])
        ->assertRedirect(route('login'));

    /*
     * THE OFFER RIDES THE FLASH CHANNEL, so it is read on the page the redirect lands on
     * rather than off the POST. Deliberately not a page prop: props are written into the
     * browser's history entry, and this one names a specific tenant's connection for the
     * address that was just typed.
     *
     * `leads` is the whole distinction between this test and the one below — which
     * control the page puts first — and it is a boolean the server states, not markup the
     * suite can read. Whether the two buttons are actually DRAWN that way is held in
     * tests/Browser, in a browser that renders them.
     */
    test()->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->hasFlash('identified', true)
            ->hasFlash('ssoOffer', url('/sso/oidc/'.$connection->id.'/redirect'))
            ->hasFlash('ssoOfferLeads', true));
});

it('leaves the password form leading when the tenant enforces nothing', function () {
    $connection = ssoOrganization('if-off', ConnectionType::Oidc, SsoEnforcement::Off);

    test()->from(route('login'))->post(route('login.identify'), ['email' => 'jane@acme.com'])
        ->assertRedirect(route('login'));

    test()->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->hasFlash('identified', true)
            ->hasFlash('ssoOffer', url('/sso/oidc/'.$connection->id.'/redirect'))
            // The connection is still reachable — discoverable, not pushed.
            ->hasFlash('ssoOfferLeads', false));
});

it('shows the password form for a non-SSO email', function () {
    // An organization exists, and the address simply does not belong to it. Without one
    // the deployment has nothing to serve and `/login` redirects to first-run — a 302
    // that has nothing to do with home-realm discovery.
    ssoOrganization('if-none', ConnectionType::Oidc, SsoEnforcement::Required);

    test()->from(route('login'))->post(route('login.identify'), ['email' => 'jane@gmail.com'])
        ->assertRedirect(route('login'));

    test()->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->hasFlash('identified', true)
            // No connection to offer, which is the point: an address with no home realm
            // gets the password form and nothing else.
            ->hasFlash('ssoOffer', null));
});

/**
 * A DIRECT PASSWORD SUBMIT IS THE PATH THAT MATTERS under `Require SSO`: the form can be
 * reached without the identifier step, so the refusal has to be re-asserted at submit
 * rather than only at discovery.
 */
it('routes even a direct password submit to SSO when the tenant requires it', function () {
    $connection = ssoOrganization('if-pw', ConnectionType::Oidc, SsoEnforcement::Required);

    test()->from(route('login'))->post(route('login.attempt'), ['email' => 'jane@acme.com', 'password' => 'irrelevant-because-sso'])
        ->assertRedirect(url('/sso/oidc/'.$connection->id.'/redirect'));
});

it('falls through to the normal password flow for a domain with no verified claim', function () {
    // A connection exists but the domain is NOT verified → deny-by-default, no redirect.
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'if-unverified'));
    $this->makeConnection($org->id, ConnectionType::Oidc, 'Acme IdP', active: true);
    $this->makeVerifiedDomain($org->id, 'acme.com', verified: false);

    test()->from(route('login'))->post(route('login.identify'), ['email' => 'jane@acme.com'])
        ->assertRedirect(route('login'));

    test()->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->hasFlash('identified', true)
            // DENY BY DEFAULT: an unverified domain offers nothing, however configured
            // the connection behind it is.
            ->hasFlash('ssoOffer', null));
});
