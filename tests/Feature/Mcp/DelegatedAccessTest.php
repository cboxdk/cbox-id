<?php

declare(strict_types=1);

use App\Mcp\ActionTool;
use App\Mcp\McpCaller;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Actions\ActionRunner;
use App\Platform\EnvironmentKeyAuditLog;
use App\Platform\OAuth\DelegatedAccess;
use App\Platform\PlaneResolver;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\ProtectedResources;
use Cbox\Id\OAuthServer\Contracts\TokenIssuer;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\ResponseFactory;

/*
|--------------------------------------------------------------------------
| A person, through an agent they signed in: `/mcp` and the REST plane with a token.
|--------------------------------------------------------------------------
|
| The token is the environment's own, audienced to its `/mcp`. What it may do is the
| intersection of two answers — the scopes the person handed the client, and what the
| person may do on their own console here — and each half is tested by taking the other
| away. Critical actions always wait for the person.
*/

const DELEGATED_SCOPES = ['webhooks:read', 'webhooks:write', 'audit:read', 'apis:read'];

/** The `/mcp` resource of the environment every request here resolves to. */
function mcpResource(): string
{
    $resource = app(ProtectedResources::class)->forMetadataPath('/.well-known/oauth-protected-resource/mcp');

    expect($resource)->not->toBeNull();

    return (string) $resource?->identifier;
}

/** An MCP client registered for the management scopes, the way an administrator registers one. */
function delegatedClient(string $name = 'Claude Code'): Client
{
    return app(ClientRegistry::class)->register(new NewClient(
        name: $name,
        type: ClientType::Public,
        redirectUris: ['http://127.0.0.1:33418/callback'],
        grantTypes: ['authorization_code', 'refresh_token'],
        scopes: ['openid', 'offline_access', ...DELEGATED_SCOPES],
    ))->client;
}

/**
 * A person of this environment holding $role in an organization of it.
 *
 * @return array{0: string, 1: string} the subject id and the organization id
 */
function delegatedPerson(MembershipRole $role = MembershipRole::Owner, string $email = 'ada@acme.test'): array
{
    $subject = app(Subjects::class)->create($email, 'Ada', 'a-strong-unbreached-passphrase');
    $organization = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-'.substr(md5($email), 0, 8)));
    app(Memberships::class)->add($organization->id, $subject->id, $role);

    return [$subject->id, $organization->id];
}

/**
 * An access token for $subjectId, as the token endpoint would mint it.
 *
 * @param  list<string>  $scopes
 */
function delegatedToken(string $subjectId, ?string $organizationId, array $scopes = DELEGATED_SCOPES, ?string $resource = null, ?Client $client = null): string
{
    return app(TokenIssuer::class)->issueForUser(
        $client ?? delegatedClient(),
        $subjectId,
        $organizationId,
        ['openid', ...$scopes],
        $resource ?? mcpResource(),
    )->token;
}

/**
 * Run an action's tool directly as the person $token stands for — past the listing, which
 * is a courtesy, straight to the runner, which is the lock.
 *
 * @param  array<string, mixed>  $arguments
 */
function delegatedToolCall(string $token, string $action, array $arguments = []): ResponseFactory
{
    $person = app(DelegatedAccess::class)->principal(HttpRequest::create('/mcp', 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]));

    expect($person)->not->toBeNull();

    app(McpCaller::class)->set($person ?? throw new LogicException('no person'));

    try {
        return (new ActionTool(app(ActionRegistry::class)->named($action)))->handle(new McpRequest($arguments), app(McpCaller::class), app(ActionRunner::class));
    } finally {
        app(McpCaller::class)->clear();
    }
}

// ── Who gets in ─────────────────────────────────────────────────────────────────

it('takes a person\'s access token for /mcp and says who it acts as', function (): void {
    [$subject, $organization] = delegatedPerson();
    $token = delegatedToken($subject, $organization);

    $me = mcpCall($token, 'whoami')['structuredContent'];

    expect($me)->toMatchArray([
        'kind' => 'delegated',
        'name' => 'Ada',
        'subject' => $subject,
        'environment' => 'env_test',
        'client' => ['id' => Client::query()->where('name', 'Claude Code')->value('client_id'), 'name' => 'Claude Code'],
        'organization' => ['id' => $organization, 'name' => 'Acme', 'role' => 'owner'],
    ])->and($me['scopes'])->toEqualCanonicalizing(DELEGATED_SCOPES);
});

