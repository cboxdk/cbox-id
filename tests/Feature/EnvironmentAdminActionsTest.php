<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use Cbox\Id\AccessControl\Contracts\AccessChecker;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Models\Permission;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\AuditStreaming\Models\AuditStream;
use Cbox\Id\Directory\Contracts\Directories;
use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\ExternalActions\Contracts\ExternalActions;
use Cbox\Id\ExternalActions\Enums\HookPoint;
use Cbox\Id\ExternalActions\Models\ExternalActionEndpoint;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Governance\Contracts\AccessReviews;
use Cbox\Id\Governance\Contracts\SegregationOfDuties;
use Cbox\Id\Governance\Enums\CampaignStatus;
use Cbox\Id\Governance\Models\CertificationCampaign;
use Cbox\Id\Governance\Models\SodPolicy;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Cbox\Id\Provisioning\Contracts\ProvisioningConnections;
use Cbox\Id\Provisioning\Enums\AuthScheme as ProvisioningAuthScheme;
use Cbox\Id\Provisioning\Models\ProvisioningConnection;
use Cbox\Id\SamlIdp\Contracts\ServiceProviders;
use Cbox\Id\SamlIdp\Enums\NameIdFormat;
use Cbox\Id\SamlIdp\Models\ServiceProvider;
use Cbox\Id\SamlIdp\ValueObjects\NewServiceProvider;
use Cbox\Id\TokenVault\Contracts\SecretVault;
use Cbox\Id\TokenVault\Models\VaultGrant;
use Cbox\Id\TokenVault\Models\VaultSecret;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;
use Cbox\Id\Webhooks\Models\WebhookEndpoint;
use Cbox\LaravelSiem\Contracts\LogStreams;
use Cbox\LaravelSiem\Enums\AuthScheme as SiemAuthScheme;
use Cbox\LaravelSiem\Enums\Destination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/** A fresh org inside the pinned environment. */
function makeOrg(string $slug): string
{
    return app(Organizations::class)
        ->create(new NewOrganization(name: 'T '.$slug, slug: $slug))->id;
}

it('exercises the connection detail mutating actions (saveConfig, activate, disable, delete)', function (): void {
    crudSetup();
    // saveConfig completes the OIDC endpoints via SSRF-guarded discovery from the issuer.
    config(['cbox-id.federation.verify_url' => false]);
    Http::fake([
        'api.pwnedpasswords.com/*' => Http::response('', 200),
        'okta.example/issuer/.well-known/openid-configuration' => Http::response([
            'issuer' => 'https://okta.example/issuer',
            'authorization_endpoint' => 'https://okta.example/issuer/authorize',
            'token_endpoint' => 'https://okta.example/issuer/token',
        ], 200),
    ]);
    $orgId = makeOrg('conn-org');
    $connection = app(Connections::class)->create(
        $orgId,
        ConnectionType::Oidc,
        'Okta',
        ['issuer' => 'https://okta.example', 'client_id' => 'a', 'client_secret' => 'b'],
    );

    // The OIDC edit form binds issuer/client_id + a signing_key that is never prefilled,
    // so a save must re-supply it or the page short-circuits with a field error.
    Volt::test('console.connections.show', ['connection' => $connection->id])
        ->set('editName', 'Okta Renamed')
        ->set('issuer', 'https://okta.example/issuer')
        ->set('client_id', 'client-abc')
        ->set('signing_key', 'a-signing-key')
        ->call('saveConfig')
        ->call('activate')
        ->call('disable')
        ->assertHasNoErrors();

    expect(Connection::query()->whereKey($connection->id)->value('name'))->toBe('Okta Renamed');

    Volt::test('console.connections.show', ['connection' => $connection->id])
        ->call('deleteConnection');
    expect(Connection::query()->whereKey($connection->id)->exists())->toBeFalse();
});

it('exercises the directory detail mutating actions (regenerateToken, toggleStatus, saveName, delete)', function (): void {
    crudSetup();
    $orgId = makeOrg('dir-org');
    $directory = app(Directories::class)->register($orgId, 'HR')->directory;

    Volt::test('console.directories.show', ['directory' => $directory->id])
        ->call('regenerateToken')
        ->call('toggleStatus')
        ->set('editName', 'HR Renamed')
        ->call('saveName')
        ->assertHasNoErrors();

    expect(Directory::query()->whereKey($directory->id)->value('name'))->toBe('HR Renamed');

    Volt::test('console.directories.show', ['directory' => $directory->id])
        ->call('deleteDirectory');
    expect(Directory::query()->whereKey($directory->id)->exists())->toBeFalse();
});

