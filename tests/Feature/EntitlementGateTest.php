<?php

declare(strict_types=1);

use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
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
    $orgId = gateAdmin('gate-sso-deny');

    Volt::test('console.connections.index')->assertSee('Enterprise');
    Volt::test('console.connections.create')->assertSee('Enterprise');

    Volt::test('console.connections.create')->call('create')->assertForbidden();
    Volt::test('console.connections.index')->call('invite')->assertForbidden();

    // The lifecycle actions moved to the connection's own page in the console merge, so
    // the gate has to be proven where they now live — on a real connection this org
    // owns, because an unscoped id would 404 before ever reaching the entitlement check
    // and the test would pass for the wrong reason.
    $connection = app(Connections::class)->create(
        $orgId,
        ConnectionType::Saml,
        'Corporate SAML',
        ['idp_entity_id' => 'https://idp.corp/metadata'],
    );

    Volt::test('console.connections.show', ['connection' => $connection->id])->call('activate')->assertForbidden();
    Volt::test('console.connections.show', ['connection' => $connection->id])->call('disable')->assertForbidden();
    Volt::test('console.connections.show', ['connection' => $connection->id])->call('saveConfig')->assertForbidden();
    Volt::test('console.connections.show', ['connection' => $connection->id])->call('deleteConnection')->assertForbidden();

    expect(Connection::query()->whereKey($connection->id)->exists())->toBeTrue();
});

it('allows SSO connection creation once the org is entitled', function () {
    $orgId = gateAdmin('gate-sso-allow');
    grantFeature($orgId, 'cbox-id-sso');

    Volt::test('console.connections.create')
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

    Volt::test('console.directories.index')->assertSee('Enterprise');

    // Both shapes of "connect a directory" are refused, not just SCIM: the merged page
    // offers the two pull providers on this plane as well, and an entitlement gate that
    // only covers the form it was written against is not a gate.
    confirmConsoleStepUp();
    Volt::test('console.directories.create')->call('register')->assertForbidden();
    confirmConsoleStepUp();
    Volt::test('console.directories.create')->call('connectPull')->assertForbidden();
    Volt::test('console.directories.index')->call('invite')->assertForbidden();
});

it('allows SCIM directory registration once the org is entitled', function () {
    $orgId = gateAdmin('gate-scim-allow');
    grantFeature($orgId, 'cbox-id-scim');

    confirmConsoleStepUp();
    Volt::test('console.directories.create')
        ->set('name', 'Okta')
        ->call('register')
        ->assertHasNoErrors();

    $directory = Directory::query()->where('organization_id', $orgId)->where('name', 'Okta')->firstOrFail();

    // The token is protected (never dehydrated into the wire snapshot), so assert the
    // one-time reveal on the rendered output rather than reaching into component state.
    Volt::test('console.directories.show', ['directory' => $directory->id])->assertSee('scim_');
});

it('gates SSO and SCIM independently', function () {
    $orgId = gateAdmin('gate-independent');
    // Entitle SSO only; SCIM must stay locked.
    grantFeature($orgId, 'cbox-id-sso');

    Volt::test('console.connections.index')->call('invite')->assertOk();
    Volt::test('console.directories.index')->call('invite')->assertForbidden();
});
