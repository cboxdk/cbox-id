<?php

declare(strict_types=1);

use App\Platform\Actions\ActionTrail;
use App\Platform\Actions\ActionVia;
use App\Platform\Audit\AuditActorKind;
use App\Platform\AuditNames;
use App\Platform\EnvironmentKeyAuditLog;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\ValueObjects\KeyProvenance;

/*
|--------------------------------------------------------------------------
| Every entry an action causes names the door it came through, and who approved it.
|--------------------------------------------------------------------------
|
| The principal says WHO acted; `via` says HOW — the console, the REST API, an MCP tool call
| or the CLI. Recorded once, centrally, while the action runs, so a framework service's own
| entry carries it as surely as the action's. And an action a key was held on records the
| person who said yes, which the audit log shows beside it.
*/

function viaKey(array $scopes = ['apps:read', 'apps:write']): string
{
    return app(EnvironmentApiKeys::class)->issue('env_test', 'Door worker', $scopes)->plaintext;
}

function viaLatest(string $action): ?AuditEntry
{
    return AuditEntry::query()->where('action', $action)->orderByDesc('sequence')->first();
}

function viaAppBody(string $name): array
{
    return ['name' => $name, 'type' => 'web', 'redirect_uris' => ['https://door.example/callback']];
}

it('records rest for a key over the REST API', function (): void {
    $this->withToken(viaKey())->postJson('/api/v1/apps', viaAppBody('Rest app'))->assertCreated();

    expect(viaLatest('app.created')?->context[ActionTrail::VIA] ?? null)->toBe('rest');
});

it('records cli when the CLI says so', function (): void {
    $this->withToken(viaKey())->withHeader('User-Agent', 'cbox-cli/1.4.0')
        ->postJson('/api/v1/apps', viaAppBody('Cli app'))->assertCreated();

    expect(viaLatest('app.created')?->context[ActionTrail::VIA] ?? null)->toBe('cli');
});

it('records mcp for the same action as a tool call', function (): void {
    $result = mcpCall(viaKey(), 'apps_create', viaAppBody('Mcp app'));

    expect($result['isError'] ?? false)->toBeFalse()
        ->and(viaLatest('app.created')?->context[ActionTrail::VIA] ?? null)->toBe('mcp');
});

it('records console for the same action from a form', function (): void {
    actingAsRole(MembershipRole::Owner);
    confirmStepUp();

    registerApp(['name' => 'Console app', 'redirectUris' => 'https://door.example/callback'])->assertRedirect();

    expect(viaLatest('app.created')?->context[ActionTrail::VIA] ?? null)->toBe('console');
});

it('leaves an entry written outside any action exactly as it was', function (): void {
    app(AuditLog::class)->record(new AuditEvent(action: 'door.nothing', actorType: ActorType::System));

    expect(viaLatest('door.nothing')?->context)->toBe([]);
});

it('filters the audit log by door and by kind of actor', function (): void {
    $key = viaKey();
    $this->withToken($key)->postJson('/api/v1/apps', viaAppBody('Over REST'))->assertCreated();
    mcpCall($key, 'apps_create', viaAppBody('Over MCP'));
    $this->flushHeaders();

    actingAsRole(MembershipRole::Owner);
    confirmStepUp();
    registerApp(['name' => 'From the console', 'redirectUris' => 'https://door.example/callback'])->assertRedirect();

    $mcp = (array) $this->get(route('audit', ['via' => 'mcp']))->assertOk()->inertiaProps('entries');
    $console = (array) $this->get(route('audit', ['via' => 'console']))->assertOk()->inertiaProps('entries');

    // The organization console reads its own organization's trail; an environment key's
    // app has none, so the door filter is proved on what this organization holds.
    expect(collect($console)->pluck('via')->unique()->values()->all())->toBe(['Console'])
        ->and(collect($console)->pluck('actorKind')->unique()->values()->all())->toBe([AuditActorKind::Human->value])
        ->and(collect($mcp)->pluck('via')->filter()->unique()->values()->all())->not->toContain('Console');

    $doors = (array) $this->get(route('audit'))->assertOk()->inertiaProps('doors');

    expect(array_column($doors, 'value'))->toBe(array_map(static fn (ActionVia $via): string => $via->value, ActionVia::cases()));
});