it('exercises the role detail mutating actions (saveDetails, togglePermission, delete)', function (): void {
    crudSetup();
    $role = app(Roles::class)->define(null, 'Support');
    // The roles page toggles a real, non-orphaned permission; the catalogue is global,
    // so a directly-created Permission is picked up by togglePermission.
    $permission = Permission::query()->create(['name' => 'reports:view', 'description' => 'View reports']);

    Volt::test('console.roles.show', ['role' => $role->id])
        ->set('editName', 'Support Renamed')
        ->set('editDescription', 'Support staff')
        ->call('saveDetails')
        ->call('togglePermission', $permission->id)
        ->assertHasNoErrors();

    expect(Role::query()->whereKey($role->id)->value('name'))->toBe('Support Renamed');

    Volt::test('console.roles.show', ['role' => $role->id])
        ->call('deleteRole');
    expect(Role::query()->whereKey($role->id)->exists())->toBeFalse();
});

it('exercises the webhook detail mutating actions (saveSubscription, pause, resume, rotateSecret, delete)', function (): void {
    crudSetup();
    $endpoint = app(WebhookRegistry::class)
        ->registerForEnvironment('https://example.com/wh', ['user.created'])->endpoint;

    Volt::test('console.webhooks.show', ['webhook' => $endpoint->id])
        ->set('editUrl', 'https://example.com/wh-updated')
        ->set('editEvents', ['user.created', 'user.updated'])
        ->call('saveSubscription')
        ->call('pause')
        ->call('resume')
        ->call('rotateSecret')
        ->assertHasNoErrors();

    expect(WebhookEndpoint::query()->whereKey($endpoint->id)->value('url'))->toBe('https://example.com/wh-updated');

    Volt::test('console.webhooks.show', ['webhook' => $endpoint->id])
        ->call('deleteEndpoint');
    expect(WebhookEndpoint::query()->whereKey($endpoint->id)->exists())->toBeFalse();
});

it('exercises the sso-provider detail mutating actions (save, remove)', function (): void {
    crudSetup();
    $sp = app(ServiceProviders::class)->register(new NewServiceProvider(
        entityId: 'https://sp/meta',
        acsUrl: 'https://sp/acs',
        nameIdFormat: NameIdFormat::cases()[0],
        nameIdAttribute: 'email',
    ));

    Volt::test('environment.sso-providers.show', ['provider' => $sp->id])
        ->set('entity_id', 'https://sp/meta-updated')
        ->call('save')
        ->assertHasNoErrors();

    expect(ServiceProvider::query()->whereKey($sp->id)->value('entity_id'))->toBe('https://sp/meta-updated');

    Volt::test('environment.sso-providers.show', ['provider' => $sp->id])
        ->call('remove');
    expect(ServiceProvider::query()->whereKey($sp->id)->exists())->toBeFalse();
});

it('exercises the event-hook detail mutating actions (pause, activate, remove)', function (): void {
    crudSetup();
    $hook = app(ExternalActions::class)
        ->registerForEnvironment(HookPoint::TokenMinting, 'https://example.com/hook')->endpoint;

    Volt::test('console.hooks.show', ['hook' => $hook->id])
        ->call('pause')
        ->call('activate')
        ->assertHasNoErrors();

    Volt::test('console.hooks.show', ['hook' => $hook->id])
        ->call('remove');
    expect(ExternalActionEndpoint::query()->whereKey($hook->id)->exists())->toBeFalse();
});

it('exercises the sod-policy detail mutating actions (scan, toggle, remove)', function (): void {
    crudSetup();
    $scanOrgId = makeOrg('sod-org');
    $roleA = app(Roles::class)->define(null, 'Maker');
    $roleB = app(Roles::class)->define(null, 'Checker');
    $policy = app(SegregationOfDuties::class)
        ->definePolicy(null, 'MC', [$roleA->id, $roleB->id]);

    // One component on both planes now, and the organization an environment-wide rule is
    // evaluated against comes from the console chrome rather than from a picker the page
    // carried — so it is chosen here, the way an administrator chooses it.
    app(ConsoleScope::class)->chooseOrganization($scanOrgId);

    Volt::test('console.sod-policies.show', ['policy' => $policy->id])
        ->call('scan')
        ->call('toggle')
        ->assertHasNoErrors();

    Volt::test('console.sod-policies.show', ['policy' => $policy->id])
        ->call('remove');
    expect(SodPolicy::query()->whereKey($policy->id)->exists())->toBeFalse();
});

