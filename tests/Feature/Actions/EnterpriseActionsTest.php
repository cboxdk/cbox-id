<?php

declare(strict_types=1);

use App\Platform\Actions\Idempotency\IdempotencyRecord;
use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\Directory\Enums\DirectoryStatus;
use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\Directory\Models\DirectoryGroup;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Enums\ConnectionStatus;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\Testing\InteractsWithFederation;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Illuminate\Support\Str;
use Inertia\Support\SessionKey;

/*
|--------------------------------------------------------------------------
| Enterprise SSO, its domains, directory sync and Admin Portal links, as actions.
|--------------------------------------------------------------------------
|
| Each is a management API endpoint and the console's own write, running the same action:
| the same tenant fence, the same refusals and the same line on the trail — the key over the
| API, the person in the console. Most of these writes recorded nothing at all before.
| Every request here is the test environment's (`env_test`) unless it says otherwise.
*/

uses(InteractsWithFederation::class);

const ENT_SSO = ['sso:read', 'sso:write'];

const ENT_DIRECTORY = ['directory_sync:read', 'directory_sync:write'];

/**
 * A management key in the test environment holding exactly $scopes.
 *
 * @param  list<string>  $scopes
 * @return array{0: string, 1: string} the key, and its id
 */
function entKey(array $scopes, string $environmentId = 'env_test'): array
{
    $issued = app(EnvironmentApiKeys::class)->issue($environmentId, 'Enterprise worker', $scopes);

    return [$issued->plaintext, (string) $issued->key->id];
}

function entOrg(string $slug = 'acme-enterprise'): string
{
    return app(Organizations::class)->create(new NewOrganization('Acme', $slug.'-'.Str::lower(Str::random(4))))->id;
}

/** @return list<AuditEntry> */
function entTrail(string $action): array
{
    return array_values(AuditEntry::query()->where('action', $action)->orderBy('id')->get()->all());
}

/** Run in ANOTHER environment than the one every request here resolves to. */
function entElsewhere(Closure $callback): mixed
{
    return app(EnvironmentContext::class)->runAs(GenericEnvironment::of('env_other'), $callback);
}

/**
 * An organization's administrator, signed in to its own console.
 *
 * @return array{0: string, 1: string} subject id, organization id
 */
function entOrgAdmin(string $slug = 'acme-console'): array
{
    $subject = app(Subjects::class)->create("admin@{$slug}.test", 'Enterprise Admin', 'supersecret123');
    app(Subjects::class)->markEmailVerified($subject->id, (string) $subject->email);
    $subject = app(Subjects::class)->find($subject->id) ?? $subject;

    $org = app(Organizations::class)->create(new NewOrganization('Acme', $slug));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);
    session([PlatformAuth::SESSION_KEY => $session->id]);

    return [$subject->id, $org->id];
}

/** @return array<string, string> */
function entSaml(array $changes = []): array
{
    return [
        'name' => 'Okta',
        'type' => 'saml',
        'idp_entity_id' => 'https://idp.acme.example/entity',
        'idp_sso_url' => 'https://idp.acme.example/sso',
        'idp_x509cert' => 'MIIC-THE-SEALED-CERTIFICATE',
        'sp_entity_id' => 'https://sp.acme.example',
        'sp_acs_url' => 'https://sp.acme.example/acs',
        ...$changes,
    ];
}

function entMetadataXml(): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'idp.example'], $key);
    $x509 = openssl_csr_sign($csr, null, $key, 1);
    openssl_x509_export($x509, $pem);
    $cert = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem);

    return '<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="https://idp.example/entity">'
        .'<md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">'
        .'<md:KeyDescriptor use="signing"><ds:KeyInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#">'
        ."<ds:X509Data><ds:X509Certificate>{$cert}</ds:X509Certificate></ds:X509Data></ds:KeyInfo></md:KeyDescriptor>"
        .'<md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="https://idp.example/sso"/>'
        .'</md:IDPSSODescriptor></md:EntityDescriptor>';
}

// ── SSO connections ──────────────────────────────────────────────────────────