it('refuses a token for another audience, an unaudienced one and a forged one as invalid_token', function (string $kind): void {
    [$subject, $organization] = delegatedPerson();

    $token = match ($kind) {
        // Minted for an app's own API: somebody else's audience.
        'another resource' => delegatedToken($subject, $organization, resource: 'https://api.acme.example'),
        // No `resource` at all: audienced to the issuer, which is not this resource server.
        'the issuer' => app(TokenIssuer::class)->issueForUser(delegatedClient(), $subject, $organization, ['openid'])->token,
        default => 'eyJhbGciOiJub25lIn0.eyJzdWIiOiJ4In0.',
    };

    $response = mcpRpc($token, 'tools/list')->assertUnauthorized();

    expect($response->headers->get('WWW-Authenticate'))
        ->toBe('Bearer resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp", error="invalid_token"');
})->with(['another resource', 'the issuer', 'not a token'])->group('security');

it('refuses a token from another environment\'s issuer', function (): void {
    [$subject, $organization] = delegatedPerson();

    // The same person and the same resource path, minted by env_other for ITS `/mcp`.
    $foreign = app(EnvironmentContext::class)->runAs(GenericEnvironment::of('env_other'), fn (): string => delegatedToken($subject, $organization));

    mcpRpc($foreign, 'tools/list')->assertUnauthorized();
})->group('security');

it('refuses a token whose subject has been deactivated', function (): void {
    [$subject, $organization] = delegatedPerson();
    $token = delegatedToken($subject, $organization);

    app(Subjects::class)->deactivate($subject);

    mcpRpc($token, 'tools/list')->assertUnauthorized();
})->group('security');

// ── What it may do: scopes AND the person's rights ──────────────────────────────

it('lists the tools the token\'s scopes and the person\'s own rights both allow', function (): void {
    [$subject, $organization] = delegatedPerson();

    $tools = array_keys(mcpTools(delegatedToken($subject, $organization, ['webhooks:read', 'audit:read', 'apis:read'])));

    // apis:read is in the token, but registering APIs is the ENVIRONMENT console's: an
    // organization's owner holds no such right, so the scope buys nothing.
    expect($tools)->toEqualCanonicalizing(['whoami', 'list_actions', 'approval_status', 'webhooks_list', 'webhooks_get', 'audit_list']);
});

it('refuses a person without the console right, even with the scope', function (): void {
    [$subject, $organization] = delegatedPerson(MembershipRole::Member, 'bob@acme.test');
    $token = delegatedToken($subject, $organization);

    expect(array_keys(mcpTools($token)))->toEqualCanonicalizing(['whoami', 'list_actions', 'approval_status']);

    // Not listed, so not callable…
    mcpRpc($token, 'tools/call', ['name' => 'webhooks_list', 'arguments' => (object) []])->assertJsonPath('error.code', -32602);

    // …and the runner is the lock, whichever door: called directly, and over REST.
    $refused = delegatedToolCall($token, 'webhooks.list');

    expect($refused->getStructuredContent())->toMatchArray(['error' => 'forbidden', 'message' => 'You do not have permission to change this.']);

    $this->withToken($token)->getJson('/api/v1/webhooks')->assertForbidden();
})->group('security');

it('refuses a scope the person holds the right for but did not grant', function (): void {
    [$subject, $organization] = delegatedPerson();
    $token = delegatedToken($subject, $organization, ['audit:read']);

    expect(array_keys(mcpTools($token)))->not->toContain('webhooks_list');

    expect(delegatedToolCall($token, 'webhooks.list')->getStructuredContent()['message'] ?? null)->toContain('webhooks:read');

    $this->withToken($token)->getJson('/api/v1/webhooks')
        ->assertForbidden()
        ->assertJsonPath('message', 'This sign-in was not granted the required scope: webhooks:read.');
})->group('security');

