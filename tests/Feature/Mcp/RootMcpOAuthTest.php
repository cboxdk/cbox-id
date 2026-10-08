<?php

declare(strict_types=1);

use App\Http\WebRateLimiters;
use App\Platform\OAuth\RootDelegatedAccess;
use App\Platform\OAuth\RootMcpOAuth;
use App\Platform\OrganizationActivity;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\Testing\InteractsWithOAuth;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| An MCP client signing a person in at the platform root.
|--------------------------------------------------------------------------
|
| `claude mcp add --transport http cbox-id https://<root>/mcp`, and nothing else: the 401
| points at the resource metadata, that names the root as the authorization server, the
| root's RFC 8414 document names registration, the client registers itself in the `mcp`
| profile (or presents a metadata document), sends the person to sign in at the root and
| consent, and redeems a token for the root's `/mcp` — one connection for the workspace,
| its environments and the person's account, critical actions held for them.
|
| And NOTHING ELSE: the root is still nobody's identity provider. No OpenID Connect, no
| audience but its `/mcp`, no client an administrator created there, and nobody without a
| workspace or operator standing gets through.
*/

uses(InteractsWithOAuth::class);

const ROOT_MCP_CALLBACK = 'http://127.0.0.1:41917/callback';

const ROOT_MCP_DOCUMENT = 'https://agent.example/oauth/root-client.json';

