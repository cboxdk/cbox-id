<?php

declare(strict_types=1);

use App\Platform\Actions\Idempotency\IdempotencyRecord;
use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Governance\Contracts\AccessReviews;
use Cbox\Id\Governance\Contracts\SegregationOfDuties;
use Cbox\Id\Governance\Enums\AccessKind;
use Cbox\Id\Governance\Models\CertificationCampaign;
use Cbox\Id\Governance\Models\SodPolicy;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\ValueObjects\KeyProvenance;
use Cbox\Id\Provisioning\Contracts\ProvisioningConnections;
use Cbox\Id\Provisioning\Enums\AuthScheme;
use Cbox\Id\Provisioning\Enums\ConnectionStatus;
use Cbox\Id\Provisioning\Models\ProvisioningConnection;
use Cbox\Id\TokenVault\Contracts\SecretVault;
use Cbox\Id\TokenVault\Models\VaultSecret;
use Cbox\Id\TokenVault\ValueObjects\VaultOwner;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Outbound provisioning, role conflicts, access reviews and the token vault, as actions.
|--------------------------------------------------------------------------
|
| The rest of an organization's enterprise plumbing, the same way: one action per change,
| run by the management API and by the console, behind one tenant fence, on one trail. And
| the Critical ones — the changes that hand out a credential or loosen a control — wait for
| a person when the key's owner said they should.
*/

beforeEach(function (): void {
    config(['cbox-id.provisioning.verify_url' => false]);
});

/**
 * @param  list<string>  $scopes
 * @return array{0: string, 1: string} the key, and its id
 */
function govKey(array $scopes): array
{
    $issued = app(EnvironmentApiKeys::class)->issue('env_test', 'Governance worker', $scopes);

    return [$issued->plaintext, (string) $issued->key->id];
}

function govOrg(string $slug = 'acme-gov'): string
{
    return app(Organizations::class)->create(new NewOrganization('Acme', $slug.'-'.Str::lower(Str::random(4))))->id;
}

/** @return list<AuditEntry> */
function govTrail(string $action): array
{
    return array_values(AuditEntry::query()->where('action', $action)->orderBy('id')->get()->all());
}

function govElsewhere(Closure $callback): mixed
{
    return app(EnvironmentContext::class)->runAs(GenericEnvironment::of('env_other'), $callback);
}

/**
 * An organization's administrator, signed in to its own console.
 *
 * @return array{0: string, 1: string} subject id, organization id
 */
function govOrgAdmin(string $slug = 'acme-gov-console'): array
{
    $subject = app(Subjects::class)->create("admin@{$slug}.test", 'Governance Admin', 'supersecret123');
    app(Subjects::class)->markEmailVerified($subject->id, (string) $subject->email);
    $subject = app(Subjects::class)->find($subject->id) ?? $subject;

    $org = app(Organizations::class)->create(new NewOrganization('Acme', $slug));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);
    session([PlatformAuth::SESSION_KEY => $session->id]);

    return [$subject->id, $org->id];
}

/**
 * A key minted "in the console" by a person in the platform root, whose policy holds every
 * Critical action for that person's approval.
 *
 * @param  list<string>  $scopes
 */
function govSupervisedKey(array $scopes): string
{
    platformRootEnvironment();

    $environment = Environment::query()->find('env_test') ?? tap(new Environment, function (Environment $environment): void {
        $environment->forceFill([
            'id' => 'env_test',
            'name' => 'Test',
            'slug' => 'env-test',
            'type' => EnvironmentType::Production,
            'status' => EnvironmentStatus::Active,
            'is_default' => false,
            'settings' => [],
        ])->save();
    });
    serveOnTestHost($environment);

    $owner = app(PlatformRoot::class)->run(fn () => app(Subjects::class)->create('owner@governance.test', 'Ada Owner', 'supersecret123')->id);

    return app(EnvironmentApiKeys::class)->issue('env_test', 'Supervised agent', $scopes, null, new KeyProvenance(
        createdByType: 'organization_member',
        createdById: $owner,
        stepUpPolicy: ['min_danger' => 'critical', 'actions' => []],
    ))->plaintext;
}