it('exercises the provisioning detail mutating actions (pause, resume, delete)', function (): void {
    crudSetup();
    $connection = app(ProvisioningConnections::class)->register(
        null,
        'DS',
        'https://example.com',
        ProvisioningAuthScheme::cases()[0],
        'secret',
    )->connection;

    Volt::test('console.provisioning.show', ['sync' => $connection->id])
        ->call('pause')
        ->call('resume')
        ->assertHasNoErrors();

    Volt::test('console.provisioning.show', ['sync' => $connection->id])
        ->call('deleteConnection');
    expect(ProvisioningConnection::query()->whereKey($connection->id)->exists())->toBeFalse();
});

it('exercises the governance detail mutating action (close)', function (): void {
    crudSetup();
    $orgId = makeOrg('gov-org');
    $campaign = app(AccessReviews::class)->open($orgId, 'Q3');

    // Choose the organization, as the console chrome's picker does. Applying revokes now
    // goes through ConsoleScope::requireOrganizationId() rather than reading the
    // campaign's own organization_id back out of the record — which is what made the
    // framework's ownership assertion compare a campaign to itself and pass for any
    // caller. Opening a review already required a selection (governance/create), so this
    // states a precondition the flow always had rather than adding one.
    app(ConsoleScope::class)->chooseOrganization($orgId);

    // note: certify() and revoke() require a seeded campaign item (a snapshotted role
    // assignment / membership for a subject in the org). A freshly opened campaign over
    // an empty org has no items, so only close() is exercised here.
    Volt::test('console.governance.show', ['campaign' => $campaign->id])
        ->call('close')
        ->assertHasNoErrors();

    expect(CertificationCampaign::query()->whereKey($campaign->id)->value('status'))
        ->toBe(CampaignStatus::Closed);
});

it('refuses to close a review before an organization is chosen', function (): void {
    // The other half of the same fix. `close()` used to pass the campaign's OWN
    // `organization_id` to the framework's ownership assertion, so the assertion compared
    // the record to itself and passed for every caller — the check existed and could not
    // fail. It asks the SCOPE now, which means an environment administrator who has not
    // named an organization is refused rather than applying revokes on one they never
    // chose. Opening a review already required a selection, so nothing legitimate lost it.
    crudSetup();
    $campaign = app(AccessReviews::class)->open(makeOrg('gov-unchosen'), 'Q4');

    Volt::test('console.governance.show', ['campaign' => $campaign->id])
        ->call('close')
        ->assertForbidden();

    expect(CertificationCampaign::query()->whereKey($campaign->id)->value('status'))
        ->toBe(CampaignStatus::Open);
});