beforeEach(function (): void {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    Mail::fake();

    // The root's own host is the test host, as in RootDelegatedAccessTest.
    multiTenantDeployment((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    config(['cbox-id.oauth.dynamic_registration.mode' => 'mcp', 'api.mcp.root_oauth' => true]);

    $this->account = provisionAccount('owner@acme.example');
});

/** The root's `/mcp` identifier and the scopes it takes. */
function rootOAuthResource(): array
{
    $resource = app(RootDelegatedAccess::class)->resource();

    expect($resource)->not->toBeNull();

    return [(string) $resource?->identifier, $resource->scopes ?? []];
}

function rootOAuthRegister(array $changes = []): TestResponse
{
    return test()->postJson('/oauth/register', [
        'client_name' => 'Claude Code',
        'redirect_uris' => [ROOT_MCP_CALLBACK],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'token_endpoint_auth_method' => 'none',
        ...$changes,
    ]);
}

/** An authorization request for the root's `/mcp`, as an MCP client sends it. */
function rootOAuthAuthorize(string $clientId, array $changes = []): TestResponse
{
    [$resource, $scopes] = rootOAuthResource();

    return authorizeRequest([
        'client_id' => $clientId,
        'redirect_uri' => ROOT_MCP_CALLBACK,
        'scope' => implode(' ', [...$scopes, 'offline_access']),
        'resource' => $resource,
        ...$changes,
    ]);
}

/** Approve the consent screen and redeem the code, as the client would. */
function rootOAuthRedeem(array $props, string $clientId, ?string $resource = null): TestResponse
{
    $location = (string) leftFor(answerConsent($props));
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($query)->toHaveKey('code')
        ->and($query['iss'] ?? null)->toBe(rtrim((string) config('app.url'), '/'));

    return test()->post('/oauth/token', array_filter([
        'grant_type' => 'authorization_code',
        'code' => $query['code'],
        'redirect_uri' => ROOT_MCP_CALLBACK,
        'client_id' => $clientId,
        'code_verifier' => pkcePair()['verifier'],
        'resource' => $resource ?? rootOAuthResource()[0],
    ]));
}

/** The error a refused authorization request was sent back to the client with. */
function rootOAuthError(TestResponse $response): ?string
{
    parse_str((string) parse_url((string) leftFor($response), PHP_URL_QUERY), $query);

    return is_string($query['error'] ?? null) ? $query['error'] : null;
}

/** @return list<string> */
function rootAuditActions(): array
{
    return app(PlatformRoot::class)->run(static fn (): array => AuditEntry::query()->pluck('action')->all()) ?? [];
}

// ── The whole flow, as Claude Code runs it ──────────────────────────────────────

it('signs a workspace member in from a bare /mcp URL, through to a held critical action', function (): void {
    [$resource] = rootOAuthResource();

    // 1. The 401 says where to look.
    $challenge = (string) mcpRpc(null, 'tools/list')->assertUnauthorized()->headers->get('WWW-Authenticate');

    expect($challenge)->toContain('resource_metadata="'.rtrim((string) config('app.url'), '/').'/.well-known/oauth-protected-resource/mcp"');

    // 2. The resource names the root as its authorization server.
    $prm = $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk();
    $issuer = (string) $prm->json('authorization_servers.0');

    expect($prm->json('resource'))->toBe($resource)
        ->and($issuer)->toBe(rtrim((string) config('app.url'), '/'));

    // 3. The root's own RFC 8414 document: the code flow, public clients, registration and
    //    metadata documents — and no OpenID Connect.
    $metadata = $this->getJson('/.well-known/oauth-authorization-server')->assertOk()->json();

    expect($metadata)->toMatchArray([
        'issuer' => $issuer,
        'authorization_endpoint' => $issuer.'/oauth/authorize',
        'token_endpoint' => $issuer.'/oauth/token',
        'registration_endpoint' => $issuer.'/oauth/register',
        'revocation_endpoint' => $issuer.'/oauth/revoke',
        'code_challenge_methods_supported' => ['S256'],
        'token_endpoint_auth_methods_supported' => ['none'],
        'response_types_supported' => ['code'],
        'client_id_metadata_document_supported' => true,
    ])->and($metadata['grant_types_supported'])->toContain('authorization_code', 'refresh_token')
        ->and($metadata['scopes_supported'])->toContain('offline_access', 'team:read', 'apis:write')
        ->and($metadata['scopes_supported'])->not->toContain('openid')
        ->and($metadata['scopes_supported'])->not->toContain('email')
        ->and($metadata['scopes_supported'])->not->toContain('profile');

    foreach (['userinfo_endpoint', 'end_session_endpoint', 'pushed_authorization_request_endpoint', 'introspection_endpoint', 'backchannel_authentication_endpoint', 'id_token_signing_alg_values_supported'] as $absent) {
        expect($metadata)->not->toHaveKey($absent);
    }

    // 4. Registration in the `mcp` profile: public, no secret, no RFC 7592 management here.
    $registered = rootOAuthRegister()->assertCreated();
    $clientId = (string) $registered->json('client_id');

    expect($registered->json('token_endpoint_auth_method'))->toBe('none')
        ->and(explode(' ', (string) $registered->json('scope')))->toContain('offline_access', 'team:read')->not->toContain('openid');

    foreach (['client_secret', 'registration_access_token', 'registration_client_uri'] as $absent) {
        expect($registered->json())->not->toHaveKey($absent);
    }

    // 5. Sign in at the root, as a workspace member, and consent.
    signInAsMember($this->account['subjectId']);

    $props = (array) rootOAuthAuthorize($clientId)->assertOk()->inertiaProps();

    expect($props['client']['selfRegistered'])->toBeTrue()
        ->and(collect($props['scopes'])->keyBy('scope')['keys:write']['critical'] ?? null)->toBeTrue();

    $issued = rootOAuthRedeem($props, $clientId)->assertOk();
    $token = (string) $issued->json('access_token');

    expect($issued->json('refresh_token'))->toBeString()
        ->and($issued->json('id_token'))->toBeNull();

    forgetSubjectSession();

    // 6. One connection: the workspace, each environment by argument, the account.
    expect(mcpCall($token, 'whoami')['structuredContent'])->toMatchArray([
        'kind' => 'person',
        'subject' => $this->account['subjectId'],
        'workspace' => ['id' => $this->account['organization']->id, 'name' => 'Acme', 'role' => 'owner'],
    ]);

    $tools = mcpTools($token);

    expect(array_keys($tools))->toContain('team_list', 'projects_create', 'apis_create', 'account_profile_update')
        ->and($tools['apis_create']['inputSchema']['required'])->toContain('environment');

    // …REST takes the same token.
    $this->withToken($token)->getJson('/api/v1/workspace')->assertOk();

    // 7. A critical action waits for the person.
    $held = mcpCall($token, 'keys_workspace_create', ['name' => 'CI', 'role' => 'developer', 'idempotency_key' => 'root-oauth-once'])['structuredContent'];

    expect($held['status'] ?? null)->toBe('approval_pending');

    // 8. Recorded: the registration with its address, the consent in the workspace's trail.
    $authorized = collect(app(OrganizationActivity::class)->recent($this->account['organization']->id))
        ->first(static fn (AuditEntry $entry): bool => $entry->action === RootMcpOAuth::AUTHORIZED);

    expect(rootAuditActions())->toContain('app.created', RootMcpOAuth::REGISTERED)
        ->and($authorized?->actor_type)->toBe(ActorType::OrganizationMember)
        ->and($authorized?->actor_id)->toBe($this->account['subjectId'])
        ->and($authorized?->target_id)->toBe($clientId);
});

it('audiences a token to the root\'s /mcp when the client named no resource, and refreshes it there', function (): void {
    [$resource] = rootOAuthResource();
    $clientId = (string) rootOAuthRegister()->assertCreated()->json('client_id');

    signInAsMember($this->account['subjectId']);

    // Nothing but a refresh token asked for, so no scope picks a resource either: the
    // framework's answer would be the issuer itself.
    $props = (array) rootOAuthAuthorize($clientId, ['resource' => null, 'scope' => 'offline_access'])->assertOk()->inertiaProps();
    $location = (string) leftFor(answerConsent($props));
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    $issued = $this->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $query['code'],
        'redirect_uri' => ROOT_MCP_CALLBACK,
        'client_id' => $clientId,
        'code_verifier' => pkcePair()['verifier'],
    ])->assertOk();

    forgetSubjectSession();

    expect(mcpCall((string) $issued->json('access_token'), 'whoami')['structuredContent']['kind'])->toBe('person');

    $refreshed = $this->post('/oauth/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $issued->json('refresh_token'),
        'client_id' => $clientId,
    ])->assertOk();

    expect(mcpCall((string) $refreshed->json('access_token'), 'whoami')['structuredContent']['kind'])->toBe('person');

    // The refresh is bound to the root's `/mcp` and to nothing else.
    $this->post('/oauth/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $refreshed->json('refresh_token'),
        'client_id' => $clientId,
        'resource' => rtrim((string) config('app.url'), '/'),
    ])->assertStatus(400);

    expect($resource)->toEndWith('/mcp');
})->group('security');