/** @return array<string, mixed> */
function govTarget(array $changes = []): array
{
    return [
        'name' => 'Slack',
        'base_url' => 'https://scim.slack.example/v2',
        'auth_scheme' => 'bearer',
        'secret' => 'tok-THE-DOWNSTREAM-CREDENTIAL',
        ...$changes,
    ];
}

// ── Outbound provisioning ────────────────────────────────────────────────────

it('registers a downstream target with its credential sealed, pauses, resumes and deletes it, recording the key', function (): void {
    [$key, $keyId] = govKey(['provisioning:read', 'provisioning:write']);
    $org = govOrg();

    $created = $this->withToken($key)->postJson('/api/v1/provisioning-targets', [...govTarget(), 'organization_id' => $org])
        ->assertCreated()
        ->assertJsonPath('data.organization_id', $org)
        ->assertJsonPath('data.active', true)
        ->json('data');

    $this->withToken($key)->getJson('/api/v1/provisioning-targets?organization_id='.$org)->assertOk()->assertJsonPath('data.0.id', $created['id']);
    $read = $this->withToken($key)->getJson("/api/v1/provisioning-targets/{$created['id']}")->assertOk();

    $this->withToken($key)->postJson("/api/v1/provisioning-targets/{$created['id']}/status", ['active' => false])->assertOk()->assertJsonPath('data.active', false);
    $this->withToken($key)->postJson("/api/v1/provisioning-targets/{$created['id']}/status", ['active' => true])->assertOk()->assertJsonPath('data.active', true);
    $this->withToken($key)->deleteJson("/api/v1/provisioning-targets/{$created['id']}")->assertNoContent();

    expect($read->getContent())->not->toContain('THE-DOWNSTREAM-CREDENTIAL')
        ->and(ProvisioningConnection::query()->whereKey($created['id'])->exists())->toBeFalse();

    foreach (['provisioning_connection.registered', 'provisioning_connection.paused', 'provisioning_connection.resumed', 'provisioning_connection.deleted'] as $action) {
        [$entry] = govTrail($action);

        expect($entry->actor_type)->toBe(ActorType::Service, $action)
            ->and($entry->actor_id)->toBe($keyId, $action)
            ->and(json_encode($entry->context))->not->toContain('THE-DOWNSTREAM-CREDENTIAL');
    }
})->group('security');

it('refuses client credentials with no token URL, and a target that is not a URL', function (): void {
    [$key] = govKey(['provisioning:write']);
    $org = govOrg();

    $refused = $this->withToken($key)->postJson('/api/v1/provisioning-targets', [...govTarget(['auth_scheme' => 'oauth2_client_credentials']), 'organization_id' => $org])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'incomplete_client_credentials');

    expect($refused->json('message'))->toContain('token URL')->toContain('client ID');

    $this->withToken($key)->postJson('/api/v1/provisioning-targets', [...govTarget(['base_url' => 'ftp://nope']), 'organization_id' => $org])
        ->assertUnprocessable()->assertJsonPath('error', 'invalid_url');

    expect(ProvisioningConnection::query()->count())->toBe(0);
});

it('answers 404 for another environment\'s target and refuses a read-only key', function (): void {
    [$key] = govKey(['provisioning:read', 'provisioning:write']);
    [$reader] = govKey(['provisioning:read']);
    $id = $this->withToken($key)->postJson('/api/v1/provisioning-targets', [...govTarget(), 'environment_wide' => true])->json('data.id');

    $elsewhere = govElsewhere(fn (): string => app(ProvisioningConnections::class)
        ->register(null, 'Elsewhere', 'https://scim.elsewhere.example/v2', AuthScheme::Bearer, 'tok')->connection->id);

    $this->withToken($key)->deleteJson("/api/v1/provisioning-targets/{$elsewhere}")->assertNotFound();
    $this->withToken($key)->getJson("/api/v1/provisioning-targets/{$id}?organization_id=".govOrg())->assertNotFound();
    $this->withToken($reader)->postJson("/api/v1/provisioning-targets/{$id}/status", ['active' => false])->assertForbidden();

    expect(ProvisioningConnection::query()->whereKey($id)->value('status'))->toBe(ConnectionStatus::Active)
        ->and(govElsewhere(fn (): bool => ProvisioningConnection::query()->whereKey($elsewhere)->exists()))->toBeTrue();
})->group('security');

