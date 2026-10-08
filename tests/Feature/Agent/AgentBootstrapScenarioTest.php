<?php

declare(strict_types=1);

use App\Platform\Actions\ActionTrail;
use App\Platform\Console\WebhookEventCatalogue;
use App\Platform\OAuth\RootDelegatedAccess;
use App\Platform\OrganizationActivity;
use Cbox\Id\Federation\Contracts\DnsResolver;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Testing\FakeDnsResolver;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Platform\ValueObjects\KeyProvenance;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\AgentDoor;

/*
|--------------------------------------------------------------------------
| "Agents can do everything": the programme's acceptance criterion, as tests.
|--------------------------------------------------------------------------
|
| Three things an agent must be able to do with nothing but a credential and a door, each
| run over the REST API AND over the MCP server with the SAME assertions ({@see AgentDoor}
| speaks each door's protocol; the scenario never asks which one it is on):
|
| 1. BOOTSTRAP. One workspace key stands up a project and an environment with its first key;
|    that key mints a narrower, supervised one; the narrow key builds an app and rotates its
|    secret with its owner's approval; it cannot climb out of its scopes; and revoking its
|    parent stops it.
| 2. THE STAGING TASK. "Create an app with redirects, an enterprise organization with SSO, a
|    domain and an Admin Portal link, and a webhook" — read back through the read actions,
|    never the database.
| 3. A PERSON'S AGENT. A token a person signed in at the platform root lists their
|    environments, acts in the one it names, and waits for them on a critical action.
|
| Every step is checked against the audit trail: who acted, and through which door.
*/

dataset('doors', [
    'over REST' => [fn (): AgentDoor => AgentDoor::rest()],
    'over MCP' => [fn (): AgentDoor => AgentDoor::mcp()],
]);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/** @return list<AuditEntry> An environment's trail for $action, oldest first. */
function agentTrail(string $environmentId, string $action): array
{
    return array_values(AuditEntry::query()->withoutGlobalScopes()
        ->where('environment_id', $environmentId)
        ->where('action', $action)
        ->orderBy('sequence')
        ->get()
        ->all());
}

/** @return list<AuditEntry> The workspace's own log for $action, oldest first. */
function agentWorkspaceLog(string $workspaceId, string $action): array
{
    return array_values(app(OrganizationActivity::class)->recent($workspaceId)
        ->filter(static fn (AuditEntry $entry): bool => $entry->action === $action)
        ->sortBy('sequence')
        ->all());
}

/** Assert $entry was written by $actorId as a service (a key), through $door. */
function expectByKey(?AuditEntry $entry, string $actorId, AgentDoor $door): void
{
    expect($entry)->not->toBeNull()
        ->and($entry?->actor_type)->toBe(ActorType::Service)
        ->and($entry?->actor_id)->toBe($actorId)
        ->and($entry?->context[ActionTrail::VIA] ?? null)->toBe($door->via);
}

// ── 1. Bootstrap ─────────────────────────────────────────────────────────────

