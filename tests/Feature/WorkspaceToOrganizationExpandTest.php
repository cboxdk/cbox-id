<?php

declare(strict_types=1);

use App\Mcp\McpProtectedResources;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Actions\Approvals\ActionApprovalGate;
use App\Platform\Actions\Approvals\ActionApprovalRequest;
use App\Platform\Actions\Approvals\StepUpPolicy;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Idempotency\IdempotencyRecord;
use App\Platform\Actions\OrganizationScopes;
use App\Platform\Actions\Principal\EnvironmentKeyPrincipal;
use App\Platform\Actions\RenamedActions;
use App\Platform\Api\ApiHost;
use App\Platform\Keys\OrganizationKeyPrefixes;
use App\Platform\OAuth\ConsentScopes;
use App\Platform\Scopes\ScopeRegistry;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Platform\Models\EnvironmentApiKey;
use Cbox\Id\Platform\Models\OrganizationApiKey;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\ValueObjects\KeyProvenance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/*
| v3.0 renames the workspace to the organization, and ID is LIVE: the release before it keeps
| serving while v3 rolls out, and comes back if the rollout fails, on the same database.
| So v3.0 is expand-only. Nothing v2 stored is rewritten, v3 reads both spellings, and v3
| still writes the v2 spelling wherever v2 reads a row. Each test writes what v2 wrote (or
| lets v3 write) and asks the question the OTHER release asks.
*/

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/** A v2 `cbid_ws_` key, written the way v2's framework wrote it: the hash of the whole plaintext. */
function v2WorkspaceKey(string $organizationId, ?array $scopes = null, string $prefix = OrganizationKeyPrefixes::WORKSPACE, array $attributes = []): array
{
    $plaintext = $prefix.Str::random(40);
    $key = OrganizationApiKey::query()->create([
        'organization_id' => $organizationId,
        'name' => 'v2 agent',
        'prefix' => substr($plaintext, 0, strlen($prefix) + 3),
        'token_hash' => hash('sha256', $plaintext),
        'role' => MembershipRole::Admin,
        'scopes' => $scopes,
        ...$attributes,
    ]);

    return [$key, $plaintext];
}

// ── Key provenance ──────────────────────────────────────────────────────────────

it('reads v2 workspace_key provenance as it is, and writes it for keys an organization key mints', function (): void {
    $account = provisionAccount();
    $person = $account['subjectId'];

    $minter = app(OrganizationApiKeys::class)->issue($account['organization']->id, 'Minter', MembershipRole::Admin, null, null, new KeyProvenance(
        createdByType: 'organization_member',
        createdById: $person,
    ));

    $child = $this->withToken($minter->plaintext)->postJson('/api/v1/workspace/keys', ['name' => 'Child', 'role' => 'developer'])
        ->assertCreated()
        // The API says v3's name …
        ->assertJsonPath('data.created_by_type', 'organization_key')
        ->json('data.id');
    $environmentKey = $this->withToken($minter->plaintext)
        ->postJson("/api/v1/workspace/environments/{$account['environment']->id}/keys", ['name' => 'CI', 'scopes' => ['apis:read']])
        ->assertCreated()->json('data.id');

    // … and the row holds v2's, in both key tables, so v2's approver lookup still reads it.
    $environment = app(PlatformRoot::class)->run(fn () => EnvironmentApiKey::query()->withoutGlobalScopes()->findOrFail($environmentKey));

    expect(DB::table('organization_api_keys')->where('id', $child)->value('created_by_type'))->toBe('workspace_key')
        ->and($environment->created_by_type)->toBe('workspace_key')
        // v3 finds the person behind the minting key from the v2 value too.
        ->and((new EnvironmentKeyPrincipal($environment))->approverSubjectId())->toBe($person);
});

// ── Prefixes ────────────────────────────────────────────────────────────────────

it('still mints cbid_ws_ keys, resolves cbid_org_ ones too, and deprecates neither', function (): void {
    $account = provisionAccount();

    $fresh = app(OrganizationApiKeys::class)->issue($account['organization']->id, 'Fresh', MembershipRole::Admin)->plaintext;
    [, $v2] = v2WorkspaceKey($account['organization']->id);
    [, $future] = v2WorkspaceKey($account['organization']->id, prefix: OrganizationKeyPrefixes::ORGANIZATION);

    // The released cbox-cli refuses a cbid_org_ key as retired: minting must not switch yet.
    expect($fresh)->toStartWith('cbid_ws_');

    foreach ([$fresh, $v2, $future] as $token) {
        // No key is deprecated: on the API host, where no PATH is deprecated either, every
        // prefix answers without a Deprecation header.
        $this->withToken($token)->getJson('http://'.ApiHost::name().'/v1/organization')
            ->assertOk()
            ->assertJsonPath('id', $account['organization']->id)
            ->assertHeaderMissing('Deprecation');

        // The old path answers for every prefix too. From v3 it carries Deprecation, but for
        // the path (its successor is `/v1`), the same for every credential (ApiHostTest).
        $this->flushHeaders()->withToken($token)->getJson('/api/v1/workspace')
            ->assertOk()
            ->assertJsonPath('data.id', $account['organization']->id);
    }
})->group('security');