// ── Role conflicts ───────────────────────────────────────────────────────────

it('defines, switches and removes a role-conflict rule, refusing a role the organization cannot hold', function (): void {
    [$key, $keyId] = govKey(['governance:read', 'governance:write']);
    $org = govOrg();
    $other = govOrg('acme-other');
    $a = app(Roles::class)->define($org, 'create-po');
    $b = app(Roles::class)->define($org, 'approve-pay');
    $theirs = app(Roles::class)->define($other, 'their-role');

    $this->withToken($key)->postJson('/api/v1/sod-policies', ['organization_id' => $org, 'name' => 'Maker/checker', 'role_ids' => [$a->id, $theirs->id]])
        ->assertUnprocessable()->assertJsonPath('error', 'unknown_role');
    $this->withToken($key)->postJson('/api/v1/sod-policies', ['organization_id' => $org, 'name' => 'Maker/checker', 'role_ids' => [$a->id]])
        ->assertUnprocessable()->assertJsonValidationErrors(['role_ids']);

    $rule = $this->withToken($key)->postJson('/api/v1/sod-policies', ['organization_id' => $org, 'name' => 'Maker/checker', 'role_ids' => [$a->id, $b->id]])
        ->assertCreated()
        ->assertJsonPath('data.active', true)
        ->json('data');

    $this->withToken($key)->getJson('/api/v1/sod-policies?organization_id='.$org)->assertOk()->assertJsonPath('data.0.id', $rule['id']);
    $this->withToken($key)->postJson("/api/v1/sod-policies/{$rule['id']}/status", ['active' => false])->assertOk()->assertJsonPath('data.active', false);
    $this->withToken($key)->getJson("/api/v1/sod-policies/{$rule['id']}?organization_id={$other}")->assertNotFound();
    $this->withToken($key)->deleteJson("/api/v1/sod-policies/{$rule['id']}")->assertNoContent();

    expect(SodPolicy::query()->whereKey($rule['id'])->exists())->toBeFalse();

    foreach (['sod.policy_defined', 'sod.policy_deactivated', 'sod.policy_deleted'] as $action) {
        [$entry] = govTrail($action);

        expect($entry->actor_type)->toBe(ActorType::Service, $action)->and($entry->actor_id)->toBe($keyId, $action);
    }
});

it('lets an organization administrator see, but never switch or remove, an environment-wide rule', function (): void {
    [$subjectId, $orgId] = govOrgAdmin();
    $a = app(Roles::class)->define($orgId, 'create-po');
    $b = app(Roles::class)->define($orgId, 'approve-pay');
    $environmentWide = app(SegregationOfDuties::class)->definePolicy(null, 'Everywhere', [$a->id, $b->id]);

    $this->from(route('sod-policies.create'))->post(route('sod-policies.store'), [
        'name' => 'Ours', 'description' => '', 'roles' => [$a->id, $b->id], 'environmentWide' => false,
    ])->assertSessionHasNoErrors();

    $ours = SodPolicy::query()->where('organization_id', $orgId)->sole();

    $this->delete(route('sod-policies.destroy', $ours->id))->assertRedirect(route('sod-policies'));
    $this->post(route('sod-policies.toggle', $environmentWide->id))->assertNotFound();
    $this->delete(route('sod-policies.destroy', $environmentWide->id))->assertNotFound();

    [$deleted] = govTrail('sod.policy_deleted');

    expect($deleted->actor_id)->toBe($subjectId)
        ->and($deleted->actor_type)->not->toBe(ActorType::Service)
        ->and($deleted->organization_id)->toBe($orgId)
        ->and(SodPolicy::query()->whereKey($environmentWide->id)->value('active'))->toBeTrue();
})->group('security');

// ── Access reviews ───────────────────────────────────────────────────────────