it('takes a client ID metadata document client through the root to its /mcp', function (): void {
    $this->fakeClientMetadataDocuments()->serve(ROOT_MCP_DOCUMENT, [
        'client_id' => ROOT_MCP_DOCUMENT,
        'client_name' => 'Agent',
        'redirect_uris' => [ROOT_MCP_CALLBACK],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'token_endpoint_auth_method' => 'none',
    ]);

    signInAsMember($this->account['subjectId']);

    $props = (array) rootOAuthAuthorize(ROOT_MCP_DOCUMENT)->assertOk()->inertiaProps();

    expect($props['client'])->toMatchArray(['selfRegistered' => true, 'documentHost' => 'agent.example']);

    $token = (string) rootOAuthRedeem($props, ROOT_MCP_DOCUMENT)->assertOk()->json('access_token');

    forgetSubjectSession();

    expect(mcpCall($token, 'whoami')['structuredContent'])->toMatchArray([
        'kind' => 'person',
        'subject' => $this->account['subjectId'],
    ])->and(Client::query()->withoutGlobalScopes()->where('client_id', ROOT_MCP_DOCUMENT)->exists())->toBeFalse();
});

it('lets an operator on no workspace sign an MCP client in, for the deployment', function (): void {
    $operator = actAsOperator('ops@platform.test');
    $clientId = (string) rootOAuthRegister()->assertCreated()->json('client_id');

    $props = (array) rootOAuthAuthorize($clientId)->assertOk()->inertiaProps();
    $token = (string) rootOAuthRedeem($props, $clientId)->assertOk()->json('access_token');

    forgetSubjectSession();

    expect(mcpCall($token, 'whoami')['structuredContent']['operator'])->toBeTrue()
        ->and(array_keys(mcpTools($token)))->toContain('platform_workspaces_create')
        ->and(rootAuditActions())->toContain(RootMcpOAuth::AUTHORIZED)
        ->and((string) $operator->subject_id)->not->toBe('');
});

it('meters /oauth/authorize per address at the root', function (): void {
    $clientId = (string) rootOAuthRegister()->assertCreated()->json('client_id');

    // One minute's budget, spent inside one minute: frozen, so a slow run under load cannot
    // cross the window's edge and get a fresh budget half way.
    $this->freezeTime();

    // Signed out, each answer is a hop to the sign-in page: cheap, and still counted.
    for ($i = 0; $i < WebRateLimiters::ROOT_AUTHORIZE_PER_IP; $i++) {
        rootOAuthAuthorize($clientId)->assertRedirect();
    }

    rootOAuthAuthorize($clientId)->assertStatus(429);
})->group('security');

// ── What it refuses ─────────────────────────────────────────────────────────────

it('refuses a confidential registration, a plain-http callback and the device grant at the root', function (array $changes): void {
    expect(rootOAuthRegister($changes)->assertStatus(400)->json('error'))->toBeIn(['invalid_client_metadata', 'invalid_redirect_uri']);
})->with([
    'a client secret' => [['token_endpoint_auth_method' => 'client_secret_basic']],
    'plain http off loopback' => [['redirect_uris' => ['http://agent.example/callback']]],
    'the device grant' => [['grant_types' => ['urn:ietf:params:oauth:grant-type:device_code']]],
])->group('security');