it('keeps a revoked 1.x cbid_org_ key revoked though the prefix resolves again', function (): void {
    $account = provisionAccount();
    [, $plaintext] = v2WorkspaceKey($account['organization']->id, prefix: OrganizationKeyPrefixes::ORGANIZATION, attributes: [
        'role' => MembershipRole::Owner,
        'revoked_at' => now()->subMonth(),
    ]);

    expect(app(OrganizationApiKeys::class)->resolve($plaintext))->toBeNull();
    $this->withToken($plaintext)->getJson('/api/v1/workspace')->assertUnauthorized();
})->group('security');

// ── Scopes ──────────────────────────────────────────────────────────────────────

it('reads a v2 key\'s stored scope names as they are: the old name reaches the new route', function (): void {
    $account = provisionAccount();
    [$key, $v2] = v2WorkspaceKey($account['organization']->id, ['workspace:read', 'keys:write']);

    $this->withToken($v2)->getJson('/api/v1/workspace')->assertOk();
    $this->withToken($v2)->getJson('/api/v1/workspace/members')
        ->assertForbidden()->assertJsonPath('message', 'This key is missing the required scope: id:members:read.');

    expect(OrganizationApiKey::query()->findOrFail($key->id)->scopes)->toBe(['workspace:read', 'keys:write']);
});

it('stores a v3-minted key\'s scopes in both spellings, so v2 reads it as minted and never wider', function (): void {
    $account = provisionAccount();
    $minter = app(OrganizationApiKeys::class)->issue($account['organization']->id, 'Minter', MembershipRole::Owner)->plaintext;

    $minted = $this->withToken($minter)->postJson('/api/v1/workspace/keys', [
        'name' => 'Narrow', 'role' => 'admin', 'scopes' => ['id:organization:read', 'team:read', 'id:environment-keys:write'],
    ])->assertCreated();

    // The API answers in v3's names, once each.
    expect($minted->json('data.scopes'))->toBe(['id:organization:read', 'id:members:read', 'id:environment-keys:write']);

    $stored = OrganizationApiKey::query()->findOrFail($minted->json('data.id'));

    // v2's check is an exact match on its own names: it reads the read scopes …
    expect($stored->permits('workspace:read'))->toBeTrue()
        ->and($stored->permits('team:read'))->toBeTrue()
        // … but not `keys:write`, which granted organization keys too: half of it is not it.
        ->and($stored->permits('keys:write'))->toBeFalse()
        ->and($stored->scopes)->toContain('id:organization:read', 'id:members:read', 'id:environment-keys:write');

    // v3 reads the stored list as minted.
    $this->withToken((string) $minted->json('data.token'))->getJson('/api/v1/workspace/members')->assertOk();
    $this->withToken((string) $minted->json('data.token'))->postJson('/api/v1/workspace/keys', ['name' => 'No', 'role' => 'viewer'])
        ->assertForbidden();
});

// ── Idempotency ─────────────────────────────────────────────────────────────────

it('replays an answer v2 stored, and stores its own the way v2 reads it', function (): void {
    $account = provisionAccount();
    $token = app(OrganizationApiKeys::class)->issue($account['organization']->id, 'Agent', MembershipRole::Admin);
    $body = ['name' => 'Once', 'role' => 'developer'];
    $hashed = static fn (string $action): string => hash('sha256', $action.'|'.json_encode($body, JSON_THROW_ON_ERROR));

    $first = $this->withToken($token->plaintext)
        ->postJson('/api/v1/workspace/keys', $body, ['Idempotency-Key' => 'expand-1'])
        ->assertCreated();

    // v3 wrote it in v2's spellings: the old action name, owner kind and hash.
    $record = IdempotencyRecord::query()->where('idempotency_key', 'expand-1')->sole();
    expect($record->action)->toBe('keys.workspace.create')
        ->and($record->principal)->toBe('workspace_key:'.$token->key->id)
        ->and($record->request_hash)->toBe($hashed('keys.workspace.create'));

    $this->withToken($token->plaintext)
        ->postJson('/api/v1/workspace/keys', $body, ['Idempotency-Key' => 'expand-1'])
        ->assertCreated()
        ->assertJsonPath('data.id', $first->json('data.id'));

    // As the contract release (v3.1) will write it: still the same request.
    DB::table('action_idempotency_records')->where('id', $record->id)->update([
        'action' => 'keys.organization.create',
        'principal' => 'organization_key:'.$token->key->id,
        'request_hash' => $hashed('keys.organization.create'),
    ]);

    $this->withToken($token->plaintext)
        ->postJson('/api/v1/workspace/keys', $body, ['Idempotency-Key' => 'expand-1'])
        ->assertCreated()
        ->assertJsonPath('data.id', $first->json('data.id'));

    expect(OrganizationApiKey::query()->where('name', 'Once')->count())->toBe(1);
});