it('opens a review, records a decision as the key, applies it on close, and will not close twice', function (): void {
    [$key, $keyId] = govKey(['governance:read', 'governance:write']);
    $org = govOrg();
    $role = app(Roles::class)->define($org, 'engineer');
    app(Roles::class)->assign($org, 'engineer-1', $role->id);

    $review = $this->withToken($key)->postJson('/api/v1/access-reviews', ['organization_id' => $org, 'name' => 'Q3 access'])
        ->assertCreated()
        ->assertJsonPath('data.open', true)
        ->assertJsonPath('data.staff', false)
        ->assertJsonPath('data.created_by', $keyId)
        ->json('data');

    $items = $this->withToken($key)->getJson("/api/v1/access-reviews/{$review['id']}/items")->assertOk()->json('data');
    $roleItem = collect($items)->firstWhere('access_type', AccessKind::Role->value);

    $this->withToken($key)->postJson("/api/v1/access-reviews/{$review['id']}/items/{$roleItem['id']}", ['decision' => 'revoked'])
        ->assertOk()
        ->assertJsonPath('data.decision', 'revoked')
        ->assertJsonPath('data.decided_by', $keyId);

    expect(app(Roles::class)->assignmentsForSubject($org, 'engineer-1'))->not->toBe([]);

    $this->withToken($key)->postJson("/api/v1/access-reviews/{$review['id']}/close")->assertOk()->assertJsonPath('data.status', 'closed');
    $this->withToken($key)->postJson("/api/v1/access-reviews/{$review['id']}/close")->assertStatus(409)->assertJsonPath('error', 'review_closed');

    expect(app(Roles::class)->assignmentsForSubject($org, 'engineer-1'))->toBe([]);

    [$closed] = govTrail('governance.campaign_closed');

    expect($closed->actor_type)->toBe(ActorType::Service)->and($closed->actor_id)->toBe($keyId);
});

it('keeps one organization\'s review out of another\'s reach, and staff reviews out of a tenant\'s', function (): void {
    [$key] = govKey(['governance:read', 'governance:write']);
    $org = govOrg();
    $other = govOrg('acme-other');

    $staff = $this->withToken($key)->postJson('/api/v1/access-reviews', ['covers' => 'staff', 'name' => 'Staff roles'])
        ->assertCreated()->assertJsonPath('data.staff', true)->json('data.id');
    $theirs = app(AccessReviews::class)->open($other, 'Theirs');

    $this->withToken($key)->getJson("/api/v1/access-reviews/{$theirs->id}?organization_id={$org}")->assertNotFound();
    $this->withToken($key)->postJson("/api/v1/access-reviews/{$theirs->id}/close", ['organization_id' => $org])->assertNotFound();
    $this->withToken($key)->postJson('/api/v1/access-reviews', ['name' => 'No one'])->assertUnprocessable()->assertJsonPath('error', 'organization_required');

    // A tenant's administrator sees neither the staff review nor another tenant's.
    govOrgAdmin();

    $this->get(route('governance.show', $staff))->assertNotFound();
    $this->post(route('governance.close', $theirs->id))->assertNotFound();
    $this->from(route('governance.create'))->post(route('governance.store'), ['name' => 'Sneaky', 'covers' => 'staff'])->assertForbidden();

    expect(CertificationCampaign::query()->whereKey($theirs->id)->value('closed_at'))->toBeNull();
})->group('security');

// ── The token vault ──────────────────────────────────────────────────────────

