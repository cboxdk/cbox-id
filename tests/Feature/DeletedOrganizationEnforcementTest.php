<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\DeviceAuthorization;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/**
 * `OrganizationStatus::Deleted` was written by the environment console's "Delete
 * organization" button and then enforced NOWHERE: every gate compared against
 * `Suspended` alone, and every other reference to `Deleted` was cosmetic (list
 * filtering, badge colours). A deleted tenant's members kept signing in, kept
 * consenting, and kept minting tokens.
 *
 * These tests pin `Deleted` to exactly the enforcement the UI has always implied, at
 * each of the three gates, plus the audit entry the write path now emits. Every one of
 * them fails if a gate drifts back to a bare `=== Suspended`.
 */
if (! function_exists('deletedOrgMember')) {
    /** @return array{0: User, 1: Organization} */
    function deletedOrgMember(string $email, string $slug): array
    {
        $subject = app(Subjects::class)->create($email, 'Member', 'a-strong-unbreached-passphrase');
        $org = app(Organizations::class)->create(new NewOrganization('Acme', $slug));
        app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

        $org->update(['status' => OrganizationStatus::Deleted]);

        return [$subject, $org->refresh()];
    }
}

it('refuses a member of a deleted organization at the request pipeline', function (): void {
    [$subject, $org] = deletedOrgMember('deleted-member@acme.test', 'acme-deleted-mw');
    $sessionId = app(SessionManager::class)->start($subject->id, $org->id, ['pwd'])->id;

    $this->withSession([PlatformAuth::SESSION_KEY => $sessionId, PlatformAuth::ORG_KEY => $org->id])
        ->get('/dashboard')
        ->assertForbidden();
});

it('refuses a deleted organization the device-authorization approval', function (): void {
    [$subject, $org] = deletedOrgMember('deleted-device@acme.test', 'acme-deleted-dev');
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);

    $client = app(ClientRegistry::class)->register(new NewClient(
        name: 'TV App',
        type: ClientType::Confidential,
        grantTypes: ['urn:ietf:params:oauth:grant-type:device_code'],
        scopes: ['openid'],
    ))->client;

    $result = app(DeviceAuthorization::class)->request($client, ['openid']);

    $component = Volt::test('device')
        ->set('userCode', $result->userCode)
        ->call('lookup')
        ->assertSet('verified', true);

    $component->call('approve');

    // No grant, and the refusal names the reason rather than failing opaquely.
    expect($component->get('outcome'))->not->toBe('approved')
        ->and($component->get('error'))->toContain('deleted');
});

it('refuses a deleted organization the consent screen’s code issuance', function (): void {
    [$subject, $org] = deletedOrgMember('deleted-consent@acme.test', 'acme-deleted-consent');
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);

    $clientId = app(ClientRegistry::class)->register(new NewClient(
        name: 'Web App',
        type: ClientType::Public,
        redirectUris: ['https://app.test/cb'],
        grantTypes: ['authorization_code'],
        scopes: ['openid', 'email'],
        organizationId: $org->id,
    ))->client->client_id;

    $component = Volt::test('oauth.consent', [
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'response_type' => 'code',
        'scope' => 'openid email',
        'state' => 'xyz',
        'code_challenge' => 'abc',
        'code_challenge_method' => 'S256',
    ]);

    $component->call('approve');

    expect($component->effects['redirect'] ?? null)->toBeNull()
        ->and($component->get('error'))->toContain('deleted');
});

it('records deleting an organization on that tenant’s audit trail', function (): void {
    platformRootEnvironment();

    $provisioned = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'deleted-audit-owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));
    serveOnTestHost($provisioned->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($provisioned->environment->id));
    actAsEnvironmentAdmin($provisioned->owner->id, $provisioned->environment->id);

    $org = app(Organizations::class)->create(new NewOrganization('Doomed', 'doomed'));

    Volt::test('environment.organizations.show', ['organization' => $org->id])->call('deleteOrg');

    expect(Organization::query()->whereKey($org->id)->value('status'))->toBe(OrganizationStatus::Deleted);

    // A state change that revokes everyone's access is on the record, on the org's own
    // chain — the Organizations contract has no delete verb to do it for us.
    $entry = AuditEntry::query()
        ->where('scope', $org->id)
        ->where('action', 'organization.deleted')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->target_id)->toBe($org->id)
        ->and($entry->actor_id)->toBe($provisioned->owner->id)
        ->and($entry->context['to'] ?? null)->toBe('deleted');
});