// ── Approvals ───────────────────────────────────────────────────────────────────

it('files an approval in v2\'s spellings, and spends one taken under v3\'s', function (bool $v3Digest): void {
    $account = provisionAccount();
    $key = app(OrganizationApiKeys::class)->issue($account['organization']->id, 'Supervised', MembershipRole::Admin, null, null, new KeyProvenance(
        createdByType: 'organization_member',
        createdById: $account['subjectId'],
        stepUpPolicy: ['min_danger' => 'critical', 'actions' => []],
    ));
    $body = ['name' => 'Held child', 'role' => 'developer'];

    $approvalId = (string) $this->withToken($key->plaintext)->postJson('/api/v1/workspace/keys', $body)
        ->assertStatus(202)->json('approval.id');

    $stored = ActionApprovalRequest::query()->findOrFail($approvalId);
    $canonical = (new ReflectionMethod(ActionApprovalGate::class, 'canonical'))->invoke(null, (array) $stored->input);
    $digest = static fn (string $kind, string $action): string => hash('sha256', json_encode([$kind.':'.$key->key->id, $action, $canonical], JSON_THROW_ON_ERROR));
    $backchannel = app(PlatformRoot::class)->run(fn () => BackchannelAuthRequest::query()->findOrFail($approvalId));

    // What v2 reads: its own action name, owner kind and digest.
    expect($stored->action)->toBe('keys.workspace.create')
        ->and($stored->principal)->toBe('workspace_key:'.$key->key->id)
        ->and($backchannel->action_digest)->toBe($digest('workspace_key', 'keys.workspace.create'));

    if ($v3Digest) {
        app(PlatformRoot::class)->run(fn () => $backchannel->forceFill(['action_digest' => $digest('organization_key', 'keys.organization.create')])->save());
        DB::table('action_approval_requests')->where('id', $approvalId)->update(['action' => 'keys.organization.create', 'principal' => 'organization_key:'.$key->key->id]);
    }

    app(PlatformRoot::class)->run(fn () => app(BackchannelAuthentication::class)->approve($approvalId, $account['subjectId']));

    $this->withToken($key->plaintext)->postJson('/api/v1/workspace/keys', $body, ['Cbox-Approval' => $approvalId])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Held child');
})->with(['filed by v3.0' => false, 'filed under the v3 names' => true])->group('security');

it('holds a renamed action by a stored policy that names its old name', function (): void {
    $registry = app(ActionRegistry::class);
    $policy = StepUpPolicy::fromArray(['actions' => ['keys.workspace.revoke', 'workspace.settings.update']]);

    expect($policy?->requires($registry->named('keys.organization.revoke')))->toBeTrue()
        ->and($policy?->requires($registry->named('organization.settings.update')))->toBeTrue()
        ->and($policy?->requires($registry->named('keys.organization.create')))->toBeFalse()
        // Stored as written: v2 reads the same list.
        ->and($policy?->toArray()['actions'])->toBe(['keys.workspace.revoke', 'workspace.settings.update']);
});

// ── What else v2 reads ─────────────────────────────────────────────────────────

it('keeps the billing usage outbox column v2 writes', function (): void {
    expect(Schema::hasColumn('product_billing_usage_outbox', 'workspace_id'))->toBeTrue()
        ->and(Schema::hasColumn('product_billing_usage_outbox', 'organization_id'))->toBeFalse();
});

// ── Renamed actions on the surfaces named after them ──────────────────────────

it('still calls a renamed MCP tool by its old name, unlisted and counted', function (): void {
    multiTenantDeployment((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    $account = provisionAccount('owner@acme.example');
    $key = app(OrganizationApiKeys::class)->issue($account['organization']->id, 'Agent', MembershipRole::Admin)->plaintext;
    Log::spy();

    $tools = array_keys(mcpTools($key));

    expect($tools)->toContain('organization_get', 'keys_organization_list')
        ->not->toContain('workspace_get')->not->toContain('keys_workspace_list');

    $result = mcpCall($key, 'workspace_get');

    expect($result['isError'] ?? false)->toBeFalse()
        ->and($result['structuredContent']['data']['id'] ?? null)->toBe($account['organization']->id);

    Log::shouldHaveReceived('notice')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'workspace_get (now organization_get)')
        && $context['surface'] === 'mcp_tool')->once();
});

