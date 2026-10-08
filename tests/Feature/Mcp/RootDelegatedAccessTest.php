<?php

declare(strict_types=1);

use App\Mcp\ActionTool;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Actions\Approvals\ActionApprovalRequest;
use App\Platform\OAuth\RootDelegatedAccess;
use App\Platform\OrganizationActivity;
use App\Support\CliClient;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\ProtectedResources;
use Cbox\Id\OAuthServer\Contracts\TokenIssuer;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Api;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\Models\DeviceCode;
use Cbox\Id\OAuthServer\Testing\InteractsWithOAuth;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Platform\Models\EnvironmentApiKey;
use Cbox\Id\Platform\Models\PlatformOperator;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| The people who run a hosted deployment, signed in at the platform root.
|--------------------------------------------------------------------------
|
| A workspace's team and the operators are subjects of the platform root: they reach a
| tenant's console through the signed handoff and are never its users, so no tenant issuer
| mints a token for them. Their token is the ROOT's, audienced to the root host's `/mcp`,
| and with it one connection reaches the workspace (as the member), every environment of it
| they could open the console of (named per call), their own account, and — an operator —
| the deployment. Each plane asks the token's scope AND the person's own right.
*/

uses(InteractsWithOAuth::class);

beforeEach(function (): void {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    Mail::fake();

    // The root's own host is the console host: an unmapped name resolves to the root, and
    // only the named one serves the console the device page is on.
    multiTenantDeployment((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    $this->account = provisionAccount('owner@acme.example');
});

/** The root's `/mcp` resource, read inside the root. */
function rootMcpResource(): string
{
    $resource = app(RootDelegatedAccess::class)->resource();

    expect($resource)->not->toBeNull();

    return (string) $resource?->identifier;
}

/** The root's CLI client — a platform-owned first-party client, as the installer provisions it. */
function rootCliClient(): Client
{
    return app(PlatformRoot::class)->run(fn (): Client => CliClient::provision(app(ClientRegistry::class))[0])
        ?? throw new LogicException('no platform root');
}

/**
 * A token the platform root issues $subjectId for its `/mcp`, as the device grant would.
 *
 * @param  list<string>|null  $scopes  null for every scope the root's `/mcp` accepts
 */
function rootToken(string $subjectId, ?array $scopes = null, ?string $workspaceId = null, ?string $dpopJkt = null): string
{
    $client = rootCliClient();

    return app(PlatformRoot::class)->run(function () use ($client, $subjectId, $scopes, $workspaceId, $dpopJkt): string {
        $resource = app(ProtectedResources::class)->forMetadataPath('/.well-known/oauth-protected-resource/mcp');

        return app(TokenIssuer::class)->issueForUser(
            $client,
            $subjectId,
            $workspaceId,
            ['openid', ...($scopes ?? $resource->scopes ?? [])],
            $resource?->identifier,
            $dpopJkt,
        )->token;
    }) ?? throw new LogicException('no platform root');
}

/** A second environment in the same workspace, reachable by the owner. */
function rootSecondEnvironment(array $account, string $slug = 'acme-staging'): Environment
{
    return Environment::query()->create([
        'name' => 'Staging',
        'slug' => $slug,
        'project_id' => $account['project']->id,
        'type' => EnvironmentType::Sandbox,
        'status' => EnvironmentStatus::Active,
        'is_default' => false,
        'settings' => [],
    ]);
}

/**
 * The APIs registered in $environment.
 *
 * @return list<string>
 */
function rootApisIn(Environment $environment): array
{
    return app(EnvironmentContext::class)->runAs($environment, static fn (): array => Api::query()->pluck('identifier')->all());
}

// ── Who gets in ─────────────────────────────────────────────────────────────────

it('takes a workspace member\'s root token at the root\'s /mcp and says who it acts as', function (): void {
    $token = rootToken($this->account['subjectId']);

    $me = mcpCall($token, 'whoami')['structuredContent'];

    expect($me)->toMatchArray([
        'kind' => 'person',
        'subject' => $this->account['subjectId'],
        'workspace' => ['id' => $this->account['organization']->id, 'name' => 'Acme', 'role' => 'owner'],
        'operator' => false,
        'client' => ['id' => rootCliClient()->client_id, 'name' => CliClient::NAME],
    ])->and(collect($me['environments'])->pluck('id')->all())->toBe([$this->account['environment']->id])
        ->and($me['scopes'])->toContain('team:write', 'apis:write', 'account:profile:write');
});

it('refuses a token from an environment\'s issuer at the root, and the root\'s token on an environment\'s host', function (): void {
    // The workspace's environment issues for ITS `/mcp`: another audience, another issuer.
    $environment = $this->account['environment'];
    $subject = $this->account['subjectId'];
    $foreign = app(EnvironmentContext::class)->runAs($environment, static function () use ($subject): string {
        $client = CliClient::provision(app(ClientRegistry::class))[0];
        $resource = app(ProtectedResources::class)->forMetadataPath('/.well-known/oauth-protected-resource/mcp');

        return app(TokenIssuer::class)->issueForUser($client, $subject, null, ['openid', 'apis:read'], $resource?->identifier)->token;
    });

    mcpRpc($foreign, 'tools/list')->assertUnauthorized();
    $this->withToken($foreign)->getJson('/api/v1/workspace')->assertUnauthorized();

    // …and the root's token on the environment's own host is that host's stranger.
    $token = rootToken($subject);
    serveOnTestHost($environment);

    mcpRpc($token, 'tools/list')->assertUnauthorized();
})->group('security');

it('refuses a DPoP-bound root token presented without its proof', function (): void {
    $token = rootToken($this->account['subjectId'], dpopJkt: 'NzbLsXh8uDCcd-6MNwXF4W_7noWXFZAfHkxZsRGC9Xs');

    mcpRpc($token, 'tools/list')->assertUnauthorized();
    $this->withToken($token)->getJson('/api/v1/workspace')->assertUnauthorized();
    $this->withToken($token)->patchJson('/api/v1/me/profile', ['name' => 'Ada'])->assertUnauthorized();
})->group('security');

// ── What it may do: the tools, per principal ────────────────────────────────────

it('lists the workspace\'s tools, every environment tool with an environment argument, and the account\'s', function (): void {
    $tools = mcpTools(rootToken($this->account['subjectId']));

    expect(array_keys($tools))->toContain('whoami', 'team_list', 'projects_create', 'apis_create', 'webhooks_list', 'account_profile_update')
        // The deployment is an operator's.
        ->not->toContain('platform_workspaces_create');

    // An environment tool names where to act; a workspace tool does not.
    expect($tools['apis_create']['inputSchema']['required'])->toContain(ActionTool::ENVIRONMENT)
        ->and($tools['apis_create']['inputSchema']['properties'])->toHaveKey(ActionTool::ENVIRONMENT)
        ->and($tools['team_list']['inputSchema']['properties'] ?? [])->not->toHaveKey(ActionTool::ENVIRONMENT);
});

it('reserves the environment argument: no environment action declares a field of that name', function (): void {
    foreach (app(ActionRegistry::class)->forPlane(ActionPlane::Environment) as $action) {
        expect(array_key_exists(ActionTool::ENVIRONMENT, (array) ($action->input()->jsonSchema()['properties'] ?? [])))
            ->toBeFalse("{$action->name} declares `environment`, which the root's tools use to name where to act.");
    }
});

it('lists only what the token\'s scopes and the member\'s role both allow', function (): void {
    [, $developer] = addMember($this->account['organization']->id, MembershipRole::Developer, 'dev@acme.example');
    [, $viewer] = addMember($this->account['organization']->id, MembershipRole::Viewer, 'viewer@acme.example');

    // A developer administers environments but may not read the team (PII).
    expect(array_keys(mcpTools(rootToken($developer))))->toContain('projects_create', 'apis_create')->not->toContain('team_list');

    // A viewer reads the team but administers no environment, whatever the token carries.
    expect(array_keys(mcpTools(rootToken($viewer))))->toContain('team_list')->not->toContain('apis_create')->not->toContain('projects_create');

    // An owner's token narrowed to one scope sees that scope's tools and the always-there three.
    expect(array_keys(mcpTools(rootToken($this->account['subjectId'], ['workspace:read']))))
        ->toEqualCanonicalizing(['whoami', 'list_actions', 'approval_status', 'workspace_get', 'projects_list', 'environments_list', 'keys_workspace_list']);
});

it('lists the deployment\'s tools to an operator, and nobody else', function (): void {
    $operator = actAsOperator('ops@platform.test');
    forgetSubjectSession();

    $token = rootToken((string) $operator->subject_id);

    expect(array_keys(mcpTools($token)))->toContain('platform_workspaces_create', 'platform_operators_create')
        ->and(mcpCall($token, 'whoami')['structuredContent']['operator'])->toBeTrue();
});

// ── The workspace plane, as the member ──────────────────────────────────────────

it('runs a workspace action as the member, recorded as the member', function (): void {
    $token = rootToken($this->account['subjectId']);

    expect(mcpCall($token, 'workspace_settings_update', ['name' => 'Acme Labs'])['isError'] ?? false)->toBeFalse();

    $entry = collect(app(OrganizationActivity::class)->recent($this->account['organization']->id))
        ->first(static fn (AuditEntry $entry): bool => $entry->action === 'organization.renamed');

    expect($entry?->actor_type)->toBe(ActorType::OrganizationMember)
        ->and($entry?->actor_id)->toBe($this->account['subjectId']);

    // Over REST, the same token and the same answer.
    $this->withToken($token)->getJson('/api/v1/workspace')->assertOk()->assertJsonPath('data.name', 'Acme Labs');
});

it('bounds a member\'s workspace token by their role, at both doors', function (): void {
    [, $developer] = addMember($this->account['organization']->id, MembershipRole::Developer, 'dev@acme.example');
    $token = rootToken($developer);

    $this->withToken($token)->getJson('/api/v1/workspace/members')
        ->assertForbidden()
        ->assertJsonPath('message', 'Your role in this workspace may not read-members.');

    // The scope is not enough either way: an owner's token without it is refused too.
    $this->withToken(rootToken($this->account['subjectId'], ['workspace:read']))
        ->getJson('/api/v1/workspace/members')
        ->assertForbidden()
        ->assertJsonPath('message', 'This sign-in was not granted the required scope: team:read.');
})->group('security');

it('still takes workspace keys at the root\'s /mcp, as the key', function (): void {
    $key = app(OrganizationApiKeys::class)->issue($this->account['organization']->id, 'Agent', MembershipRole::Admin)->plaintext;

    expect(mcpCall($key, 'whoami')['structuredContent'])->toMatchArray(['kind' => 'workspace_key'])
        ->and(array_keys(mcpTools($key)))->toContain('projects_create')->not->toContain('apis_create')->not->toContain('account_profile_update');
});

// ── An environment of the workspace, named per call ─────────────────────────────

it('runs an environment action in the environment it names, inside that environment', function (): void {
    $production = $this->account['environment'];
    $staging = rootSecondEnvironment($this->account);
    $token = rootToken($this->account['subjectId']);

    $created = mcpCall($token, 'apis_create', [
        'environment' => $staging->slug,
        'identifier' => 'https://staging-api.acme.example',
        'name' => 'Staging API',
    ]);

    expect($created['isError'] ?? false)->toBeFalse()
        ->and(rootApisIn($staging))->toBe(['https://staging-api.acme.example'])
        ->and(rootApisIn($production))->toBe([]);

    // By id as well as by slug.
    expect(mcpCall($token, 'apis_list', ['environment' => $production->id])['structuredContent']['data'])->toBe([]);

    // In that environment's own trail, as the member.
    $entry = app(EnvironmentContext::class)->runAs($staging, static fn (): ?AuditEntry => AuditEntry::query()->where('action', 'like', 'api%')->latest('id')->first());

    expect($entry?->actor_type)->toBe(ActorType::OrganizationMember)
        ->and($entry?->actor_id)->toBe($this->account['subjectId']);
});

it('refuses an environment the member cannot reach, another workspace\'s, and no environment at all', function (): void {
    $staging = rootSecondEnvironment($this->account);
    [, $scoped] = addMember($this->account['organization']->id, MembershipRole::Developer, 'dev@acme.example');
    $workspace = $this->account['organization']->id;
    app(PlatformRoot::class)->run(static fn () => app(Memberships::class)->setEnvironmentAccess($workspace, $scoped, false, [$staging->id]));

    $token = rootToken($scoped);

    // Not given production: not found, the console's answer for an environment it never showed.
    expect(mcpCall($token, 'apis_list', ['environment' => $this->account['environment']->id])['structuredContent'])
        ->toMatchArray(['error' => 'not_found', 'field' => 'environment'])
        ->and(mcpCall($token, 'apis_list', ['environment' => $staging->slug])['isError'] ?? false)->toBeFalse();

    // Another workspace's environment is not found either.
    $other = provisionAccount('rival@rival.example');

    expect(mcpCall(rootToken($this->account['subjectId']), 'apis_list', ['environment' => $other['environment']->id])['structuredContent']['error'])
        ->toBe('not_found');

    // The argument is required.
    expect(mcpCall($token, 'apis_list')['structuredContent'])->toMatchArray(['error' => 'validation_failed', 'field' => 'environment']);
})->group('security');

it('refuses a member whose role administers no environment, even with the scope', function (): void {
    [, $viewer] = addMember($this->account['organization']->id, MembershipRole::Viewer, 'viewer@acme.example');
    $token = rootToken($viewer);

    // Not listed, so not callable…
    expect(array_keys(mcpTools($token)))->not->toContain('apis_list');

    mcpRpc($token, 'tools/call', ['name' => 'apis_list', 'arguments' => ['environment' => $this->account['environment']->id]])
        ->assertJsonPath('error.code', -32602);

    // …and refused at the REST door, which binds the environment the same way.
    $this->withToken($token)
        ->getJson('/api/v1/apis', ['Cbox-Environment' => $this->account['environment']->id])
        ->assertForbidden()
        ->assertJsonPath('message', 'Your role in this workspace does not administer environments.');
})->group('security');

it('takes the environment in a header over REST, on the environment API\'s own routes', function (): void {
    $staging = rootSecondEnvironment($this->account);
    $token = rootToken($this->account['subjectId']);

    $this->withToken($token)->postJson('/api/v1/apis', ['identifier' => 'https://rest.acme.example', 'name' => 'REST API'], ['Cbox-Environment' => $staging->slug])
        ->assertCreated();

    expect(rootApisIn($staging))->toBe(['https://rest.acme.example']);

    $this->withToken($token)->getJson('/api/v1/apis', ['Cbox-Environment' => $staging->id])
        ->assertOk()
        ->assertJsonPath('data.0.identifier', 'https://rest.acme.example');

    // Without the header there is no environment to act in.
    $this->withToken($token)->getJson('/api/v1/apis')
        ->assertStatus(400)
        ->assertJsonPath('error', 'environment_required');

    // Another workspace's environment: not found.
    $other = provisionAccount('rival@rival.example');

    $this->withToken($token)->getJson('/api/v1/apis', ['Cbox-Environment' => $other['environment']->id])
        ->assertNotFound();
})->group('security');

// ── Critical waits for the person ───────────────────────────────────────────────

it('holds a critical workspace action for the person\'s approval in the root, then finishes it', function (): void {
    $subject = $this->account['subjectId'];
    $token = rootToken($subject);

    $arguments = ['name' => 'CI', 'role' => 'developer', 'idempotency_key' => 'ws-key-once'];
    $held = mcpCall($token, 'keys_workspace_create', $arguments)['structuredContent'];

    expect($held['status'] ?? null)->toBe('approval_pending');

    $approvalId = $held['approval']['id'];
    $request = app(PlatformRoot::class)->run(static fn (): ?BackchannelAuthRequest => BackchannelAuthRequest::query()->find($approvalId));

    expect($request?->user_id)->toBe($subject);

    app(PlatformRoot::class)->run(static fn () => app(BackchannelAuthentication::class)->approve($approvalId, $subject));

    $done = mcpCall($token, 'keys_workspace_create', [...$arguments, 'approval_id' => $approvalId]);

    expect($done['isError'] ?? false)->toBeFalse()
        ->and($done['structuredContent']['data'])->toHaveKey('token');
})->group('security');

it('holds a critical environment action for the person, and finds it again from the root', function (): void {
    $subject = $this->account['subjectId'];
    $environment = $this->account['environment'];
    $token = rootToken($subject);

    $arguments = ['environment' => $environment->slug, 'name' => 'Deploy bot', 'scopes' => ['apis:read']];
    $held = mcpCall($token, 'keys_create', $arguments)['structuredContent'];

    expect($held['status'] ?? null)->toBe('approval_pending');

    $approvalId = $held['approval']['id'];

    // Polled unbound, at the root: the person's own, raised in one of their environments.
    expect(mcpCall($token, 'approval_status', ['approval_id' => $approvalId])['structuredContent']['status'])->toBe('pending')
        ->and(ActionApprovalRequest::query()->find($approvalId)?->environment_id)->toBe($environment->id);

    $this->withToken($token)->getJson("/api/v1/action-approvals/{$approvalId}")->assertOk()->assertJsonPath('data.status', 'pending');

    app(PlatformRoot::class)->run(static fn () => app(BackchannelAuthentication::class)->approve($approvalId, $subject));

    expect(mcpCall($token, 'keys_create', [...$arguments, 'approval_id' => $approvalId])['isError'] ?? false)->toBeFalse();

    // The key's maker is the PERSON, so its own held actions go to them.
    $key = app(EnvironmentContext::class)->runAs($environment, static fn (): ?EnvironmentApiKey => EnvironmentApiKey::query()->where('name', 'Deploy bot')->first());

    expect($key?->created_by_type)->toBe('organization_member')
        ->and($key?->created_by_id)->toBe($subject);
})->group('security');

// ── The person's own account, and the deployment ────────────────────────────────

it('serves the member\'s own root account at /api/v1/me on the root', function (): void {
    $subject = $this->account['subjectId'];

    $this->withToken(rootToken($subject))->patchJson('/api/v1/me/profile', ['name' => 'Ada Owner'])->assertOk();

    expect(app(PlatformRoot::class)->run(static fn () => app(Subjects::class)->find($subject)?->name))->toBe('Ada Owner');

    // A token without the scope is refused, and keys are refused outright.
    $this->withToken(rootToken($subject, ['workspace:read']))->patchJson('/api/v1/me/profile', ['name' => 'X'])->assertForbidden();

    $key = app(OrganizationApiKeys::class)->issue($this->account['organization']->id, 'Agent', MembershipRole::Admin)->plaintext;
    $this->withToken($key)->patchJson('/api/v1/me/profile', ['name' => 'X'])->assertUnauthorized();
});

it('opens the operator API to an operator\'s root token, holding every call for their approval', function (): void {
    $operator = actAsOperator('ops@platform.test');
    forgetSubjectSession();
    $token = rootToken((string) $operator->subject_id);

    $held = $this->withToken($token)
        ->postJson('/api/v1/platform/operators', ['name' => 'X', 'email' => 'x@platform.test', 'password' => 'a-long-unbreached-pass'])
        ->assertStatus(202)
        ->assertJsonPath('error', 'approval_required');

    // The poll the answer names is there, and the approval is the operator's own.
    $this->withToken($token)->getJson((string) parse_url((string) $held->json('approval.poll_url'), PHP_URL_PATH))
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');

    // A workspace member is no operator; a key is nobody.
    $this->withToken(rootToken($this->account['subjectId']))
        ->postJson('/api/v1/platform/operators', ['name' => 'X', 'email' => 'x@platform.test', 'password' => 'a-long-unbreached-pass'])
        ->assertForbidden();

    $key = app(EnvironmentApiKeys::class)->issue($this->account['environment']->id, 'Env', ['apis:read'])->plaintext;
    $this->withToken($key)->postJson('/api/v1/platform/operators', [])->assertUnauthorized();

    expect(PlatformOperator::query()->where('email', 'x@platform.test')->exists())->toBeFalse();
})->group('security');

// ── The `cbox` CLI at the root ──────────────────────────────────────────────────

it('signs the cbox CLI in at the root with the device grant, for the whole workspace', function (): void {
    $client = rootCliClient();

    $bootstrap = $this->getJson('/.well-known/cbox-cli')->assertOk();

    expect($bootstrap->json('resource'))->toBe(rootMcpResource())
        ->and($bootstrap->json('client_id'))->toBe($client->client_id)
        ->and($bootstrap->json('scopes'))->toContain('offline_access', 'team:write', 'apis:write', 'account:profile:write', 'operator:workspaces:write')
        ->and($bootstrap->json('device_authorization_endpoint'))->toEndWith('/oauth/device_authorization');

    $started = $this->post('/oauth/device_authorization', [
        'client_id' => $client->client_id,
        'scope' => implode(' ', (array) $bootstrap->json('scopes')),
    ])->assertOk();

    signInAsMember($this->account['subjectId']);

    lookUpDeviceCode((string) $started->json('user_code'))->assertRedirect(route('device'));
    approveDevice()->assertRedirect(route('device'));

    app(PlatformRoot::class)->run(static fn () => DeviceCode::query()->update(['last_polled_at' => now()->subMinute()]));

    $token = (string) $this->post('/oauth/token', [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
        'device_code' => $started->json('device_code'),
        'client_id' => $client->client_id,
        'resource' => $bootstrap->json('resource'),
    ])->assertOk()->json('access_token');

    forgetSubjectSession();

    expect(mcpCall($token, 'whoami')['structuredContent'])->toMatchArray(['kind' => 'person', 'subject' => $this->account['subjectId']]);

    $this->withToken($token)->getJson('/api/v1/workspace/projects')->assertOk();
    $this->withToken($token)->getJson('/api/v1/apis', ['Cbox-Environment' => $this->account['environment']->id])->assertOk();
});

it('admits only the platform\'s own client to the device grant at the root', function (): void {
    $workspace = $this->account['organization']->id;
    $theirs = app(PlatformRoot::class)->run(static fn (): Client => app(ClientRegistry::class)->register(new NewClient(
        name: 'Somebody\'s CLI',
        type: ClientType::Public,
        grantTypes: CliClient::GRANTS,
        scopes: ['openid'],
        organizationId: $workspace,
    ))->client);

    $this->post('/oauth/device_authorization', ['client_id' => $theirs?->client_id, 'scope' => 'openid'])->assertNotFound();
})->group('security');
