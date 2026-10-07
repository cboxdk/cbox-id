<?php

declare(strict_types=1);

use App\Mcp\ActionTool;
use App\Mcp\McpCaller;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Actions\ActionRunner;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Principal\EnvironmentKeyPrincipal;
use App\Platform\EnvironmentKeyAuditLog;
use Cbox\Id\Api\Support\ServerMetadata;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Models\Api;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Contracts\ManagementScopes;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Platform\Enums\EnvironmentApiScope;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\ValueObjects\IssuedEnvironmentApiKey;
use Cbox\Id\Platform\ValueObjects\KeyProvenance;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
|--------------------------------------------------------------------------
| The MCP server: a third door to the action layer, behaving like the REST one.
|--------------------------------------------------------------------------
|
| Driven over HTTP JSON-RPC, as a client drives it: the credential, the host-resolved
| environment, the rate limiter and the tool list are the real ones.
*/

/**
 * @param  list<EnvironmentApiScope|string>  $scopes
 */
function mcpIssue(array $scopes = [EnvironmentApiScope::ApisRead, EnvironmentApiScope::ApisWrite], string $environmentId = 'env_test'): IssuedEnvironmentApiKey
{
    return app(EnvironmentApiKeys::class)->issue(
        $environmentId,
        'Agent',
        array_map(fn (EnvironmentApiScope|string $scope): string => $scope instanceof EnvironmentApiScope ? $scope->value : $scope, $scopes),
    );
}

/**
 * @param  array<string, mixed>  $params
 */
function mcpRpc(?string $token, string $method, array $params = []): TestResponse
{
    $request = test()->withHeaders(['Accept' => 'application/json, text/event-stream']);

    if ($token !== null) {
        $request = $request->withToken($token);
    }

    return $request->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        ...$params === [] ? [] : ['params' => $params],
    ]);
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed> The tool result: content, isError, structuredContent.
 */
function mcpCall(string $token, string $tool, array $arguments = []): array
{
    $response = mcpRpc($token, 'tools/call', ['name' => $tool, 'arguments' => (object) $arguments])->assertOk();

    // A tool that answers with several messages (execute_tools) is streamed as SSE; the
    // result is the last `data:` line. Everything else is one JSON body.
    if ($response->baseResponse instanceof StreamedResponse) {
        preg_match_all('/^data: (.+)$/m', $response->streamedContent(), $lines);
        $message = json_decode((string) end($lines[1]), true);
        $result = is_array($message) ? ($message['result'] ?? null) : null;
    } else {
        $result = $response->json('result');
    }

    expect($result)->toBeArray();

    return $result;
}

/**
 * @return array<string, array<string, mixed>> Listed tools, keyed by name.
 */
function mcpTools(string $token): array
{
    $tools = mcpRpc($token, 'tools/list')->assertOk()->json('result.tools');

    return collect($tools)->keyBy('name')->all();
}

// ── Authentication ──────────────────────────────────────────────────────────────

it('challenges a request without a credential, pointing at the protected-resource metadata', function (): void {
    $response = mcpRpc(null, 'tools/list')->assertUnauthorized()->assertJsonPath('error', 'unauthorized');

    expect($response->headers->get('WWW-Authenticate'))
        ->toBe('Bearer resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp"')
        // A machine endpoint, outside the `web` group: no session is started for it.
        ->and($response->headers->getCookies())->toBe([]);
});

it('refuses a bogus key, another plane\'s key and another environment\'s key, saying the token is invalid', function (string $token): void {
    $response = mcpRpc($token, 'tools/list')->assertUnauthorized();

    expect($response->headers->get('WWW-Authenticate'))
        ->toBe('Bearer resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp", error="invalid_token"');
})->with([
    'bogus' => fn (): string => 'cbid_env_bogus',
    'organization plane' => fn (): string => 'cbid_org_wrongplane',
    'other environment' => fn (): string => mcpIssue(environmentId: 'env_other')->plaintext,
]);

it('refuses a revoked key', function (): void {
    $key = mcpIssue();
    app(EnvironmentApiKeys::class)->revoke('env_test', $key->key->id);

    mcpRpc($key->plaintext, 'tools/list')->assertUnauthorized();
});

// ── Discovery ───────────────────────────────────────────────────────────────────