it('documents every renamed action\'s old names as x-action-aliases', function (): void {
    $specs = [];

    foreach (app(ActionRegistry::class)->all() as $action) {
        $retired = RenamedActions::retired($action->name);
        $spec = $specs[$action->plane->value] ??= Yaml::parseFile(resource_path("openapi/{$action->plane->value}.yaml"));
        $operation = $spec['paths'][$action->documentedPath()][strtolower($action->method)] ?? [];

        expect($operation['x-action-aliases'] ?? [])->toBe($retired, $action->name);
    }

    // Every alias points at an action that exists.
    $names = array_map(static fn ($action): string => $action->name, array_values(app(ActionRegistry::class)->all()));
    expect(array_diff(array_values(RenamedActions::MAP), $names))->toBe([]);
});

// ── Scope names on the wire ─────────────────────────────────────────────────────

it('names only scopes the registry knows, on every action and every route', function (): void {
    $unknown = [];

    foreach (app(ActionRegistry::class)->all() as $action) {
        if (! ScopeRegistry::knows($action->scope)) {
            $unknown[] = "action {$action->name}: {$action->scope}";
        }
    }

    foreach (Route::getRoutes()->getRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || preg_match('/^(env\.api|org\.api|scope|delegated\.api):(.+)$/', $middleware, $m) !== 1) {
                continue;
            }

            // Which parameter is the scope: `delegated.api:<plane>,<scope>`,
            // `env.api:<scope>,<doors>`, `org.api:<scope>`, `scope:<scope>`.
            // `scope:<a>|<b>` accepts either, and each must be a scope the registry knows.
            $parameters = explode(',', $m[2]);
            $scope = $m[1] === 'delegated.api' ? ($parameters[1] ?? '') : $parameters[0];

            foreach ($m[1] === 'scope' ? explode('|', $scope) : [$scope] as $one) {
                if ($one !== '' && ! ScopeRegistry::knows($one)) {
                    $unknown[] = "route {$route->uri()} ({$m[1]}): {$one}";
                }
            }
        }
    }

    expect($unknown)->toBe([]);
});

it('advertises each MCP scope once, and still accepts the other spelling', function (): void {
    multiTenantDeployment((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    provisionAccount();

    $resource = app(PlatformRoot::class)->run(fn () => app(McpProtectedResources::class)->mcp());
    $scopes = $resource?->scopes ?? [];

    // No scope is published beside its own other spelling. `keys:write` is the one name two
    // planes share: the environment plane declares it for an environment's own keys, and the
    // organization plane's two key scopes are what it split into there.
    $twins = [];
    foreach ($scopes as $scope) {
        foreach (ScopeRegistry::equivalents($scope) as $other) {
            if ($other !== $scope && in_array($other, $scopes, true) && ! in_array('keys:write', [$scope, $other], true)) {
                $twins[] = "{$scope} / {$other}";
            }
        }
    }

    expect($twins)->toBe([])
        ->and($scopes)->toContain('id:organization:read', 'id:members:read')
        ->not->toContain('workspace:read')->not->toContain('team:read')
        // A client registered before v3 still gets the name it asks for.
        ->and($resource?->accepts('workspace:read'))->toBeTrue()
        ->and($resource?->accepts('team:write'))->toBeTrue()
        ->and($resource?->accepts('nonsense:read'))->toBeFalse();

    // The consent screen for a client that asked for nothing in particular: each
    // organization-plane scope reads as itself, so the two halves of `keys:write` are two
    // different rows rather than the environment's "Manage management keys" twice.
    $labels = collect(app(ConsentScopes::class)->rows($scopes))->pluck('label', 'scope');

    foreach (OrganizationScopes::all() as $scope) {
        expect($labels[$scope] ?? null)->toBe(OrganizationScopes::label($scope));
    }

    expect($labels['id:environment-keys:write'])->not->toBe($labels['id:organization-keys:write']);
});

it('flags a critical scope on the consent screen under every name that grants it', function (): void {
    $consent = app(ConsentScopes::class);

    foreach (app(ActionRegistry::class)->all() as $action) {
        if ($action->danger !== Danger::Critical) {
            continue;
        }

        foreach (ScopeRegistry::equivalents($action->scope) as $name) {
            $row = $consent->rows([$name])[0];

            if ($row['management']) {
                expect($row['critical'])->toBeTrue("{$name} ({$action->name})");
            }
        }
    }
});
