<?php

declare(strict_types=1);

use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Models\Connection;
use Inertia\Testing\AssertableInertia;

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

    // The upsell is a PROP rather than a word in a document: `entitled` is the single
    // thing that decides whether this page offers anything at all.
    test()->get(route('connections'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('entitled', false));
    test()->get(route('connections.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('entitled', false));

    createConnection()->assertForbidden();
    test()->from(route('connections'))->post(route('connections.invite'))->assertForbidden();

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

    $from = route('connections.show', $connection->id);

    test()->from($from)->post(route('connections.activate', $connection->id))->assertForbidden();
    test()->from($from)->post(route('connections.disable', $connection->id))->assertForbidden();
    test()->from($from)->patch(route('connections.update', $connection->id), ['name' => 'Renamed'])->assertForbidden();
    test()->from($from)->delete(route('connections.destroy', $connection->id))->assertForbidden();

    expect(Connection::query()->whereKey($connection->id)->exists())->toBeTrue();
});

it('allows SSO connection creation once the org is entitled', function () {
    $orgId = gateAdmin('gate-sso-allow');
    grantFeature($orgId, 'cbox-id-sso');

    createConnection()->assertSessionHasNoErrors();

    expect(Connection::query()->where('organization_id', $orgId)->where('name', 'Corporate SAML')->exists())->toBeTrue();
});

it('shows the SCIM upsell and refuses every SCIM action for a non-entitled org', function () {
    gateAdmin('gate-scim-deny');

    test()->get(route('directories'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('organizationChosen', true)
            ->where('entitled', false));

    // Both shapes of "connect a directory" are refused, not just SCIM: the merged page
    // offers the two pull providers on this plane as well, and an entitlement gate that
    // only covers the form it was written against is not a gate.
    confirmConsoleStepUp();
    registerDirectory()->assertForbidden();
    confirmConsoleStepUp();
    connectDirectory()->assertForbidden();
    test()->from(route('directories'))->post(route('directories.invite'))->assertForbidden();
});

it('allows SCIM directory registration once the org is entitled', function () {
    $orgId = gateAdmin('gate-scim-allow');
    grantFeature($orgId, 'cbox-id-scim');

    confirmConsoleStepUp();
    registerDirectory(['name' => 'Okta'])->assertSessionHasNoErrors()->assertInertiaFlash('newToken');

    expect(Directory::query()->where('organization_id', $orgId)->where('name', 'Okta')->exists())
        ->toBeTrue();
});

it('gates SSO and SCIM independently', function () {
    $orgId = gateAdmin('gate-independent');
    // Entitle SSO only; SCIM must stay locked.
    grantFeature($orgId, 'cbox-id-sso');

    test()->from(route('connections'))->post(route('connections.invite'))->assertRedirect();
    test()->from(route('directories'))->post(route('directories.invite'))->assertForbidden();
});