it('keeps the person inside their own organization', function (): void {
    [$subject, $organization] = delegatedPerson();
    $other = app(Organizations::class)->create(new NewOrganization('Rival', 'rival'))->id;

    $own = app(WebhookRegistry::class)->register($organization, 'https://own.acme.example/in', ['user.created'])->endpoint;
    $theirs = app(WebhookRegistry::class)->register($other, 'https://rival.example/in', ['user.created'])->endpoint;

    $token = delegatedToken($subject, $organization);

    $listed = collect(mcpCall($token, 'webhooks_list')['structuredContent']['data'])->pluck('id')->all();

    expect($listed)->toContain($own->id)->not->toContain($theirs->id)
        ->and(mcpCall($token, 'webhooks_get', ['id' => $theirs->id])['structuredContent']['error'])->toBe('not_found');

    // The environment's own endpoint carries their traffic, so they see it — and it is the
    // vendor's to change, not theirs, exactly as on their console.
    $environments = app(WebhookRegistry::class)->registerForEnvironment('https://env.acme.example/in', ['user.created'])->endpoint;

    expect(mcpCall($token, 'webhooks_pause', ['id' => $environments->id])['structuredContent']['error'])->toBe('forbidden');
})->group('security');

it('offers on a customer\'s environment only what a customer\'s own console does', function (): void {
    // A multi-tenant deployment, and this environment is not the platform root: a
    // customer's, whose own console is an admin portal and whose product administration
    // (apps, webhooks, hooks…) is the vendor's environment console.
    multiTenantDeployment();
    platformRootEnvironment();
    serveOnTestHost(Environment::query()->find('env_test') ?? tap(new Environment, function (Environment $environment): void {
        $environment->forceFill([
            'id' => 'env_test', 'name' => 'Test', 'slug' => 'env-test',
            'type' => EnvironmentType::Production,
            'status' => EnvironmentStatus::Active,
            'is_default' => false, 'settings' => [],
        ])->save();
    }));

    expect(app(PlaneResolver::class)->onCustomerEnvironment())->toBeTrue();

    [$subject, $organization] = delegatedPerson();
    $token = delegatedToken($subject, $organization);

    expect(array_keys(mcpTools($token)))->toEqualCanonicalizing(['whoami', 'list_actions', 'approval_status']);

    $this->withToken($token)->getJson('/api/v1/webhooks')->assertForbidden();
})->group('security');

it('acts in the organization the token is bound to only while the person is still in it', function (): void {
    [$subject, $organization] = delegatedPerson(MembershipRole::Admin);
    $token = delegatedToken($subject, $organization);

    app(Memberships::class)->remove($organization, $subject);

    expect(array_keys(mcpTools($token)))->toEqualCanonicalizing(['whoami', 'list_actions', 'approval_status'])
        ->and(mcpCall($token, 'whoami')['structuredContent']['organization'])->toBeNull();
})->group('security');

// ── Critical waits for the person ───────────────────────────────────────────────

it('holds every critical action for the person\'s own approval, then finishes it', function (): void {
    [$subject, $organization] = delegatedPerson();
    $endpoint = app(WebhookRegistry::class)->register($organization, 'https://own.acme.example/in', ['user.created'])->endpoint;
    $token = delegatedToken($subject, $organization);

    $arguments = ['id' => $endpoint->id, 'idempotency_key' => 'rotate-once'];
    $held = mcpCall($token, 'webhooks_secret_rotate', $arguments);

    expect($held['isError'] ?? false)->toBeFalse()
        ->and($held['structuredContent']['status'])->toBe('approval_pending');

    $approvalId = $held['structuredContent']['approval']['id'];

    // Filed with the PERSON, in the environment they belong to — where their devices are.
    $request = BackchannelAuthRequest::query()->findOrFail($approvalId);

    expect($request->user_id)->toBe($subject)
        ->and($request->binding_message)->toContain('"Claude Code" for Ada wants to run webhooks.secret.rotate');

    expect(mcpCall($token, 'approval_status', ['approval_id' => $approvalId])['structuredContent']['status'])->toBe('pending');

    app(BackchannelAuthentication::class)->approve($approvalId, $subject);

    $done = mcpCall($token, 'webhooks_secret_rotate', [...$arguments, 'approval_id' => $approvalId]);

    expect($done['isError'] ?? false)->toBeFalse()
        ->and($done['structuredContent']['data'])->toHaveKey('secret');
})->group('security');