it('exercises the vault detail mutating actions (startRotate, rotate, addGrant, revokeGrant, revoke)', function (): void {
    crudSetup();
    $secret = app(SecretVault::class)->store('K', 'stripe', 'sk_x');
    $client = app(ClientRegistry::class)->register(new NewClient(
        name: 'Agent App',
        type: ClientType::Confidential,
        redirectUris: ['https://agent.example/cb'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
    ))->client;

    // The merged component — one page for both planes. It resolves the owner from the
    // CONSOLE'S scope rather than from the row, so with no organization chosen this acts
    // on the environment's own unowned secrets, which is what store() above created.
    Volt::test('console.vault.show', ['secret' => $secret->id])
        ->call('startRotate')
        ->set('rotateSecret', 'sk_rotated_value')
        ->call('rotate')
        ->set('grantClient', $client->client_id)
        ->call('addGrant')
        ->call('revokeGrant', $client->client_id)
        ->assertHasNoErrors();

    expect(VaultGrant::query()->where('secret_id', $secret->id)->whereNull('revoked_at')->exists())->toBeFalse();

    // revoke is a soft revoke (isRevoked), not a hard delete — the row stays but is sealed off.
    Volt::test('console.vault.show', ['secret' => $secret->id])
        ->call('revoke');
    expect(VaultSecret::query()->whereKey($secret->id)->value('revoked_at'))->not->toBeNull();
});

it('exercises the audit-stream detail mutating actions (disable, resume, delete)', function (): void {
    crudSetup();
    $stream = app(LogStreams::class)->create(
        'S',
        Destination::GenericJson,
        'https://example.com/s',
        'k',
        SiemAuthScheme::Bearer,
    )->stream;

    Volt::test('console.audit-streams.show', ['stream' => $stream->id])
        ->call('disable')
        ->call('resume')
        ->assertHasNoErrors();

    Volt::test('console.audit-streams.show', ['stream' => $stream->id])
        ->call('deleteStream');
    expect(AuditStream::query()->whereKey($stream->id)->exists())->toBeFalse();
});

/**
 * @group security
 *
 * EXISTENCE IS NOT LIFE.
 *
 * Adding a user to an organization checked only that the row was there — so a member
 * could be added to a SUSPENDED or DELETED organization, and the membership was really
 * written. Access granted through an organization that refuses every authenticated
 * action, from a picker that offered it as an ordinary choice.
 *
 * Asserted through the property rather than the dropdown: hiding an option is not a
 * guard, because `assignOrgId` is a Livewire property and a client sets it to whatever
 * it likes.
 */
it('refuses to add a user to an organization that is not live', function (string $status): void {
    platformRootEnvironment();
    $result = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($result->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($result->environment->id));
    actAsEnvironmentAdmin($result->owner->id, $result->environment->id);

    $subjectId = app(Subjects::class)->create('dana@acme.example', 'Dana', 'the-original-passphrase')->id;

    $dead = app(Organizations::class)->create(new NewOrganization('Gone Ltd', 'gone-ltd-'.$status));
    $dead->forceFill(['status' => OrganizationStatus::from($status)])->save();

    Volt::test('environment.users.show', ['user' => $subjectId])
        ->set('assignOrgId', $dead->id)
        ->set('assignRole', MembershipRole::Member->value)
        ->call('assignOrg')
        ->assertHasErrors('assignOrgId');

    expect(app(Memberships::class)->of($dead->id, $subjectId))->toBeNull();
})->with(['suspended', 'deleted'])->group('security');

/**
 * And it is not offered in the first place, so nobody is invited to try.
 */
it('offers only live organizations in the add-to-organization picker', function (): void {
    platformRootEnvironment();
    $result = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($result->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($result->environment->id));
    actAsEnvironmentAdmin($result->owner->id, $result->environment->id);

    $subjectId = app(Subjects::class)->create('dana@acme.example', 'Dana', 'the-original-passphrase')->id;

    $live = app(Organizations::class)->create(new NewOrganization('Still Here', 'still-here'));
    $dead = app(Organizations::class)->create(new NewOrganization('Gone Ltd', 'gone-ltd'));
    $dead->forceFill(['status' => OrganizationStatus::Deleted])->save();

    $html = Volt::test('environment.users.show', ['user' => $subjectId])->html();

    expect($html)->toContain('Still Here')->not->toContain('Gone Ltd');
});

/**
 * The grant that names no organization — the case an org-scoped one cannot describe.
 *
 * A support agent acting across every customer, somebody who has joined no organization,
 * and any app with no tenancy of its own each used to get a token carrying no roles and
 * no permissions, with no way to give them any short of inventing a membership.
 */
it('grants a role everywhere from the user page, with no membership involved', function (): void {
    platformRootEnvironment();
    $result = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($result->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($result->environment->id));
    actAsEnvironmentAdmin($result->owner->id, $result->environment->id);

    $subjectId = app(Subjects::class)->create('agent@acme.example', 'Agent', 'the-original-passphrase')->id;
    $support = app(Roles::class)->define(null, 'Support');

    Volt::test('environment.users.show', ['user' => $subjectId])
        ->call('toggleEnvironmentRole', $support->id);

    expect(app(Roles::class)->everywhereFor($subjectId))->toBe([$support->id]);

    // It reaches a token in an organization the person is not a member of — which is the
    // whole claim, and the thing an org-scoped grant cannot do.
    expect(app(AccessChecker::class)->forToken($subjectId, $result->organization->id, 'cid_any')->roles)
        ->toBe(['Support']);

    // Toggling again takes it back everywhere at once.
    Volt::test('environment.users.show', ['user' => $subjectId])
        ->call('toggleEnvironmentRole', $support->id);

    expect(app(Roles::class)->everywhereFor($subjectId))->toBe([]);
});

/**
 * @group security
 *
 * And one organization's own role may not be handed to everybody. It is that tenant's
 * policy, named by them; granting it across the environment would give every other tenant
 * a role they did not define and cannot see.
 */
it('refuses to grant one organization’s role everywhere from the page', function (): void {
    platformRootEnvironment();
    $result = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner2@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($result->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($result->environment->id));
    actAsEnvironmentAdmin($result->owner->id, $result->environment->id);

    $subjectId = app(Subjects::class)->create('agent2@acme.example', 'Agent', 'the-original-passphrase')->id;
    $theirs = app(Roles::class)->define($result->organization->id, 'Billing admin');

    // Called directly, because the button is never drawn for it — and a control that is
    // merely unrendered is not a control that is enforced.
    Volt::test('environment.users.show', ['user' => $subjectId])
        ->call('toggleEnvironmentRole', $theirs->id);

    expect(app(Roles::class)->everywhereFor($subjectId))->toBe([]);
})->group('security');
