<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Authorization\Contracts\EntitlementWriter;
use Cbox\Id\Kernel\Authorization\Enums\EntitlementSource;
use Cbox\Id\Kernel\Authorization\ValueObjects\EntitlementInput;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Livewire\Volt\Volt;

/**
 * Sign an admin into a fresh org and return its id. The org starts with NO
 * entitlements — deny-by-default is the thing under test.
 */
function gateAdmin(string $slug = 'gate-acme', string $role = 'owner'): string
{
    $subject = app(Subjects::class)->create("admin@{$slug}.test", 'Admin', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', $slug));
    app(Memberships::class)->add($org->id, $subject->id, $role);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, $role);

    return $org->id;
}

function grantFeature(string $organizationId, string $key): void
{
    app(EntitlementWriter::class)->set(
        $organizationId,
        new EntitlementInput($key, ['enabled' => true]),
        EntitlementSource::Manual,
    );
}

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

    expect($component->get('newToken'))->toStartWith('scim_')
        ->and(Directory::query()->where('organization_id', $orgId)->where('name', 'Okta')->exists())->toBeTrue();
});

it('gates SSO and SCIM independently', function () {
    $orgId = gateAdmin('gate-independent');
    // Entitle SSO only; SCIM must stay locked.
    grantFeature($orgId, 'cbox-id-sso');

    Volt::test('connections')->call('invite')->assertOk();
    Volt::test('directories')->call('invite')->assertForbidden();
});