it('runs a write that is not critical without asking, and records it as the person and their client', function (): void {
    [$subject, $organization] = delegatedPerson();
    $endpoint = app(WebhookRegistry::class)->register($organization, 'https://own.acme.example/in', ['user.created'])->endpoint;
    $client = delegatedClient('Cursor');
    $token = delegatedToken($subject, $organization, client: $client);

    $paused = mcpCall($token, 'webhooks_pause', ['id' => $endpoint->id]);

    expect($paused['isError'] ?? false)->toBeFalse();

    $entry = AuditEntry::query()->where('action', 'like', 'webhook%paused')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry?->actor_type)->toBe(ActorType::User)
        ->and($entry?->actor_id)->toBe($subject)
        ->and($entry?->context[EnvironmentKeyAuditLog::CLIENT_CONTEXT_KEY] ?? null)->toBe($client->client_id);
});

// ── The REST door takes the same token ──────────────────────────────────────────

it('takes the same token on the REST environment plane, bounded the same way', function (): void {
    [$subject, $organization] = delegatedPerson();
    $own = app(WebhookRegistry::class)->register($organization, 'https://own.acme.example/in', ['user.created'])->endpoint;
    $token = delegatedToken($subject, $organization);

    $this->withToken($token)->getJson('/api/v1/webhooks')->assertOk()->assertJsonPath('data.0.id', $own->id);

    // The scope is there; the person's right is not.
    $this->withToken($token)->getJson('/api/v1/apis')->assertForbidden();

    // A route that is not an action yet acts as a KEY, and says so.
    $this->withToken($token)->getJson('/api/v1/organizations')
        ->assertForbidden()
        ->assertJsonPath('error', 'forbidden');

    // Another audience is no credential here either.
    $this->withToken(delegatedToken($subject, $organization, resource: 'https://api.acme.example'))
        ->getJson('/api/v1/webhooks')->assertUnauthorized();
})->group('security');

it('holds a critical REST call for the person and lets them poll it', function (): void {
    [$subject, $organization] = delegatedPerson();
    $endpoint = app(WebhookRegistry::class)->register($organization, 'https://own.acme.example/in', ['user.created'])->endpoint;
    $token = delegatedToken($subject, $organization);

    $held = $this->withToken($token)->postJson("/api/v1/webhooks/{$endpoint->id}/rotate")
        ->assertStatus(202)
        ->assertJsonPath('error', 'approval_required');

    $approvalId = (string) $held->json('approval.id');

    $this->withToken($token)->getJson("/api/v1/action-approvals/{$approvalId}")
        ->assertOk()->assertJsonPath('data.status', 'pending');

    // Another person's token does not see it.
    [$other, $theirs] = delegatedPerson(MembershipRole::Owner, 'eve@acme.test');
    $this->withToken(delegatedToken($other, $theirs))->getJson("/api/v1/action-approvals/{$approvalId}")->assertNotFound();
})->group('security');

// ── The person's own account, on their environment's host ───────────────────────

it('serves the person\'s own account at /api/v1/me on their environment\'s host, with the same token', function (): void {
    [$subject, $organization] = delegatedPerson();
    $client = app(ClientRegistry::class)->register(new NewClient(
        name: 'Cbox CLI',
        type: ClientType::Public,
        redirectUris: ['http://127.0.0.1:33418/callback'],
        grantTypes: ['authorization_code', 'refresh_token'],
        scopes: ['openid', 'offline_access', 'account:profile:write', ...DELEGATED_SCOPES],
    ))->client;

    $this->withToken(delegatedToken($subject, $organization, ['account:profile:write'], client: $client))
        ->patchJson('/api/v1/me/profile', ['name' => 'Ada Lovelace'])
        ->assertOk();

    expect(app(Subjects::class)->find($subject)?->name)->toBe('Ada Lovelace');

    // Their account, within what they handed the client: no scope, no change.
    $this->withToken(delegatedToken($subject, $organization, ['webhooks:read'], client: $client))
        ->patchJson('/api/v1/me/profile', ['name' => 'Mallory'])
        ->assertForbidden();

    // The deployment is not theirs: an environment's token never speaks for an operator.
    $this->withToken(delegatedToken($subject, $organization, ['account:profile:write'], client: $client))
        ->postJson('/api/v1/platform/operators', [])
        ->assertForbidden()
        ->assertJsonPath('message', 'Only a platform operator can use this API.');
});