it('stores, grants, rotates and revokes a credential without ever returning or keeping its value', function (): void {
    [$key, $keyId] = govKey(['token_vault:read', 'token_vault:write']);
    $org = govOrg();
    $body = ['organization_id' => $org, 'name' => 'Stripe', 'provider' => 'stripe', 'secret' => 'sk_live_THE-STORED-VALUE'];

    $first = $this->withToken($key)->withHeader('Idempotency-Key', 'vault-1')->postJson('/api/v1/token-vault/secrets', $body)
        ->assertCreated()->assertJsonPath('data.organization_id', $org)->assertJsonPath('data.status', 'active');
    $this->withToken($key)->withHeader('Idempotency-Key', 'vault-1')->postJson('/api/v1/token-vault/secrets', $body)
        ->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    $this->flushHeaders();

    $id = (string) $first->json('data.id');

    $this->withToken($key)->postJson("/api/v1/token-vault/secrets/{$id}/grants", ['organization_id' => $org, 'client_id' => 'agent-1'])
        ->assertOk()->assertJsonPath('data.grants', ['agent-1']);
    $this->withToken($key)->postJson("/api/v1/token-vault/secrets/{$id}/rotate", ['organization_id' => $org, 'secret' => 'sk_live_THE-NEXT-VALUE'])->assertOk();
    $read = $this->withToken($key)->getJson("/api/v1/token-vault/secrets/{$id}?organization_id={$org}")->assertOk()->assertJsonPath('data.grants', ['agent-1']);
    $this->withToken($key)->deleteJson("/api/v1/token-vault/secrets/{$id}/grants/agent-1?organization_id={$org}")->assertNoContent();
    $this->withToken($key)->postJson("/api/v1/token-vault/secrets/{$id}/revoke", ['organization_id' => $org])->assertOk()->assertJsonPath('data.revoked', true);
    $this->withToken($key)->postJson("/api/v1/token-vault/secrets/{$id}/rotate", ['organization_id' => $org, 'secret' => 'x'])
        ->assertUnprocessable()->assertJsonPath('error', 'secret_revoked');

    $everything = $first->getContent().$read->getContent().json_encode(IdempotencyRecord::query()->get()->pluck('payload')->all());

    expect($everything)->not->toContain('THE-STORED-VALUE')->not->toContain('THE-NEXT-VALUE')
        ->and(VaultSecret::query()->count())->toBe(1);

    foreach (['vault.secret.stored', 'vault.grant.created', 'vault.secret.rotated', 'vault.grant.revoked', 'vault.secret.revoked'] as $action) {
        [$entry] = govTrail($action);

        expect($entry->actor_type)->toBe(ActorType::Service, $action)
            ->and($entry->actor_id)->toBe($keyId, $action)
            ->and(json_encode($entry->context))->not->toContain('THE-');
    }
})->group('security');

it('bounds the vault by its owner: another organization\'s secret, and the environment\'s own, are 404', function (): void {
    [$key] = govKey(['token_vault:read', 'token_vault:write']);
    [$reader] = govKey(['token_vault:read']);
    $org = govOrg();
    $other = govOrg('acme-other');
    $theirs = app(SecretVault::class)->store('Theirs', 'openai', 'sk-theirs', VaultOwner::organization($other));
    $environments = app(SecretVault::class)->store('Ours', 'openai', 'sk-env');

    $this->withToken($key)->postJson("/api/v1/token-vault/secrets/{$theirs->id}/grants", ['organization_id' => $org, 'client_id' => 'agent-1'])->assertNotFound();
    $this->withToken($key)->postJson("/api/v1/token-vault/secrets/{$theirs->id}/revoke")->assertNotFound();
    $this->withToken($key)->getJson("/api/v1/token-vault/secrets/{$environments->id}?organization_id={$org}")->assertNotFound();
    $this->withToken($key)->getJson('/api/v1/token-vault/secrets')->assertOk()->assertJsonPath('data.0.id', $environments->id)->assertJsonCount(1, 'data');
    $this->withToken($reader)->postJson("/api/v1/token-vault/secrets/{$environments->id}/rotate", ['secret' => 'x'])->assertForbidden();

    expect(VaultSecret::query()->whereKey($theirs->id)->value('revoked_at'))->toBeNull();
})->group('security');

// ── A person's approval, before a Critical change ────────────────────────────

it('holds every Critical enterprise change for its owner\'s approval, and changes nothing meanwhile', function (string $method, string $path, array $body, array $scopes): void {
    $key = govSupervisedKey($scopes);
    $org = govOrg();

    $this->withToken($key)->json($method, str_replace('{org}', $org, $path), [...$body, 'organization_id' => $org])
        ->assertStatus(202)
        ->assertJsonPath('error', 'approval_required');

    expect(Directory::query()->count())->toBe(0)
        ->and(ProvisioningConnection::query()->count())->toBe(0)
        ->and(AuditEntry::query()->where('action', 'portal_link.created')->exists())->toBeFalse();
})->with([
    'a SCIM directory and its token' => ['POST', '/api/v1/directories', ['name' => 'Okta'], ['directory_sync:write']],
    'a downstream target for people\'s data' => ['POST', '/api/v1/provisioning-targets', ['name' => 'Slack', 'base_url' => 'https://scim.slack.example/v2', 'auth_scheme' => 'bearer', 'secret' => 't'], ['provisioning:write']],
    'an Admin Portal link' => ['POST', '/api/v1/organizations/{org}/portal-links', ['intents' => ['sso']], ['portal_links:write']],
])->group('security');