it('bootstraps an environment from one workspace key, down to a supervised narrow key, and stops it with its parent', function (AgentDoor $door): void {
    $account = provisionAccount();
    $workspace = $account['organization'];
    $owner = $account['subjectId'];

    // Minted "in the console" by the workspace's owner: the person every approval down the
    // chain goes to.
    $workspaceKey = app(OrganizationApiKeys::class)->issue($workspace->id, 'Bootstrap agent', MembershipRole::Admin, null,
        ['workspace:read', 'projects:write', 'environments:write', 'keys:write'],
        new KeyProvenance(createdByType: 'organization_member', createdById: $owner),
    );
    $ws = $workspaceKey->plaintext;

    // (a) A project, and an environment under it with its first — broad — key.
    $project = $door->call($ws, 'projects.create', ['name' => 'Agent Product', 'environment_limit' => 2]);

    expect($project['status'])->toBe('ok')
        ->and($project['data']['name'])->toBe('Agent Product');

    $broadScopes = ['apps:read', 'apps:write', 'keys:read', 'keys:write', 'organizations:read', 'organizations:write'];
    $created = $door->call($ws, 'environments.create', [
        'name' => 'Production',
        'project_id' => $project['data']['id'],
        'initial_key' => ['name' => 'Broad', 'scopes' => $broadScopes],
    ]);

    expect($created['status'])->toBe('ok')
        ->and($created['data']['project_id'])->toBe($project['data']['id'])
        ->and($created['data']['initial_key']['scopes'])->toBe($broadScopes)
        ->and($created['data']['initial_key']['token'])->toStartWith('cbid_env_');

    $environmentId = $created['data']['id'];
    $broadId = $created['data']['initial_key']['id'];
    $broad = $created['data']['initial_key']['token'];

    // (b) On the environment's own host, the broad key mints a narrower, supervised one.
    serveOnTestHost(Environment::query()->findOrFail($environmentId));

    $minted = $door->call($broad, 'keys.create', [
        'name' => 'Narrow',
        'scopes' => ['apps:read', 'apps:write'],
        'require_approval' => ['min_danger' => 'critical'],
    ]);

    expect($minted['status'])->toBe('ok')
        ->and($minted['data']['scopes'])->toBe(['apps:read', 'apps:write'])
        ->and($minted['data']['parent_key_id'])->toBe($broadId)
        ->and($minted['data']['require_approval']['min_danger'])->toBe('critical')
        ->and($minted['data']['created_by'])->toBe(['type' => 'environment_key', 'id' => $broadId]);

    $narrowId = $minted['data']['id'];
    $narrow = $minted['data']['token'];

    // (c) The narrow key creates an app — critical, since it hands out a client secret, so it
    // waits for the owner — sets its redirect URIs, and rotates its secret.
    $held = $door->call($narrow, 'apps.create', ['name' => 'Agent app', 'type' => 'web', 'redirect_uris' => ['https://agent.example/callback']]);

    expect($held['status'])->toBe('approval_required')
        ->and($held['approval']['binding_code'])->toMatch('/^[0-9A-F]{4}$/');

    $app = $door->approve($held, $owner);

    expect($app['status'])->toBe('ok')
        ->and($app['data']['client_secret'])->toStartWith('csec_');

    $appId = $app['data']['id'];
    $redirects = ['https://agent.example/callback', 'https://agent.example/oauth/return'];
    $updated = $door->call($narrow, 'apps.update', ['id' => $appId, 'redirect_uris' => $redirects]);

    expect($updated['status'])->toBe('ok')
        ->and($updated['data']['redirect_uris'])->toBe($redirects);

    $rotation = $door->call($narrow, 'apps.secrets.rotate', ['id' => $appId, 'grace_seconds' => 0]);

    expect($rotation['status'])->toBe('approval_required');

    $rotated = $door->approve($rotation, $owner);

    expect($rotated['status'])->toBe('ok')
        ->and($rotated['data']['client_secret'])->toStartWith('csec_')
        ->and($rotated['data']['client_secret'])->not->toBe($app['data']['client_secret']);

    // Once: the same request again replays the answer without the secret, and runs nothing.
    $replayed = $door->call($narrow, 'apps.secrets.rotate', ['id' => $appId, 'grace_seconds' => 0], idempotencyKey: $rotation['request']['idempotency_key'], approvalId: $rotation['approval']['id']);

    expect($replayed['status'])->toBe('ok')
        ->and($replayed['data']['id'])->toBe($rotated['data']['id'])
        ->and($replayed['data']['client_secret'])->toBeNull();

    $secrets = $door->call($narrow, 'apps.secrets.list', ['id' => $appId]);

    expect($secrets['status'])->toBe('ok')
        ->and(collect($secrets['data'])->pluck('id')->all())->toContain($rotated['data']['id'])
        ->and(json_encode($secrets['data']))->not->toContain($rotated['data']['client_secret']);

    // (d) It cannot exceed its scopes, and it cannot mint keys at all.
    foreach ([
        ['organizations.list', []],
        ['keys.create', ['name' => 'Escape', 'scopes' => ['apps:read']]],
        ['keys.list', []],
    ] as [$action, $input]) {
        $refused = $door->call($narrow, $action, $input);

        expect($refused['status'])->toBe('refused', "{$action} ran with the narrow key")
            ->and($refused['error'])->toBe('forbidden');
    }

    // (e) Revoking the broad parent — from the workspace, by the key that minted it — stops
    // the narrow child it minted.
    expect($door->call($narrow, 'apps.list')['status'])->toBe('ok');

    $revoked = $door->call($ws, 'keys.environment.revoke', ['environment_id' => $environmentId, 'id' => $broadId]);

    expect($revoked['status'])->toBe('ok');

    foreach ([$broad, $narrow] as $dead) {
        $after = $door->call($dead, 'apps.list');

        expect($after['status'])->toBe('refused')
            ->and($after['error'])->toBe('unauthorized');
    }

    // (f) Every step on the trail, as the credential that took it, through this door.
    $workspaceKeyId = $workspaceKey->key->id;

    foreach (['organization.project_created', 'organization.environment_created'] as $action) {
        expectByKey(agentWorkspaceLog($workspace->id, $action)[0] ?? null, $workspaceKeyId, $door);
    }

    $keysCreated = collect(agentWorkspaceLog($workspace->id, 'organization.environment_key_created'))->keyBy(static fn (AuditEntry $entry): mixed => $entry->context['name'] ?? null);

    expectByKey($keysCreated['Broad'] ?? null, $workspaceKeyId, $door);
    expectByKey($keysCreated['Narrow'] ?? null, $broadId, $door);
    expect($keysCreated['Narrow']->context['parent_key_id'] ?? null)->toBe($broadId);

    foreach (['app.created', 'app.updated', 'app.secret_rotated'] as $action) {
        expectByKey(agentTrail($environmentId, $action)[0] ?? null, $narrowId, $door);
    }

    // The two that waited name the person who said yes, and the approval they spent.
    expect(agentTrail($environmentId, 'app.created')[0]->context)->toMatchArray([ActionTrail::APPROVED_BY => $owner, ActionTrail::APPROVAL => $held['approval']['id']])
        ->and(agentTrail($environmentId, 'app.secret_rotated')[0]->context)->toMatchArray([ActionTrail::APPROVED_BY => $owner, ActionTrail::APPROVAL => $rotation['approval']['id']])
        ->and(agentTrail($environmentId, 'app.secret_rotated'))->toHaveCount(1);

    // And nothing else in its name: the platform registering its own step-up client the
    // first time an approval was asked for is not the narrow key's act.
    expect(AuditEntry::query()->withoutGlobalScopes()->where('actor_id', $narrowId)->orderBy('sequence')->pluck('action')->all())
        ->toBe(['app.created', 'app.updated', 'app.secret_rotated']);

    $revocations = collect(agentWorkspaceLog($workspace->id, 'organization.environment_key_revoked'))->keyBy(static fn (AuditEntry $entry): mixed => $entry->context['key_id'] ?? null);

    expect($revocations->keys()->all())->toEqualCanonicalizing([$broadId, $narrowId]);
    expectByKey($revocations[$broadId], $workspaceKeyId, $door);
    expectByKey($revocations[$narrowId], $workspaceKeyId, $door);
    expect($revocations[$narrowId]->context['because_parent_revoked'] ?? null)->toBeTrue();
})->with('doors');

