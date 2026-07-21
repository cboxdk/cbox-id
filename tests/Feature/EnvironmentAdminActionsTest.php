<?php

declare(strict_types=1);

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
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
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
    Volt::test('environment.connections.show', ['connection' => $connection->id])
        ->set('editName', 'Okta Renamed')
        ->set('issuer', 'https://okta.example/issuer')
        ->set('client_id', 'client-abc')
        ->set('signing_key', 'a-signing-key')
        ->call('saveConfig')
        ->call('activate')
        ->call('disable')
        ->assertHasNoErrors();

    expect(Connection::query()->whereKey($connection->id)->value('name'))->toBe('Okta Renamed');

    Volt::test('environment.connections.show', ['connection' => $connection->id])
        ->call('deleteConnection');
    expect(Connection::query()->whereKey($connection->id)->exists())->toBeFalse();
});

it('exercises the directory detail mutating actions (regenerateToken, toggleStatus, saveName, delete)', function (): void {
    crudSetup();
    $orgId = makeOrg('dir-org');
    $directory = app(Directories::class)->register($orgId, 'HR')->directory;

    Volt::test('environment.directories.show', ['directory' => $directory->id])
        ->call('regenerateToken')
        ->call('toggleStatus')
        ->set('editName', 'HR Renamed')
        ->call('saveName')
        ->assertHasNoErrors();

    expect(Directory::query()->whereKey($directory->id)->value('name'))->toBe('HR Renamed');

    Volt::test('environment.directories.show', ['directory' => $directory->id])
        ->call('deleteDirectory');
    expect(Directory::query()->whereKey($directory->id)->exists())->toBeFalse();
});

it('exercises the role detail mutating actions (saveDetails, togglePermission, delete)', function (): void {
    crudSetup();
    $role = app(Roles::class)->define(null, 'Support');
    // The roles page toggles a real, non-orphaned permission; the catalogue is global,
    // so a directly-created Permission is picked up by togglePermission.
    $permission = Permission::query()->create(['name' => 'reports:view', 'description' => 'View reports']);

    Volt::test('environment.roles.show', ['role' => $role->id])
        ->set('editName', 'Support Renamed')
        ->set('editDescription', 'Support staff')
        ->call('saveDetails')
        ->call('togglePermission', $permission->id)
        ->assertHasNoErrors();

    expect(Role::query()->whereKey($role->id)->value('name'))->toBe('Support Renamed');

    Volt::test('environment.roles.show', ['role' => $role->id])
        ->call('deleteRole');
    expect(Role::query()->whereKey($role->id)->exists())->toBeFalse();
});

it('exercises the webhook detail mutating actions (saveSubscription, pause, resume, rotateSecret, delete)', function (): void {
    crudSetup();
    $endpoint = app(WebhookRegistry::class)
        ->register(null, 'https://example.com/wh', ['user.created'])->endpoint;

    Volt::test('environment.webhooks.show', ['webhook' => $endpoint->id])
        ->set('editUrl', 'https://example.com/wh-updated')
        ->set('editEvents', ['user.created', 'user.updated'])
        ->call('saveSubscription')
        ->call('pause')
        ->call('resume')
        ->call('rotateSecret')
        ->assertHasNoErrors();

    expect(WebhookEndpoint::query()->whereKey($endpoint->id)->value('url'))->toBe('https://example.com/wh-updated');

    Volt::test('environment.webhooks.show', ['webhook' => $endpoint->id])
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
        ->register(HookPoint::TokenMinting, 'https://example.com/hook')->endpoint;

    Volt::test('environment.hooks.show', ['hook' => $hook->id])
        ->call('pause')
        ->call('activate')
        ->assertHasNoErrors();

    Volt::test('environment.hooks.show', ['hook' => $hook->id])
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

    Volt::test('environment.sod-policies.show', ['policy' => $policy->id])
        ->set('scanOrgId', $scanOrgId)
        ->call('scan')
        ->call('toggle')
        ->assertHasNoErrors();

    Volt::test('environment.sod-policies.show', ['policy' => $policy->id])
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

    Volt::test('environment.provisioning.show', ['sync' => $connection->id])
        ->call('pause')
        ->call('resume')
        ->assertHasNoErrors();

    Volt::test('environment.provisioning.show', ['sync' => $connection->id])
        ->call('deleteConnection');
    expect(ProvisioningConnection::query()->whereKey($connection->id)->exists())->toBeFalse();
});

it('exercises the governance detail mutating action (close)', function (): void {
    crudSetup();
    $orgId = makeOrg('gov-org');
    $campaign = app(AccessReviews::class)->open($orgId, 'Q3');

    // note: certify() and revoke() require a seeded campaign item (a snapshotted role
    // assignment / membership for a subject in the org). A freshly opened campaign over
    // an empty org has no items, so only close() is exercised here.
    Volt::test('environment.governance.show', ['campaign' => $campaign->id])
        ->call('close')
        ->assertHasNoErrors();

    expect(CertificationCampaign::query()->whereKey($campaign->id)->value('status'))
        ->toBe(CampaignStatus::Closed);
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

    Volt::test('environment.vault.show', ['secret' => $secret->id])
        ->call('startRotate')
        ->set('rotateSecret', 'sk_rotated_value')
        ->call('rotate')
        ->set('grantClient', $client->client_id)
        ->call('addGrant')
        ->call('revokeGrant', $client->client_id)
        ->assertHasNoErrors();

    expect(VaultGrant::query()->where('secret_id', $secret->id)->whereNull('revoked_at')->exists())->toBeFalse();

    // revoke is a soft revoke (isRevoked), not a hard delete — the row stays but is sealed off.
    Volt::test('environment.vault.show', ['secret' => $secret->id])
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

    Volt::test('environment.audit-streams.show', ['stream' => $stream->id])
        ->call('disable')
        ->call('resume')
        ->assertHasNoErrors();

    Volt::test('environment.audit-streams.show', ['stream' => $stream->id])
        ->call('deleteStream');
    expect(AuditStream::query()->whereKey($stream->id)->exists())->toBeFalse();
});