it('holds a change to how an organization signs in, and lets a Write through', function (): void {
    $key = govSupervisedKey(['sso:read', 'sso:write']);
    $org = govOrg();
    $connection = app(Connections::class)->create($org, ConnectionType::Saml, 'Okta', [
        'idp_entity_id' => 'https://idp.example', 'idp_sso_url' => 'https://idp.example/sso', 'idp_x509cert' => 'MIIC',
        'sp_entity_id' => 'https://sp.example', 'sp_acs_url' => 'https://sp.example/acs',
    ]);

    $this->withToken($key)->postJson("/api/v1/sso/connections/{$connection->id}/activate")->assertStatus(202)->assertJsonPath('error', 'approval_required');
    $this->withToken($key)->patchJson("/api/v1/sso/connections/{$connection->id}", ['idp_sso_url' => 'https://evil.example/sso'])->assertStatus(202);

    // Reading and a draft are not what the policy holds.
    $this->withToken($key)->getJson("/api/v1/sso/connections/{$connection->id}")->assertOk();
    $this->withToken($key)->postJson('/api/v1/sso/domains', ['organization_id' => $org, 'domain' => 'acme.com'])->assertCreated();

    expect(Connection::query()->whereKey($connection->id)->value('status')->value)->toBe('draft')
        ->and(app(Connections::class)->config(Connection::query()->findOrFail($connection->id))['idp_sso_url'])->toBe('https://idp.example/sso');
})->group('security');

// ── The console runs the same actions ────────────────────────────────────────

it('records an organization administrator\'s outbound and vault changes through the same actions', function (): void {
    [$subjectId, $orgId] = govOrgAdmin();

    $this->from(route('provisioning.create'))->post(route('provisioning.store'), [
        'name' => 'Downstream', 'baseUrl' => 'https://scim.example.test/v2', 'scheme' => 'bearer', 'secret' => 'tok_123',
        'environmentWide' => false, 'tokenUrl' => '', 'clientId' => '', 'scope' => '',
    ])->assertSessionHasNoErrors();

    $target = ProvisioningConnection::query()->where('organization_id', $orgId)->sole();

    $this->from(route('provisioning.show', $target->id))->post(route('provisioning.toggle', $target->id))->assertRedirect(route('provisioning.show', $target->id));
    $this->delete(route('provisioning.destroy', $target->id))->assertRedirect(route('provisioning'));

    foreach (['provisioning_connection.registered', 'provisioning_connection.paused', 'provisioning_connection.deleted'] as $action) {
        $entries = govTrail($action);

        expect($entries)->toHaveCount(1, "{$action} was not recorded")
            ->and($entries[0]->actor_id)->toBe($subjectId)
            ->and($entries[0]->actor_type)->not->toBe(ActorType::Service)
            ->and($entries[0]->organization_id)->toBe($orgId);
    }

    // The vault answers from the console's owner, never the row's: another tenant's
    // secret is not there to rotate.
    confirmConsoleStepUp();
    $theirs = app(SecretVault::class)->store('Theirs', 'openai', 'sk-theirs', VaultOwner::organization(govOrg('acme-other')));

    $this->from(route('vault.create'))->post(route('vault.store'), ['name' => 'Stripe', 'provider' => 'stripe', 'secret' => 'sk_live_console'])->assertSessionHasNoErrors();
    $this->post(route('vault.rotate', $theirs->id), ['secret' => 'sk_live_mine'])->assertNotFound();

    expect(VaultSecret::query()->where('owner_id', $orgId)->where('name', 'Stripe')->exists())->toBeTrue();
})->group('security');