// ── 2. The staging task: an enterprise customer ──────────────────────────────

it('sets up an enterprise customer: an app, an organization with SSO, a domain, a portal link and a webhook', function (AgentDoor $door): void {
    $dns = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $dns);
    app()->forgetInstance(DomainVerification::class);

    $environment = serveOnTestHost(provisionAccount()['environment']);
    $issued = app(EnvironmentApiKeys::class)->issue($environment->id, 'Staging agent', [
        'apps:read', 'apps:write',
        'organizations:read', 'organizations:write',
        'sso:read', 'sso:write',
        'portal_links:read', 'portal_links:write',
        'webhooks:read', 'webhooks:write',
    ]);
    $key = $issued->plaintext;

    // An app, with its redirects.
    $redirects = ['https://acme.example/callback'];
    $app = $door->call($key, 'apps.create', ['name' => 'Acme web', 'type' => 'web', 'redirect_uris' => $redirects]);

    expect($app['status'])->toBe('ok');

    // The customer's organization.
    $organization = $door->call($key, 'organizations.create', ['name' => 'Globex', 'slug' => 'globex']);

    expect($organization['status'])->toBe('ok');

    $orgId = $organization['data']['id'];

    // Its SSO connection: a SAML draft first — the service provider's details to hand the
    // customer's IT — then the identity provider's, read from its metadata.
    $draft = $door->call($key, 'sso.connections.create', ['organization_id' => $orgId, 'name' => 'Globex Okta', 'type' => 'saml', 'pending_idp' => true]);

    expect($draft['status'])->toBe('ok');

    $connectionId = $draft['data']['id'];
    $imported = $door->call($key, 'sso.saml_metadata.import', ['metadata' => agentIdpMetadata()]);

    expect($imported['status'])->toBe('ok')
        ->and($imported['data']['idp_entity_id'])->toBe('https://idp.globex.example/entity');

    $completed = $door->call($key, 'sso.connections.update', [
        'id' => $connectionId,
        ...array_intersect_key($imported['data'], array_flip(['idp_entity_id', 'idp_sso_url', 'idp_x509cert'])),
    ]);

    expect($completed['status'])->toBe('ok');

    // A domain, claimed and proven.
    $claimed = $door->call($key, 'organizations.domains.add', ['organization_id' => $orgId, 'domain' => 'globex.example']);

    expect($claimed['status'])->toBe('ok')
        ->and($claimed['data']['verified'])->toBeFalse();

    $dns->publish($claimed['data']['record_name'], $claimed['data']['record_value']);
    $verified = $door->call($key, 'organizations.domains.verify', ['organization_id' => $orgId, 'domain_id' => $claimed['data']['id']]);

    expect($verified['status'])->toBe('ok')
        ->and($verified['data']['verified'])->toBeTrue();

    // An Admin Portal link for the customer's IT, for SSO and directory sync.
    $link = $door->call($key, 'organizations.portal_links.create', ['organization_id' => $orgId, 'intents' => ['sso', 'dsync']]);

    expect($link['status'])->toBe('ok')
        ->and($link['data']['url'])->toContain('/setup/');

    // A webhook.
    $event = WebhookEventCatalogue::offered()[0];
    $webhook = $door->call($key, 'webhooks.create', ['url' => 'https://hooks.acme.example/in', 'event_types' => [$event], 'organization_id' => $orgId]);

    expect($webhook['status'])->toBe('ok');

    // ── The end state, read back the way an agent would check its work ──
    $readApp = $door->call($key, 'apps.get', ['id' => $app['data']['id']]);
    $readOrg = $door->call($key, 'organizations.get', ['id' => $orgId]);
    $readConnection = $door->call($key, 'sso.connections.get', ['id' => $connectionId]);
    $readDomains = $door->call($key, 'organizations.domains.list', ['organization_id' => $orgId]);
    $readLinks = $door->call($key, 'organizations.portal_links.list', ['organization_id' => $orgId]);
    $readWebhooks = $door->call($key, 'webhooks.list');

    expect($readApp['data'])->toMatchArray(['name' => 'Acme web', 'redirect_uris' => $redirects])
        ->and($readOrg['data'])->toMatchArray(['id' => $orgId, 'name' => 'Globex', 'slug' => 'globex'])
        ->and($readConnection['data'])->toMatchArray([
            'id' => $connectionId,
            'organization_id' => $orgId,
            'type' => 'saml',
            'status' => 'draft',
            'complete' => true,
        ])
        ->and($readConnection['data']['config'])->toMatchArray([
            'idp_entity_id' => 'https://idp.globex.example/entity',
            'idp_sso_url' => 'https://idp.globex.example/sso',
        ])
        ->and(json_encode($readConnection['data']))->not->toContain('X509Certificate')
        ->and($readDomains['data'])->toHaveCount(1)
        ->and($readDomains['data'][0])->toMatchArray(['domain' => 'globex.example', 'verified' => true])
        ->and($readLinks['data'])->toHaveCount(1)
        ->and($readLinks['data'][0])->toMatchArray(['id' => $link['data']['id'], 'intents' => ['sso', 'dsync']])
        ->and(collect($readWebhooks['data'])->firstWhere('id', $webhook['data']['id']))->toMatchArray([
            'url' => 'https://hooks.acme.example/in',
            'event_types' => [$event],
            'organization_id' => $orgId,
            'active' => true,
        ]);

    // Every write on the trail, in order, as the key, through this door — and nothing else.
    $writes = ['app.created', 'organization.created', 'sso_connection.created', 'sso_connection.updated', 'domain.added', 'domain.verified', 'portal_link.created', 'webhook.created'];
    $trail = AuditEntry::query()->withoutGlobalScopes()->where('environment_id', $environment->id)->orderBy('sequence')->get();

    expect($trail->pluck('action')->all())->toBe($writes);

    foreach ($trail as $entry) {
        expectByKey($entry, $issued->key->id, $door);
    }
})->with('doors');

