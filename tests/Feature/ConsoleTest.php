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
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Livewire\Volt\Volt;

/** Entitle an org for a self-serve feature ('cbox-id-sso' | 'cbox-id-scim'). */
function entitle(string $organizationId, string $key): void
{
    app(EntitlementWriter::class)->set(
        $organizationId,
        new EntitlementInput($key, ['enabled' => true]),
        EntitlementSource::Manual,
    );
}

function owner(): string
{
    $subject = app(Subjects::class)->create('owner@acme.test', 'Owner', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-console'));
    app(Memberships::class)->add($org->id, $subject->id, 'owner');
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, 'owner');

    return $org->id;
}

it('registers a SCIM directory and reveals a bearer token once', function () {
    $orgId = owner();
    entitle($orgId, 'cbox-id-scim');

    $component = Volt::test('directories')
        ->set('name', 'Okta')
        ->call('register')
        ->assertHasNoErrors();

    expect($component->get('newToken'))->toStartWith('scim_')
        ->and(Directory::query()->where('organization_id', $orgId)->where('name', 'Okta')->exists())->toBeTrue();
});

it('registers an OAuth client for the organization', function () {
    $orgId = owner();

    Volt::test('clients')
        ->set('name', 'CI Pipeline')
        ->set('scopes', 'api.read, api.write')
        ->call('create')
        ->assertHasNoErrors();

    $client = Client::query()->where('organization_id', $orgId)->first();
    expect($client)->not->toBeNull()
        ->and($client->name)->toBe('CI Pipeline')
        ->and($client->scopes)->toContain('api.read');
});

it('creates and activates a SAML connection', function () {
    $orgId = owner();
    entitle($orgId, 'cbox-id-sso');

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

it('forbids a non-admin from registering a directory', function () {
    $subject = app(Subjects::class)->create('member@acme.test', 'Member');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-m'));
    app(Memberships::class)->add($org->id, $subject->id, 'member');
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, 'member');

    // The read gate now blocks a member at mount — they never reach register().
    Volt::test('directories')->assertForbidden();
});

function member(): string
{
    $subject = app(Subjects::class)->create('plain@acme.test', 'Plain Member');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-reader'));
    app(Memberships::class)->add($org->id, $subject->id, 'member');
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, 'member');

    return $org->id;
}

it('forbids a non-admin member from reading admin console pages', function (string $page) {
    member();

    // Not just the write buttons — the whole page (org-wide config, secrets,
    // audit) must be unreadable to a plain member.
    Volt::test($page)->assertForbidden();
})->with(['audit', 'clients', 'connections', 'directories', 'roles', 'webhooks']);