it('narrows the environment trail to agents, to the door they came through', function (): void {
    $key = viaKey();
    $this->withToken($key)->postJson('/api/v1/apps', viaAppBody('Over REST'))->assertCreated();
    mcpCall($key, 'apps_create', viaAppBody('Over MCP'));
    $this->flushHeaders();

    multiTenantDeployment();
    $environmentId = actAsEnvironmentAdminOfATenant();

    // In the tenant environment the admin now administers: one app over MCP, one by hand.
    $tenantKey = app(EnvironmentApiKeys::class)->issue($environmentId, 'Tenant agent', ['apps:read', 'apps:write'])->plaintext;
    $this->withToken($tenantKey)->postJson('/api/v1/apps', viaAppBody('Tenant REST'))->assertCreated();
    mcpCall($tenantKey, 'apps_create', viaAppBody('Tenant MCP'));
    $this->flushHeaders();

    $agents = (array) $this->get(route('environment.audit', ['actor' => 'agent', 'via' => 'mcp']))->assertOk()->inertiaProps('entries');
    $people = (array) $this->get(route('environment.audit', ['actor' => 'human']))->assertOk()->inertiaProps('entries');

    expect($agents)->not->toBe([])
        ->and(collect($agents)->pluck('via')->unique()->values()->all())->toBe(['MCP'])
        ->and(collect($agents)->pluck('actorKind')->unique()->values()->all())->toBe(['agent'])
        // Another environment's entries never reach this console, whatever the filter.
        ->and(collect($agents)->pluck('targetName')->filter()->all())->not->toContain('Over MCP')
        ->and(collect($people)->pluck('actorKind')->unique()->all())->not->toContain('agent');
});

/*
 * AN AGENT'S WORK IS SIGNED WITH THE AGENT'S NAME. The row showed the stored actor — a
 * `service` id rendered as "Service", or "System" where a framework service named no one —
 * so the page an auditor reads to learn WHICH agent did something said the platform did
 * it. Named by the key, as an agent, linking to where agents are managed, with the door.
 */
it('names the agent key that acted, as an agent, linking to AI agents, with the door it came through', function (): void {
    multiTenantDeployment();
    $environmentId = actAsEnvironmentAdminOfATenant();

    $tenantKey = app(EnvironmentApiKeys::class)->issue($environmentId, 'Release bot', ['apps:read', 'apps:write', 'organizations:read', 'organizations:write']);
    $this->withToken($tenantKey->plaintext)->postJson('/api/v1/apps', viaAppBody('Over REST'))->assertCreated();
    mcpCall($tenantKey->plaintext, 'organizations_create', ['name' => 'Acme', 'slug' => 'acme-agents']);
    $this->flushHeaders();

    // And one a framework service wrote as the platform's own while the key was acting.
    app(AuditLog::class)->record(new AuditEvent(
        action: 'agent.system_side_effect',
        actorType: ActorType::System,
        context: [EnvironmentKeyAuditLog::CONTEXT_KEY => $tenantKey->key->id],
    ));

    $entries = collect((array) $this->get(route('environment.audit'))->assertOk()->inertiaProps('entries'))->keyBy('action');

    foreach (['app.created' => 'REST API', 'organization.created' => 'MCP', 'agent.system_side_effect' => null] as $action => $via) {
        expect($entries[$action]['actorName'] ?? null)->toBe('Release bot')
            ->and($entries[$action]['actorType'])->toBe('Agent')
            ->and($entries[$action]['actorKind'])->toBe('agent')
            ->and($entries[$action]['actorHref'])->toBe(route('environment.agents'))
            ->and($entries[$action]['via'])->toBe($via);
    }
});

it('records who approved an action a key was held on, and the audit log names them', function (): void {
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

    $owner = app(PlatformRoot::class)->run(fn () => app(Subjects::class)->create('approver@workspace.test', 'Ada Approver', 'supersecret123')->id);

    $key = app(EnvironmentApiKeys::class)->issue('env_test', 'Held agent', ['organizations:read', 'organizations:write'], null, new KeyProvenance(
        createdByType: 'organization_member',
        createdById: $owner,
        stepUpPolicy: ['min_danger' => null, 'actions' => ['organizations.create']],
    ))->plaintext;

    $body = ['name' => 'Held Co', 'slug' => 'held-co'];
    $approval = $this->withToken($key)->postJson('/api/v1/organizations', $body)->assertStatus(202)->json('approval');

    app(PlatformRoot::class)->run(fn () => app(BackchannelAuthentication::class)->approve($approval['id'], $owner));

    $this->withToken($key)->withHeader('Cbox-Approval', $approval['id'])->postJson('/api/v1/organizations', $body)->assertCreated();

    $entry = viaLatest('organization.created');

    expect($entry?->context[ActionTrail::APPROVED_BY] ?? null)->toBe($owner)
        ->and($entry?->context[ActionTrail::APPROVAL] ?? null)->toBe($approval['id'])
        ->and($entry?->context[ActionTrail::VIA] ?? null)->toBe('rest');

    // And the audit log says so, by name, on that row.
    $names = app(AuditNames::class)->for([$entry]);

    expect($names[$owner] ?? null)->toBe('Ada Approver');
})->group('security');