// ── 3. A person's agent, signed in at the platform root ──────────────────────

it('lets a person\'s root token list their environments, act in the one it names, and wait for them on a critical action', function (AgentDoor $door): void {
    Mail::fake();
    multiTenantDeployment((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    config(['cbox-id.oauth.dynamic_registration.mode' => 'mcp', 'api.mcp.root_oauth' => true]);

    $account = provisionAccount('owner@acme.example');
    $person = $account['subjectId'];
    $staging = Environment::query()->create([
        'name' => 'Staging',
        'slug' => 'acme-staging',
        'project_id' => $account['project']->id,
        'type' => EnvironmentType::Sandbox,
        'status' => EnvironmentStatus::Active,
        'is_default' => false,
        'settings' => [],
    ]);

    $token = agentRootToken($person);

    // Which environments may this agent act in?
    $environments = $door->environments($token);

    expect(collect($environments)->pluck('slug')->all())->toContain($account['environment']->slug, 'acme-staging');

    // An ordinary write, in the environment it names.
    $organization = $door->call($token, 'organizations.create', ['name' => 'Initech', 'slug' => 'initech'], environment: 'acme-staging');

    expect($organization['status'])->toBe('ok');

    $read = $door->call($token, 'organizations.get', ['id' => $organization['data']['id']], environment: 'acme-staging');

    expect($read['data']['name'])->toBe('Initech')
        // …and in that environment only.
        ->and($door->call($token, 'organizations.get', ['id' => $organization['data']['id']], environment: $account['environment']->id)['error'])->toBe('not_found');

    // A critical one waits for the person, on their device.
    $held = $door->call($token, 'apps.create', ['name' => 'Initech portal', 'type' => 'web', 'redirect_uris' => ['https://initech.example/callback']], environment: 'acme-staging');

    expect($held['status'])->toBe('approval_required');

    $app = $door->approve($held, $person);

    expect($app['status'])->toBe('ok')
        ->and($app['data']['name'])->toBe('Initech portal');

    // Recorded in that environment, as the person, through this door.
    foreach (['organization.created', 'app.created'] as $action) {
        $entry = agentTrail($staging->id, $action)[0] ?? null;

        expect($entry)->not->toBeNull()
            ->and($entry?->actor_id)->toBe($person)
            ->and($entry?->context[ActionTrail::VIA] ?? null)->toBe($door->via);
    }

    expect(agentTrail($staging->id, 'app.created')[0]->context[ActionTrail::APPROVED_BY] ?? null)->toBe($person)
        ->and(agentTrail($account['environment']->id, 'organization.created'))->toBe([]);
})->with('doors');

/*
|--------------------------------------------------------------------------
| Fixtures
|--------------------------------------------------------------------------
*/

/** A SAML IdP's metadata document, with a freshly made signing certificate. */
function agentIdpMetadata(): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'idp.globex.example'], $key);
    $x509 = openssl_csr_sign($csr, null, $key, 1);
    openssl_x509_export($x509, $pem);
    $cert = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem);

    return '<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="https://idp.globex.example/entity">'
        .'<md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">'
        .'<md:KeyDescriptor use="signing"><ds:KeyInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#">'
        ."<ds:X509Data><ds:X509Certificate>{$cert}</ds:X509Certificate></ds:X509Data></ds:KeyInfo></md:KeyDescriptor>"
        .'<md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="https://idp.globex.example/sso"/>'
        .'</md:IDPSSODescriptor></md:EntityDescriptor>';
}