it('registers nothing at the root in any mode but mcp', function (string $mode): void {
    config(['cbox-id.oauth.dynamic_registration.mode' => $mode]);

    rootOAuthRegister()->assertForbidden();

    expect($this->getJson('/.well-known/oauth-authorization-server')->assertOk()->json())->not->toHaveKey('registration_endpoint');
})->with(['open', 'protected', 'disabled'])->group('security');

it('never registers openid at the root, and refuses it at /authorize', function (): void {
    $registered = rootOAuthRegister(['scope' => 'openid email team:read offline_access'])->assertCreated();

    expect(explode(' ', (string) $registered->json('scope')))->toEqualCanonicalizing(['team:read', 'offline_access']);

    $clientId = (string) $registered->json('client_id');
    signInAsMember($this->account['subjectId']);

    expect(rootOAuthError(rootOAuthAuthorize($clientId, ['scope' => 'openid team:read'])))->toBe('invalid_scope');
})->group('security');

it('refuses any audience but the root\'s /mcp', function (string $resource): void {
    $clientId = (string) rootOAuthRegister()->assertCreated()->json('client_id');
    signInAsMember($this->account['subjectId']);

    expect(rootOAuthError(rootOAuthAuthorize($clientId, ['scope' => 'team:read', 'resource' => $resource])))->toBe('invalid_target');
})->with([
    'the root issuer' => ['http://localhost'],
    'another environment\'s /mcp' => ['https://acme.cboxid.test/mcp'],
    'somebody else\'s API' => ['https://api.attacker.example'],
])->group('security');

it('refuses the person who runs no workspace and is no operator, on the page', function (): void {
    $clientId = (string) rootOAuthRegister()->assertCreated()->json('client_id');
    $stranger = app(PlatformRoot::class)->run(static fn () => app(Subjects::class)->create('drifter@nowhere.example', 'Drifter', 'a-strong-unbreached-passphrase'));

    signInAsSubject((string) $stranger?->id);

    $refused = rootOAuthAuthorize($clientId)->assertOk();

    expect(consentRefusal($refused))->toBe(__('oauth.failure.no_workspace'))
        ->and(rootAuditActions())->not->toContain(RootMcpOAuth::AUTHORIZED);
})->group('security');

it('still refuses a client an administrator created at the root', function (): void {
    $workspace = $this->account['organization']->id;
    $theirs = app(PlatformRoot::class)->run(static fn (): Client => app(ClientRegistry::class)->register(new NewClient(
        name: 'Somebody\'s app',
        type: ClientType::Public,
        redirectUris: [ROOT_MCP_CALLBACK],
        grantTypes: ['authorization_code', 'refresh_token'],
        scopes: ['openid'],
        organizationId: $workspace,
    ))->client);

    signInAsMember($this->account['subjectId']);

    rootOAuthAuthorize((string) $theirs?->client_id, ['scope' => 'openid'])->assertNotFound();
})->group('security');

it('still serves no OpenID Connect at the root', function (): void {
    $clientId = (string) rootOAuthRegister()->assertCreated()->json('client_id');

    $this->getJson('/.well-known/openid-configuration')->assertNotFound();
    $this->getJson('/oauth/userinfo')->assertNotFound();
    $this->getJson('/.well-known/oauth-protected-resource')->assertNotFound();
    $this->getJson('/.well-known/oauth-protected-resource/api')->assertNotFound();
    $this->getJson('/oauth/register/'.$clientId)->assertNotFound();
    $this->post('/oauth/par', ['client_id' => $clientId])->assertNotFound();
    $this->post('/oauth/device_authorization', ['client_id' => $clientId])->assertNotFound();
})->group('security');

it('closes all of it again when the switch is off', function (): void {
    $clientId = (string) rootOAuthRegister()->assertCreated()->json('client_id');
    signInAsMember($this->account['subjectId']);

    config(['api.mcp.root_oauth' => false]);

    $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertNotFound();
    $this->getJson('/.well-known/oauth-authorization-server')->assertNotFound();
    rootOAuthRegister()->assertNotFound();
    rootOAuthAuthorize($clientId)->assertNotFound();
    $this->post('/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => 'x'])->assertNotFound();
})->group('security');

it('leaves an environment\'s own host as it was', function (): void {
    serveOnTestHost($this->account['environment']);

    $this->getJson('/.well-known/openid-configuration')->assertOk();

    $metadata = $this->getJson('/.well-known/oauth-authorization-server')->assertOk()->json();

    expect($metadata)->toHaveKey('userinfo_endpoint')
        ->and($metadata['scopes_supported'])->toContain('openid');

    expect(rootOAuthRegister()->assertCreated()->json())->toHaveKey('registration_access_token');
});