it('completes the initialize handshake a client opens with', function (): void {
    mcpRpc(mcpIssue()->plaintext, 'initialize', [
        'protocolVersion' => '2025-11-25',
        'capabilities' => (object) [],
        'clientInfo' => ['name' => 'test', 'version' => '1.0'],
    ])
        ->assertOk()
        ->assertJsonPath('result.protocolVersion', '2025-11-25')
        ->assertJsonPath('result.serverInfo.name', 'Cbox ID')
        ->assertJsonPath('result.capabilities.tools.listChanged', false);
});

it('serves RFC 9728 metadata for /mcp naming the environment\'s issuer', function (): void {
    $scopes = collect(app(ActionRegistry::class)->all())->pluck('scope')->unique()->sort()->values()->all();

    $this->getJson('/.well-known/oauth-protected-resource/mcp')
        ->assertOk()
        ->assertJsonPath('resource', 'http://localhost/mcp')
        ->assertJsonPath('authorization_servers', [ServerMetadata::issuer()])
        ->assertJsonPath('scopes_supported', $scopes)
        ->assertJsonPath('bearer_methods_supported', ['header']);

    expect($scopes)->toContain('apis:read', 'apis:write');
});

it('lists only the tools the key\'s scopes allow, plus whoami, list_actions and approval_status', function (): void {
    $reader = array_keys(mcpTools(mcpIssue([EnvironmentApiScope::ApisRead])->plaintext));

    expect($reader)->toEqualCanonicalizing(['whoami', 'list_actions', 'approval_status', 'apis_list', 'apis_get']);

    $nothing = array_keys(mcpTools(mcpIssue([EnvironmentApiScope::UsersRead])->plaintext));

    expect($nothing)->toEqualCanonicalizing(['whoami', 'list_actions', 'approval_status']);

    $writer = array_keys(mcpTools(mcpIssue()->plaintext));

    expect($writer)->toContain('apis_create', 'apis_update', 'apis_delete', 'apis_scopes_define', 'apis_scopes_remove');
});