it('connects an organization\'s identity provider as a draft, never returns the certificate, and records the key', function (): void {
    [$key, $keyId] = entKey(ENT_SSO);
    $org = entOrg();

    $created = $this->withToken($key)->postJson('/api/v1/sso/connections', [...entSaml(), 'organization_id' => $org])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.active', false)
        ->assertJsonPath('data.organization_id', $org)
        ->assertJsonPath('data.config.idp_sso_url', 'https://idp.acme.example/sso')
        ->assertJsonMissingPath('data.config.idp_x509cert')
        ->json('data');

    $this->withToken($key)->getJson('/api/v1/sso/connections?organization_id='.$org)->assertOk()->assertJsonPath('data.0.id', $created['id']);
    $read = $this->withToken($key)->getJson("/api/v1/sso/connections/{$created['id']}")->assertOk();

    expect($read->getContent())->not->toContain('MIIC-THE-SEALED-CERTIFICATE');

    $this->withToken($key)->postJson("/api/v1/sso/connections/{$created['id']}/activate")->assertOk()->assertJsonPath('data.active', true);
    $this->withToken($key)->postJson("/api/v1/sso/connections/{$created['id']}/disable")->assertOk()->assertJsonPath('data.status', 'inactive');
    $this->withToken($key)->deleteJson("/api/v1/sso/connections/{$created['id']}")->assertNoContent();

    expect(Connection::query()->whereKey($created['id'])->exists())->toBeFalse();

    foreach (['sso_connection.created', 'connection.activated', 'sso_connection.disabled', 'sso_connection.deleted'] as $action) {
        [$entry] = entTrail($action);

        expect($entry->actor_type)->toBe(ActorType::Service, $action)
            ->and($entry->actor_id)->toBe($keyId, $action)
            ->and(json_encode($entry->context))->not->toContain('MIIC-THE-SEALED-CERTIFICATE');
    }
})->group('security');

it('refuses an incomplete connection on every field at once, and an owner left unsaid', function (): void {
    [$key] = entKey(ENT_SSO);
    $org = entOrg();

    $refused = $this->withToken($key)->postJson('/api/v1/sso/connections', [...entSaml(['idp_x509cert' => '', 'sp_acs_url' => 'not a url']), 'organization_id' => $org])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'incomplete_connection');

    expect($refused->json('message'))->toContain('signing certificate')->toContain('ACS URL');

    $this->withToken($key)->postJson('/api/v1/sso/connections', entSaml())
        ->assertUnprocessable()->assertJsonPath('error', 'owner_required');

    expect(Connection::query()->count())->toBe(0);
});

