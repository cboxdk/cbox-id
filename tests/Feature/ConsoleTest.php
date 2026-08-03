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
use Cbox\Id\Organization\Enums\MembershipRole;
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
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    // An established owner has confirmed their address — see actingAsRole() in Pest.php.
    app(Subjects::class)->markEmailVerified($subject->id, (string) $subject->email);
    $subject = app(Subjects::class)->find($subject->id) ?? $subject;

    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);

    return $org->id;
}

it('registers a SCIM directory and reveals a bearer token once', function () {
    $orgId = owner();
    entitle($orgId, 'cbox-id-scim');

    // Registration lives on the merged create page now, and the reveal-once token lands
    // on the directory's own detail page — flashed across the single redirect, because a
    // token that is shown once needs somewhere to be shown that is not the row you just
    // submitted.
    Volt::test('console.directories.create')
        ->set('name', 'Okta')
        ->call('register')
        ->assertHasNoErrors();

    $directory = Directory::query()->where('organization_id', $orgId)->where('name', 'Okta')->firstOrFail();

    // The token is protected (never dehydrated into the wire snapshot), so assert the
    // one-time reveal on the rendered output rather than reaching into component state.
    Volt::test('console.directories.show', ['directory' => $directory->id])->assertSee('scim_');
});

it('registers an OAuth client for the organization', function () {
    $orgId = owner();

    // A machine-to-machine app (client-credentials), so no redirect URI is needed;
    // its scopes come from the advanced/custom field. Registration moved to its own
    // routable page when the two consoles merged; the capability is unchanged.
    Volt::test('console.clients.create')
        ->set('name', 'CI Pipeline')
        ->set('grantAuthorizationCode', false)
        ->set('grantClientCredentials', true)
        ->set('selectedScopes', [])
        ->set('customScopes', 'api.read, api.write')
        ->call('create')
        ->assertHasNoErrors();

    $client = Client::query()->where('organization_id', $orgId)->first();
    expect($client)->not->toBeNull()
        ->and($client->name)->toBe('CI Pipeline')
        ->and($client->scopes)->toContain('api.read');
});

// Sign-out only returns people to the app when the requested post_logout_redirect_uri
// is on this client's registered allow-list, byte for byte — so the org console has to
// be able to write that list, or every sign-out ends on Cbox ID's bare page.
it('registers an OAuth client with post-logout redirect URIs', function () {
    $orgId = owner();

    Volt::test('console.clients.create')
        ->set('name', 'Support Portal')
        ->set('grantAuthorizationCode', true)
        ->set('redirectUris', 'https://portal.acme.test/auth/callback')
        ->set('postLogoutRedirectUris', 'https://portal.acme.test/signed-out')
        ->call('create')
        ->assertHasNoErrors();

    $client = Client::query()->where('organization_id', $orgId)->where('name', 'Support Portal')->first();
    expect($client)->not->toBeNull()
        ->and($client->post_logout_redirect_uris)->toBe(['https://portal.acme.test/signed-out']);
});

it('creates and activates a SAML connection', function () {
    $orgId = owner();
    entitle($orgId, 'cbox-id-sso');

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

it('forbids a non-admin from registering a directory', function () {
    $subject = app(Subjects::class)->create('member@acme.test', 'Member');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-m'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Member);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Member);

    // The read gate now blocks a member at mount — they never reach register().
    Volt::test('console.directories.create')->assertForbidden();
});

function member(): string
{
    $subject = app(Subjects::class)->create('plain@acme.test', 'Plain Member');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-reader'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Member);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Member);

    return $org->id;
}

it('forbids a non-admin member from reading admin console pages', function (string $page) {
    member();

    // Not just the write buttons — the whole page (org-wide config, secrets,
    // audit) must be unreadable to a plain member.
    Volt::test($page)->assertForbidden();
})->with(['console.audit', 'console.clients.index', 'console.connections.index', 'console.directories.index', 'console.roles.index', 'console.webhooks.index']);

it('re-authorizes org-admin console pages on every request via boot(), not just mount()', function () {
    // A member who is an admin at mount, then demoted mid-session. The read gate must
    // catch the demotion on the NEXT request — proving the guard lives in boot()
    // (runs every hydration), not mount() (runs once). Under a mount()-only gate the
    // open snapshot would keep re-rendering org-wide SSO/SCIM/role/webhook config.
    $subject = app(Subjects::class)->create('demote@acme.test', 'Dee', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-demote'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    $cu = app(CurrentUser::class);
    $cu->set($subject, $session, $org, MembershipRole::Owner);

    entitle($org->id, 'cbox-id-sso');
    entitle($org->id, 'cbox-id-scim');

    foreach (['console.connections.index', 'console.directories.index', 'console.roles.index', 'console.webhooks.index'] as $page) {
        $component = Volt::test($page)->assertOk();          // mounts fine as admin

        $cu->set($subject, $session, $org, MembershipRole::Member); // demoted

        $component->call('$refresh')->assertForbidden();     // boot() re-checks → 403

        $cu->set($subject, $session, $org, MembershipRole::Owner);  // restore for next page
    }
});