it('describes each tool from its action: schema, scope, danger and annotations', function (): void {
    $tools = mcpTools(mcpIssue()->plaintext);

    expect($tools['apis_create']['inputSchema']['required'])->toBe(['identifier', 'name'])
        ->and($tools['apis_create']['inputSchema']['properties'])->toHaveKey('idempotency_key')
        ->and($tools['apis_create']['description'])->toContain('apis:write', 'danger: write')
        ->and($tools['apis_create']['annotations'])->toBe(['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false])
        ->and($tools['apis_delete']['annotations'])->toMatchArray(['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true])
        ->and($tools['apis_list']['annotations'])->toMatchArray(['readOnlyHint' => true, 'destructiveHint' => false])
        // A read has nothing to retry safely, so it is offered no key.
        ->and($tools['apis_list']['inputSchema']['properties'])->not->toHaveKey('idempotency_key')
        ->and($tools['whoami']['annotations'])->toMatchArray(['readOnlyHint' => true]);
});

it('says who the connection acts as', function (): void {
    $key = mcpIssue([EnvironmentApiScope::ApisRead]);

    expect(mcpCall($key->plaintext, 'whoami')['structuredContent'])->toMatchArray([
        'kind' => 'environment_key',
        'id' => $key->key->id,
        'name' => 'Agent',
        'environment' => 'env_test',
        'issuer' => ServerMetadata::issuer(),
        'scopes' => ['apis:read'],
    ]);
});

it('indexes only the actions the key may run', function (): void {
    $actions = mcpCall(mcpIssue([EnvironmentApiScope::ApisRead])->plaintext, 'list_actions')['structuredContent']['actions'];

    expect(collect($actions)->pluck('tool')->all())->toEqualCanonicalizing(['apis_list', 'apis_get'])
        ->and(collect($actions)->firstWhere('tool', 'apis_list'))->toMatchArray(['action' => 'apis.list', 'scope' => 'apis:read', 'danger' => 'read']);
});

// ── Running actions ─────────────────────────────────────────────────────────────

it('creates an API through apis_create and records it as the key, exactly as REST does', function (): void {
    $key = mcpIssue();
    $body = ['name' => 'Tax', 'scopes' => [['key' => 'tax:quote', 'description' => 'Quote']]];

    $this->withToken($key->plaintext)->postJson('/api/v1/apis', ['identifier' => 'https://rest.example', ...$body])->assertCreated();

    $result = mcpCall($key->plaintext, 'apis_create', ['identifier' => 'https://mcp.example', ...$body, 'scopes' => [['key' => 'tax:file', 'description' => 'File']]]);

    expect($result['isError'])->toBeFalse()
        ->and($result['structuredContent']['data'])->toMatchArray(['identifier' => 'https://mcp.example', 'name' => 'Tax'])
        ->and($result['content'][0]['text'])->toBe('apis.create: done. The result is below.')
        ->and(json_decode($result['content'][1]['text'], true))->toBe($result['structuredContent'])
        ->and(Api::query()->where('identifier', 'https://mcp.example')->exists())->toBeTrue();

    $trail = fn (string $identifier): array => AuditEntry::query()
        ->where('target_type', 'api')->where('target_id', $identifier)->orderBy('sequence')->get()
        ->map(fn (AuditEntry $entry): array => [
            'action' => $entry->action,
            'actor_type' => $entry->actor_type,
            'actor_id' => $entry->actor_id,
            'key' => ((array) $entry->context)[EnvironmentKeyAuditLog::CONTEXT_KEY] ?? null,
        ])->all();

    expect($trail('https://mcp.example'))->toBe($trail('https://rest.example'))
        ->and($trail('https://mcp.example')[0])->toBe([
            'action' => 'api.created',
            'actor_type' => ActorType::Service,
            'actor_id' => $key->key->id,
            'key' => $key->key->id,
        ]);
});

it('answers invalid input as a tool error listing the fields', function (): void {
    $result = mcpCall(mcpIssue()->plaintext, 'apis_create', ['identifier' => 'https://x.example']);

    expect($result['isError'])->toBeTrue()
        ->and($result['structuredContent']['error'])->toBe('validation_failed')
        ->and($result['structuredContent']['field'])->toBe('name')
        ->and($result['structuredContent']['errors'])->toHaveKey('name')
        ->and(json_decode($result['content'][0]['text'], true))->toBe($result['structuredContent']);
});

it('answers an action\'s refusal with its code and field', function (): void {
    $result = mcpCall(mcpIssue()->plaintext, 'apis_create', ['identifier' => 'https://x.example', 'name' => 'X', 'organization_id' => 'org_missing']);

    expect($result['isError'])->toBeTrue()
        ->and($result['structuredContent'])->toMatchArray([
            'error' => 'organization_not_found',
            'field' => 'organization_id',
        ])
        ->and(Api::query()->count())->toBe(0);
});

it('answers a missing API as not_found', function (): void {
    $result = mcpCall(mcpIssue()->plaintext, 'apis_get', ['id' => 'api_missing']);

    expect($result['isError'])->toBeTrue()
        ->and($result['structuredContent']['error'])->toBe('not_found');
});

it('cannot reach a tool the key may not run, and the runner refuses it anyway', function (): void {
    $reader = mcpIssue([EnvironmentApiScope::ApisRead]);

    // Not listed, so not callable: the client is told no such tool exists here.
    mcpRpc($reader->plaintext, 'tools/call', ['name' => 'apis_create', 'arguments' => ['identifier' => 'https://x.example', 'name' => 'X']])
        ->assertJsonPath('error.code', -32602);

    // Hiding is the courtesy; the runner is the lock. Called directly, it still refuses.
    app(McpCaller::class)->set(new EnvironmentKeyPrincipal($reader->key));
    $tool = new ActionTool(app(ActionRegistry::class)->named('apis.create'));
    $refused = $tool->handle(new Request(['identifier' => 'https://x.example', 'name' => 'X']), app(McpCaller::class), app(ActionRunner::class));

    expect($refused->getStructuredContent())->toMatchArray(['error' => 'forbidden'])
        ->and($refused->responses()->first()?->isError())->toBeTrue()
        ->and(Api::query()->count())->toBe(0);
});

it('replays a retried write with the same idempotency_key, and refuses the key on a different request', function (): void {
    $key = mcpIssue()->plaintext;
    $arguments = ['identifier' => 'https://retry.example', 'name' => 'Retry', 'idempotency_key' => 'k-1'];

    $first = mcpCall($key, 'apis_create', $arguments);
    $again = mcpCall($key, 'apis_create', $arguments);

    expect($again['structuredContent']['data']['id'])->toBe($first['structuredContent']['data']['id'])
        ->and($again['structuredContent']['replayed'])->toBeTrue()
        ->and($first['structuredContent'])->not->toHaveKey('replayed')
        ->and(Api::query()->count())->toBe(1);

    $reused = mcpCall($key, 'apis_create', [...$arguments, 'identifier' => 'https://other.example']);

    expect($reused['isError'])->toBeTrue()
        ->and($reused['structuredContent']['error'])->toBe('idempotency_key_reused');
});

it('shares idempotency with the REST door: one key, one change, whichever door retried', function (): void {
    $key = mcpIssue()->plaintext;
    $body = ['identifier' => 'https://doors.example', 'name' => 'Doors'];

    $rest = $this->withToken($key)->withHeader('Idempotency-Key', 'cross')->postJson('/api/v1/apis', $body)->assertCreated();
    $mcp = mcpCall($key, 'apis_create', [...$body, 'idempotency_key' => 'cross']);

    expect($mcp['structuredContent']['data']['id'])->toBe($rest->json('data.id'))
        ->and($mcp['structuredContent']['replayed'])->toBeTrue()
        ->and(Api::query()->count())->toBe(1);
});

it('refuses an idempotency_key that is not a string', function (): void {
    $result = mcpCall(mcpIssue()->plaintext, 'apis_create', ['identifier' => 'https://x.example', 'name' => 'X', 'idempotency_key' => 42]);

    expect($result['isError'])->toBeTrue()
        ->and($result['structuredContent'])->toMatchArray(['error' => 'validation_failed', 'field' => 'idempotency_key']);
});

it('pages a list the way REST does', function (): void {
    $key = mcpIssue()->plaintext;
    mcpCall($key, 'apis_create', ['identifier' => 'https://one.example', 'name' => 'One']);
    mcpCall($key, 'apis_create', ['identifier' => 'https://two.example', 'name' => 'Two']);

    $page = mcpCall($key, 'apis_list', ['limit' => 1]);

    expect($page['structuredContent']['data'])->toHaveCount(1)
        ->and($page['structuredContent']['meta']['has_more'])->toBeTrue()
        ->and($page['content'][0]['text'])->toContain('1 item(s)', 'next_cursor');
});

// ── Tool search ─────────────────────────────────────────────────────────────────

it('groups the action tools behind search_tools and execute_tools when tool search is on', function (): void {
    config(['api.mcp.tool_search' => true]);
    $key = mcpIssue([EnvironmentApiScope::ApisRead])->plaintext;

    expect(array_keys(mcpTools($key)))->toEqualCanonicalizing(['whoami', 'list_actions', 'approval_status', 'search_tools', 'execute_tools']);

    $found = json_decode(mcpCall($key, 'search_tools', ['query' => 'api'])['content'][0]['text'], true);

    expect(collect($found['tools'])->pluck('name')->all())->toEqualCanonicalizing(['apis_list', 'apis_get']);

    $ran = json_decode(mcpCall($key, 'execute_tools', ['calls' => [['name' => 'apis_list', 'arguments' => (object) []]]])['content'][0]['text'], true);

    expect($ran['ok'])->toBeTrue()
        ->and($ran['results'][0]['structuredContent']['data'])->toBe([]);
});

// ── Parity ──────────────────────────────────────────────────────────────────────

/*
 * Every action is a tool. An action that is not one is something the REST API and the
 * console can do and an agent cannot — this is the MCP twin of ActionParityTest.
 */
it('offers every action in the registry as a tool, with its own input schema', function (): void {
    $all = mcpIssue(app(ManagementScopes::class)->offerable())->plaintext;

    // Each plane's key sees its own plane's tools, and only those.
    $tools = mcpTools($all);
    $workspace = app(OrganizationApiKeys::class)->issue(provisionAccount()['organization']->id, 'Agent', MembershipRole::Admin)->plaintext;
    $workspaceTools = mcpTools($workspace);

    foreach (app(ActionRegistry::class)->forPlane(ActionPlane::Workspace) as $action) {
        expect($tools)->not->toHaveKey($action->toolName());

        if ($action->name !== 'team.transfer_ownership') {
            expect($workspaceTools)->toHaveKey($action->toolName(), message: "Workspace action {$action->name} has no MCP tool.");
        }
    }

    $tools = [...$workspaceTools, ...$tools];

    foreach (app(ActionRegistry::class)->all() as $action) {
        if ($action->name === 'team.transfer_ownership') {
            continue; // Only the owner, in the console: no key holds the Owner role.
        }

        expect($tools)->toHaveKey($action->toolName(), message: "Action {$action->name} has no MCP tool.");

        $schema = $tools[$action->toolName()]['inputSchema'];
        $declared = json_decode(json_encode($action->input()->jsonSchema(), JSON_THROW_ON_ERROR), true);

        expect(array_diff_key($schema['properties'], [ActionTool::IDEMPOTENCY_KEY => true, ActionTool::APPROVAL_ID => true]))->toBe($declared['properties'] ?? [])
            ->and(array_key_exists(ActionTool::APPROVAL_ID, $schema['properties']))->toBeTrue("{$action->name} cannot be finished after an approval")
            ->and($schema['required'] ?? [])->toBe($declared['required'] ?? [])
            ->and(array_key_exists(ActionTool::IDEMPOTENCY_KEY, $schema['properties']))->toBe($action->danger !== Danger::Read)
            ->and(array_key_exists(ActionTool::IDEMPOTENCY_KEY, $declared['properties'] ?? []))->toBeFalse("{$action->name} declares a field named idempotency_key, which MCP uses for retries.");
    }
});

// ── Approvals ───────────────────────────────────────────────────────────────────

/*
 * A key whose policy holds an action answers `approval_pending` over MCP — not an error —
 * and the agent finishes the call once the person approves, exactly as over REST.
 */
it('holds an action for the owner\'s approval and finishes it with approval_id', function (): void {
    platformRootEnvironment();
    $environment = Environment::query()->find('env_test') ?? tap(new Environment, function ($environment): void {
        $environment->forceFill([
            'id' => 'env_test', 'name' => 'Test', 'slug' => 'env-test',
            'type' => EnvironmentType::Production,
            'status' => EnvironmentStatus::Active,
            'is_default' => false, 'settings' => [],
        ])->save();
    });
    serveOnTestHost($environment);

    $owner = app(PlatformRoot::class)->run(fn () => app(Subjects::class)->create('mcp-owner@workspace.test', 'Owner', 'supersecret123')->id);
    $key = app(EnvironmentApiKeys::class)->issue('env_test', 'Agent', ['keys:read', 'keys:write'], null, new KeyProvenance(
        createdByType: 'organization_member',
        createdById: $owner,
        stepUpPolicy: ['min_danger' => 'critical', 'actions' => []],
    ))->plaintext;

    $arguments = ['name' => 'Minted by an agent', 'scopes' => ['keys:read'], 'idempotency_key' => 'mcp-held'];

    $held = mcpCall($key, 'keys_create', $arguments);

    expect($held['isError'] ?? false)->toBeFalse()
        ->and($held['structuredContent']['status'])->toBe('approval_pending');

    $approvalId = $held['structuredContent']['approval']['id'];

    expect(mcpCall($key, 'approval_status', ['approval_id' => $approvalId])['structuredContent']['status'])->toBe('pending');

    app(PlatformRoot::class)->run(fn () => app(BackchannelAuthentication::class)->approve($approvalId, $owner));

    $done = mcpCall($key, 'keys_create', [...$arguments, 'approval_id' => $approvalId]);

    expect($done['isError'] ?? false)->toBeFalse()
        ->and($done['structuredContent']['data']['name'])->toBe('Minted by an agent');
})->group('security');

it('takes a workspace key too, and runs the workspace plane\'s tools as that key', function (): void {
    $account = provisionAccount();
    $key = app(OrganizationApiKeys::class)->issue($account['organization']->id, 'Agent', MembershipRole::Admin, null, ['workspace:read', 'projects:write']);

    $me = mcpCall($key->plaintext, 'whoami');

    expect($me['structuredContent']['kind'])->toBe('workspace_key')
        ->and($me['structuredContent']['workspace'])->toBe($account['organization']->id)
        ->and($me['structuredContent']['scopes'])->toBe(['workspace:read', 'projects:write']);

    $tools = mcpTools($key->plaintext);

    expect($tools)->toHaveKey('projects_create')
        ->and($tools)->not->toHaveKey('team_invite')
        ->and($tools)->not->toHaveKey('apis_create');

    $created = mcpCall($key->plaintext, 'projects_create', ['name' => 'From an agent', 'idempotency_key' => 'mcp-ws-1']);

    expect($created['isError'] ?? false)->toBeFalse()
        ->and(Project::query()->where('organization_id', $account['organization']->id)->where('name', 'From an agent')->exists())->toBeTrue();
});