it('changes settings without losing the sealed certificate or writing it to the trail', function (): void {
    [$key] = entKey(ENT_SSO);
    $org = entOrg();
    $id = $this->withToken($key)->postJson('/api/v1/sso/connections', [...entSaml(), 'organization_id' => $org])->json('data.id');

    $this->withToken($key)->patchJson("/api/v1/sso/connections/{$id}", ['name' => 'Okta EU', 'idp_sso_url' => 'https://eu.idp.acme.example/sso'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Okta EU')
        ->assertJsonPath('data.config.idp_sso_url', 'https://eu.idp.acme.example/sso');

    $config = app(Connections::class)->config(Connection::query()->findOrFail($id));
    [$entry] = entTrail('sso_connection.updated');

    expect($config['idp_x509cert'] ?? null)->toBe('MIIC-THE-SEALED-CERTIFICATE')
        ->and($entry->context['changed'])->toEqualCanonicalizing(['idp_sso_url', 'name'])
        ->and(json_encode($entry->context))->not->toContain('MIIC');
});

it('requires SSO for the connection\'s organization, and refuses an environment-owned connection', function (): void {
    [$key, $keyId] = entKey(ENT_SSO);
    $org = entOrg();
    $theirs = app(Connections::class)->create($org, ConnectionType::Saml, 'Okta', entSaml());
    $environments = app(Connections::class)->create(null, ConnectionType::Saml, 'Staff IdP', entSaml());

    $this->withToken($key)->postJson("/api/v1/sso/connections/{$theirs->id}/require-sso")
        ->assertOk()
        ->assertJsonPath('data.organization_id', $org)
        ->assertJsonPath('data.policy.sso', 'required');

    $this->withToken($key)->postJson("/api/v1/sso/connections/{$environments->id}/require-sso")
        ->assertUnprocessable()->assertJsonPath('error', 'environment_connection');

    [$entry] = entTrail('auth_policy.updated');

    expect(app(AuthPolicies::class)->overrideFor($org)?->sso)->toBe(SsoEnforcement::Required)
        ->and(app(AuthPolicies::class)->forEnvironment()->sso)->not->toBe(SsoEnforcement::Required)
        ->and($entry->actor_id)->toBe($keyId)
        ->and($entry->context['connection_id'])->toBe($theirs->id);
})->group('security');

it('answers 404 for another environment\'s connection, and for one outside the organization it narrowed to', function (): void {
    [$key] = entKey(ENT_SSO);
    $org = entOrg();
    $other = entOrg('acme-other');
    $theirs = app(Connections::class)->create($other, ConnectionType::Saml, 'Theirs', entSaml());
    $elsewhere = entElsewhere(fn (): Connection => app(Connections::class)->create(null, ConnectionType::Saml, 'Elsewhere', entSaml()));

    $this->withToken($key)->getJson("/api/v1/sso/connections/{$elsewhere->id}")->assertNotFound();
    $this->withToken($key)->deleteJson("/api/v1/sso/connections/{$elsewhere->id}")->assertNotFound();
    $this->withToken($key)->patchJson("/api/v1/sso/connections/{$theirs->id}", ['name' => 'Mine now', 'organization_id' => $org])->assertNotFound();
    $this->withToken($key)->getJson('/api/v1/sso/connections?organization_id=org_missing')->assertUnprocessable()->assertJsonPath('error', 'organization_not_found');

    expect(Connection::query()->whereKey($theirs->id)->value('name'))->toBe('Theirs')
        ->and(entElsewhere(fn (): bool => Connection::query()->whereKey($elsewhere->id)->exists()))->toBeTrue();
})->group('security');

it('refuses a key without the scope, reads included', function (): void {
    [$reader] = entKey(['sso:read']);
    [$nothing] = entKey(['directory_sync:read']);
    $org = entOrg();

    $this->withToken($reader)->postJson('/api/v1/sso/connections', [...entSaml(), 'organization_id' => $org])->assertForbidden();
    $this->withToken($reader)->postJson('/api/v1/sso/domains', ['organization_id' => $org, 'domain' => 'acme.com'])->assertForbidden();
    $this->withToken($nothing)->getJson('/api/v1/sso/connections')->assertForbidden();
    $this->withToken($nothing)->getJson('/api/v1/sso/domains')->assertForbidden();
    $this->withToken($reader)->postJson("/api/v1/organizations/{$org}/portal-links", ['intents' => ['sso']])->assertForbidden();

    expect(Connection::query()->count())->toBe(0);
})->group('security');

it('reads SAML metadata into the connection\'s fields, and maps a bad document to the metadata field', function (): void {
    [$key] = entKey(ENT_SSO);

    $this->withToken($key)->postJson('/api/v1/sso/saml-metadata', ['metadata' => entMetadataXml()])
        ->assertOk()
        ->assertJsonPath('data.idp_entity_id', 'https://idp.example/entity')
        ->assertJsonPath('data.idp_sso_url', 'https://idp.example/sso');

    $this->withToken($key)->postJson('/api/v1/sso/saml-metadata', ['metadata' => '<garbage>'])
        ->assertUnprocessable()->assertJsonPath('error', 'invalid_metadata');

    $this->withToken($key)->postJson('/api/v1/sso/saml-metadata', [])
        ->assertUnprocessable()->assertJsonValidationErrors(['metadata']);

    // The console runs the same action: its refusal lands on the form's own field.
    entOrgAdmin('meta-console');

    $this->from(route('connections.create'))->post(route('connections.import'), ['metadata' => '<garbage>'])
        ->assertSessionHasErrors('metadata');
    $this->from(route('connections.create'))->post(route('connections.import'), ['metadata' => entMetadataXml()])
        ->assertSessionHasNoErrors();

    $flash = session()->get(SessionKey::FLASH_DATA, []);

    expect(is_array($flash) ? ($flash['metadata']['idp_entity_id'] ?? null) : null)->toBe('https://idp.example/entity');
});

// ── Domains ──────────────────────────────────────────────────────────────────

it('claims, proves, captures and gives up a domain, refusing to capture one nobody has proven', function (): void {
    [$key, $keyId] = entKey(ENT_SSO);
    $org = entOrg();
    $dns = $this->fakeDns();

    $domain = $this->withToken($key)->postJson('/api/v1/sso/domains', ['organization_id' => $org, 'domain' => 'ACME.com'])
        ->assertCreated()
        ->assertJsonPath('data.domain', 'acme.com')
        ->assertJsonPath('data.verified', false)
        ->assertJsonPath('data.verification.type', 'TXT')
        ->json('data');

    $this->withToken($key)->postJson("/api/v1/sso/domains/{$domain['id']}/capture", ['capture' => true])
        ->assertForbidden()->assertJsonPath('error', 'domain_unverified');

    $this->withToken($key)->postJson("/api/v1/sso/domains/{$domain['id']}/verify")->assertOk()->assertJsonPath('data.verified', false);

    $dns->publish($domain['verification']['name'], $domain['verification']['value']);

    $this->withToken($key)->postJson("/api/v1/sso/domains/{$domain['id']}/verify")
        ->assertOk()->assertJsonPath('data.verified', true)->assertJsonPath('data.verification', null);
    $this->withToken($key)->postJson("/api/v1/sso/domains/{$domain['id']}/capture", ['capture' => true])
        ->assertOk()->assertJsonPath('data.capture', true);

    // Somebody else's claim on the same domain lands on the field it is about.
    $this->withToken($key)->postJson('/api/v1/sso/domains', ['organization_id' => entOrg('acme-rival'), 'domain' => 'acme.com'])
        ->assertUnprocessable()->assertJsonPath('error', 'domain_claimed');
    $this->withToken($key)->postJson('/api/v1/sso/domains', ['organization_id' => $org, 'domain' => 'not a domain'])
        ->assertUnprocessable()->assertJsonPath('error', 'invalid_domain');

    $this->withToken($key)->getJson('/api/v1/sso/domains?organization_id='.$org)->assertOk()->assertJsonPath('data.0.capture', true);
    $this->withToken($key)->deleteJson("/api/v1/sso/domains/{$domain['id']}")->assertNoContent();

    expect(app(DomainVerification::class)->forOrganization($org))->toBe([]);

    [$verified] = entTrail('domain.verified');

    expect($verified->actor_type)->toBe(ActorType::Service)->and($verified->actor_id)->toBe($keyId);
});

// ── Admin Portal links ───────────────────────────────────────────────────────

it('mints a one-time Admin Portal link, shown once and never kept for a replay', function (): void {
    [$key, $keyId] = entKey(['portal_links:write']);
    $org = entOrg();

    $first = $this->withToken($key)->withHeader('Idempotency-Key', 'portal-1')
        ->postJson("/api/v1/organizations/{$org}/portal-links", ['intents' => ['sso', 'dsync']])
        ->assertCreated()
        ->assertJsonPath('data.organization_id', $org)
        ->assertJsonPath('data.intents', ['sso', 'dsync']);
    $again = $this->withToken($key)->withHeader('Idempotency-Key', 'portal-1')
        ->postJson("/api/v1/organizations/{$org}/portal-links", ['intents' => ['sso', 'dsync']])
        ->assertCreated();

    $url = (string) $first->json('data.url');

    expect($url)->toContain('/setup/')
        ->and($again->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($again->json('data.url'))->toBeNull()
        ->and($again->json('data.id'))->toBe($first->json('data.id'))
        ->and(json_encode(IdempotencyRecord::query()->sole()->payload))->not->toContain(basename($url));

    [$entry] = entTrail('portal_link.created');

    expect($entry->actor_type)->toBe(ActorType::Service)
        ->and($entry->actor_id)->toBe($keyId)
        ->and($entry->organization_id)->toBe($org);

    $this->flushHeaders();
    $this->withToken($key)->postJson('/api/v1/organizations/org_missing/portal-links', ['intents' => ['sso']])->assertNotFound();
})->group('security');

it('refuses a portal link for a feature the organization\'s plan does not include', function (): void {
    config(['cbox-id.entitlements.mode' => 'metered']);
    [$key] = entKey(['portal_links:write']);

    $this->withToken($key)->postJson('/api/v1/organizations/'.entOrg().'/portal-links', ['intents' => ['dsync']])
        ->assertForbidden()->assertJsonPath('error', 'not_entitled');

    expect(entTrail('portal_link.created'))->toBe([]);
});

// ── Directory sync ───────────────────────────────────────────────────────────

it('registers a SCIM directory and re-keys it, each token shown once and never kept for a replay', function (): void {
    [$key, $keyId] = entKey(ENT_DIRECTORY);
    $org = entOrg();
    $body = ['organization_id' => $org, 'name' => 'Okta SCIM'];

    $created = $this->withToken($key)->withHeader('Idempotency-Key', 'dir-1')->postJson('/api/v1/directories', $body)
        ->assertCreated()
        ->assertJsonPath('data.provider', 'scim')
        ->assertJsonPath('data.pull', false)
        ->json('data');
    $replay = $this->withToken($key)->withHeader('Idempotency-Key', 'dir-1')->postJson('/api/v1/directories', $body)->assertCreated();

    expect($created['bearer_token'])->toStartWith('scim_')
        ->and($replay->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($replay->json('data.bearer_token'))->toBeNull()
        ->and(Directory::query()->count())->toBe(1);

    $this->flushHeaders();

    $rotated = $this->withToken($key)->withHeader('Idempotency-Key', 'rot-1')->postJson("/api/v1/directories/{$created['id']}/rotate")->assertOk()->json('data.bearer_token');
    $rotatedAgain = $this->withToken($key)->withHeader('Idempotency-Key', 'rot-1')->postJson("/api/v1/directories/{$created['id']}/rotate")->assertOk();

    expect($rotated)->toStartWith('scim_')->not->toBe($created['bearer_token'])
        ->and(Directory::query()->whereKey($created['id'])->value('bearer_token_hash'))->toBe(hash('sha256', $rotated))
        ->and($rotatedAgain->json('data.bearer_token'))->toBeNull();

    foreach (IdempotencyRecord::query()->get() as $record) {
        expect(json_encode($record->payload))->not->toContain($created['bearer_token'])->not->toContain($rotated);
    }

    $this->flushHeaders();
    $read = $this->withToken($key)->getJson("/api/v1/directories/{$created['id']}")->assertOk();
    $this->withToken($key)->getJson('/api/v1/directories?organization_id='.$org)->assertOk()->assertJsonMissingPath('data.0.bearer_token');

    expect($read->getContent())->not->toContain($rotated)->not->toContain('bearer_token');

    foreach (['directory.registered', 'directory.token_rotated'] as $action) {
        [$entry] = entTrail($action);

        expect($entry->actor_type)->toBe(ActorType::Service)
            ->and($entry->actor_id)->toBe($keyId)
            ->and(json_encode($entry->context))->not->toContain($created['bearer_token'])->not->toContain($rotated);
    }
})->group('security');

it('pauses, renames, maps and deletes a directory, refusing a group from another directory', function (): void {
    [$key] = entKey(ENT_DIRECTORY);
    $org = entOrg();
    $id = $this->withToken($key)->postJson('/api/v1/directories', ['organization_id' => $org, 'name' => 'Okta'])->json('data.id');
    $other = $this->withToken($key)->postJson('/api/v1/directories', ['organization_id' => $org, 'name' => 'Other'])->json('data.id');

    $foreignGroup = new DirectoryGroup;
    $foreignGroup->forceFill(['id' => (string) Str::ulid(), 'directory_id' => $other, 'display_name' => 'Finance'])->save();

    $this->withToken($key)->postJson("/api/v1/directories/{$id}/status", ['active' => false])->assertOk()->assertJsonPath('data.status', 'paused');
    $this->withToken($key)->patchJson("/api/v1/directories/{$id}", ['name' => 'Okta EU'])->assertOk()->assertJsonPath('data.name', 'Okta EU');
    $this->withToken($key)->postJson("/api/v1/directories/{$id}/group-roles", ['group_id' => $foreignGroup->id, 'role_id' => 'role_x', 'mapped' => true])->assertNotFound();
    $this->withToken($key)->getJson("/api/v1/directories/{$other}/groups")->assertOk()->assertJsonPath('data.0.name', 'Finance');
    $this->withToken($key)->postJson("/api/v1/directories/{$id}/rotate", [])->assertOk();
    $this->withToken($key)->deleteJson("/api/v1/directories/{$id}")->assertNoContent();

    expect(Directory::query()->whereKey($id)->exists())->toBeFalse()
        ->and(entTrail('directory.paused'))->toHaveCount(1)
        ->and(entTrail('directory.renamed'))->toHaveCount(1)
        ->and(entTrail('directory.deleted'))->toHaveCount(1);
});

it('answers 404 for another environment\'s directory, and refuses a key without the scope', function (): void {
    [$key] = entKey(ENT_DIRECTORY);
    [$reader] = entKey(['directory_sync:read']);
    $org = entOrg();
    $id = $this->withToken($key)->postJson('/api/v1/directories', ['organization_id' => $org, 'name' => 'Okta'])->json('data.id');

    $elsewhere = entElsewhere(function (): string {
        $directory = new Directory;
        $directory->forceFill(['id' => (string) Str::ulid(), 'organization_id' => 'org_elsewhere', 'name' => 'Elsewhere', 'provider' => 'scim', 'status' => DirectoryStatus::Active, 'bearer_token_hash' => hash('sha256', 'scim_elsewhere')])->save();

        return $directory->id;
    });

    $this->withToken($key)->postJson("/api/v1/directories/{$elsewhere}/rotate")->assertNotFound();
    $this->withToken($key)->getJson("/api/v1/directories/{$id}?organization_id=".entOrg('acme-other'))->assertNotFound();
    $this->withToken($reader)->postJson("/api/v1/directories/{$id}/rotate")->assertForbidden();
    $this->withToken($reader)->postJson('/api/v1/directories', ['organization_id' => $org, 'name' => 'Sneaky'])->assertForbidden();

    expect(Directory::query()->where('name', 'Sneaky')->exists())->toBeFalse();
})->group('security');

// ── The console runs the same actions, and leaves the same trail ─────────────

it('records an organization administrator\'s SSO and directory changes as theirs, and fences the rest out', function (): void {
    [$subjectId, $orgId] = entOrgAdmin();
    $other = entOrg('acme-other');
    $theirs = app(Connections::class)->create($other, ConnectionType::Saml, 'Theirs', entSaml());

    $this->from(route('connections.create'))->post(route('connections.store'), [...entSaml(), 'environmentWide' => false])
        ->assertSessionHasNoErrors();

    $mine = Connection::query()->where('organization_id', $orgId)->sole();
    $from = route('connections.show', $mine->id);

    $this->from($from)->patch(route('connections.update', $mine->id), ['name' => 'Okta EU', 'idp_sso_url' => 'https://eu.idp.acme.example/sso'])->assertSessionHasNoErrors();
    $this->from($from)->post(route('connections.disable', $mine->id))->assertRedirect($from);

    confirmConsoleStepUp();
    $this->from(route('directories.create'))->post(route('directories.store'), ['name' => 'Okta SCIM'])->assertSessionHasNoErrors();
    $directory = Directory::query()->where('organization_id', $orgId)->sole();
    $this->from(route('directories.show', $directory->id))->post(route('directories.toggle', $directory->id))->assertRedirect(route('directories.show', $directory->id));

    foreach (['sso_connection.created', 'sso_connection.updated', 'sso_connection.disabled', 'directory.registered', 'directory.paused'] as $action) {
        $entries = entTrail($action);

        expect($entries)->toHaveCount(1, "{$action} was not recorded")
            ->and($entries[0]->actor_id)->toBe($subjectId, $action)
            ->and($entries[0]->actor_type)->not->toBe(ActorType::Service)
            ->and($entries[0]->organization_id)->toBe($orgId);
    }

    expect(Connection::query()->whereKey($mine->id)->value('status'))->toBe(ConnectionStatus::Inactive)
        ->and(Directory::query()->whereKey($directory->id)->value('status'))->toBe(DirectoryStatus::Paused);

    // Another tenant's connection is not even visible from here.
    $this->post(route('connections.disable', $theirs->id))->assertNotFound();
    $this->delete(route('connections.destroy', $theirs->id))->assertNotFound();

    expect(Connection::query()->whereKey($theirs->id)->value('status'))->toBe(ConnectionStatus::Draft);
})->group('security');

it('records an environment administrator\'s console changes the way the API records a key\'s', function (): void {
    craftedEnvAdmin();
    $org = entOrg();

    $this->from(route('environment.connections.create'))->post(route('environment.connections.store'), [...entSaml(), 'environmentWide' => true])
        ->assertSessionHasNoErrors();

    $connection = Connection::query()->whereNull('organization_id')->sole();

    $this->from(route('environment.connections.show', $connection->id))->delete(route('environment.connections.destroy', $connection->id))
        ->assertRedirect(route('environment.connections'));

    $entries = [...entTrail('sso_connection.created'), ...entTrail('sso_connection.deleted')];

    expect($entries)->toHaveCount(2);

    foreach ($entries as $entry) {
        expect($entry->actor_type)->toBe(ActorType::OrganizationMember)->and($entry->organization_id)->toBeNull();
    }

    // And the same act from a key on this environment names the key.
    $environmentId = (string) app(EnvironmentContext::class)->current()?->environmentKey();
    [$key, $keyId] = entKey(ENT_SSO, $environmentId);

    $id = $this->withToken($key)->postJson('/api/v1/sso/connections', [...entSaml(), 'organization_id' => $org])->assertCreated()->json('data.id');

    $byKey = AuditEntry::query()->where('action', 'sso_connection.created')->where('target_id', $id)->sole();

    expect($byKey->actor_type)->toBe(ActorType::Service)->and($byKey->actor_id)->toBe($keyId);
});