/**
 * A token for the root's `/mcp`, minted for $subjectId the way Claude Code gets one: it
 * registers itself, sends the person to sign in at the root and consent, and redeems the
 * code — the flow {@see RootMcpOAuthTest} proves step by step.
 */
function agentRootToken(string $subjectId): string
{
    $callback = 'http://127.0.0.1:41917/callback';
    $resource = app(RootDelegatedAccess::class)->resource();

    expect($resource)->not->toBeNull();

    $clientId = (string) test()->postJson('/oauth/register', [
        'client_name' => 'Claude Code',
        'redirect_uris' => [$callback],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'token_endpoint_auth_method' => 'none',
    ])->assertCreated()->json('client_id');

    signInAsMember($subjectId);

    $props = (array) authorizeRequest([
        'client_id' => $clientId,
        'redirect_uri' => $callback,
        'scope' => implode(' ', [...($resource->scopes ?? []), 'offline_access']),
        'resource' => (string) $resource?->identifier,
    ])->assertOk()->inertiaProps();

    parse_str((string) parse_url((string) leftFor(answerConsent($props)), PHP_URL_QUERY), $query);

    $token = (string) test()->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $query['code'] ?? null,
        'redirect_uri' => $callback,
        'client_id' => $clientId,
        'code_verifier' => pkcePair()['verifier'],
        'resource' => (string) $resource?->identifier,
    ])->assertOk()->json('access_token');

    // The agent holds the token, not the browser session the person consented in.
    forgetSubjectSession();

    return $token;
}
