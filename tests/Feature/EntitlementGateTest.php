<?php

declare(strict_types=1);

use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\Federation\Models\Connection;
use Livewire\Volt\Volt;

// This file is ABOUT the entitlement gate, so it declares the mode it exercises.
// The default is now `open` — an unset entitlement means granted, which is what a
// self-hosted deployment runs and what most of the suite therefore sees. Gating
// only means anything under `metered`, where the billing projection is the sole
// source of a grant.
beforeEach(function (): void {
    config(['cbox-id.entitlements.mode' => 'metered']);
});

it('shows the SSO upsell and refuses every SSO action for a non-entitled org', function () {
    gateAdmin('gate-sso-deny');

    Volt::test('connections')->assertSee('Enterprise');

    Volt::test('connections')->call('create')->assertForbidden();
    Volt::test('connections')->call('activate', 'con_x')->assertForbidden();
    Volt::test('connections')->call('invite')->assertForbidden();
});

it('allows SSO connection creation once the org is entitled', function () {
    $orgId = gateAdmin('gate-sso-allow');
    grantFeature($orgId, 'cbox-id-sso');

    Volt::test('connections')
        ->set('type', 'saml')
        ->set('name', 'Corporate SAML')
        ->set('idp_entity_id', 'https://idp.corp/metadata')
        ->set('idp_sso_url', 'https://idp.corp/sso')
        ->set('idp_x509cert', '-----BEGIN CERTIFICATE-----MIIB-----END CERTIFICATE-----')
        ->set('sp_entity_id', 'https://sp.acme/metadata')
        ->set('sp_acs_url', 'https://sp.acme/acs')
        ->call('create')
        ->assertHasNoErrors();

    expect(Connection::query()->where('organization_id', $orgId)->where('name', 'Corporate SAML')->exists())->toBeTrue();
});

it('shows the SCIM upsell and refuses every SCIM action for a non-entitled org', function () {
    gateAdmin('gate-scim-deny');

    Volt::test('directories')->assertSee('Enterprise');

    Volt::test('directories')->call('register')->assertForbidden();
    Volt::test('directories')->call('invite')->assertForbidden();
});

it('allows SCIM directory registration once the org is entitled', function () {
    $orgId = gateAdmin('gate-scim-allow');
    grantFeature($orgId, 'cbox-id-scim');

    $component = Volt::test('directories')
        ->set('name', 'Okta')
        ->call('register')
        ->assertHasNoErrors();

    // The token is protected (never dehydrated into the wire snapshot), so assert the
    // one-time reveal on the rendered output rather than reaching into component state.
    $component->assertSee('scim_');

    expect(Directory::query()->where('organization_id', $orgId)->where('name', 'Okta')->exists())->toBeTrue();
});

it('gates SSO and SCIM independently', function () {
    $orgId = gateAdmin('gate-independent');
    // Entitle SSO only; SCIM must stay locked.
    grantFeature($orgId, 'cbox-id-sso');

    Volt::test('connections')->call('invite')->assertOk();
    Volt::test('directories')->call('invite')->assertForbidden();
});
