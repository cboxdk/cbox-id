<?php

declare(strict_types=1);

use App\Platform\Actions\ActionRegistry;
use App\Platform\Actions\Approvals\ActionApprovalRequest;
use App\Platform\Actions\Approvals\ApprovalInput;
use App\Platform\EnvironmentSudo;
use App\Platform\OrganizationActivity;
use Carbon\CarbonImmutable;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Enums\GrantPollStatus;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Models\EnvironmentApiKey;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\ValueObjects\KeyProvenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AI agents: the environment's management keys as agents, their held actions, and Connect.
|--------------------------------------------------------------------------
*/

/**
 * An environment administrator on their environment's own host.
 *
 * @return array{subjectId: string, environment: Environment, organization: Organization, project: Project}
 */
function agentsAdmin(): array
{
    multiTenantDeployment();

    $account = provisionAccount('agents-owner@acme.example');

    serveOnTestHost($account['environment']);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($account['environment']->id));
    actAsEnvironmentAdmin($account['subjectId'], $account['environment']->id);

    return $account;
}

/** A key "minted in the console" by `$owner`, with an approval policy. */
function agentKey(string $environmentId, ?string $owner, string $name = 'Claude Code', array $scopes = ['keys:read', 'keys:write'], ?array $policy = null, ?string $parent = null): array
{
    $issued = app(EnvironmentApiKeys::class)->issue($environmentId, $name, $scopes, null, new KeyProvenance(
        createdByType: $parent !== null ? 'environment_key' : ($owner === null ? null : 'organization_member'),
        createdById: $parent ?? $owner,
        parentKeyId: $parent,
        stepUpPolicy: $policy,
    ));

    return [$issued->plaintext, $issued->key];
}

// ── Agents ──────────────────────────────────────────────────────────────────

it('lists the environment\'s keys as agents: risk, approval, who made them, and the keys they minted beneath them', function (): void {
    ['environment' => $environment, 'subjectId' => $owner] = agentsAdmin();

    [, $parent] = agentKey($environment->id, $owner, 'Claude Code', ['keys:read', 'keys:write'], ['min_danger' => 'critical', 'actions' => []]);
    [, $child] = agentKey($environment->id, null, 'Sub-agent', ['keys:read'], null, $parent->id);
    agentKey($environment->id, $owner, 'Reader', ['users:read', 'apps:read']);

    $agents = (array) $this->get(route('environment.agents'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('environment/agents/index')
            ->where('title', 'Agents')
            ->where('inactiveCount', 0))
        ->inertiaProps('agents');

    $byName = collect($agents)->keyBy('name');

    expect(array_column($agents, 'name'))->toBe(['Reader', 'Claude Code', 'Sub-agent'])
        // Rotating an app's secret and minting keys are Critical; the badge says so.
        ->and($byName['Claude Code']['risk'])->toBe('critical')
        ->and($byName['Claude Code']['approval']['label'])->toBe('Approval for critical actions')
        ->and($byName['Claude Code']['createdBy'])->toBe('Owner')
        ->and($byName['Claude Code']['descendants'])->toBe(1)
        ->and($byName['Claude Code']['rotateHref'])->toBe(route('environment.keys.rotate', $parent->id))
        ->and($byName['Sub-agent']['depth'])->toBe(1)
        ->and($byName['Sub-agent']['parentId'])->toBe($parent->id)
        ->and($byName['Sub-agent']['createdBy'])->toBe('Key "Claude Code"')
        ->and($byName['Reader']['scopeSummary'])->toBe('Read-only · 2 scopes')
        ->and($byName['Reader']['risk'])->toBe('read')
        ->and($byName['Reader']['approval']['label'])->toBe('No approval needed');

    // The value is never a prop — only its public prefix.
    expect(collect($agents)->every(fn (array $agent): bool => ! array_key_exists('token', $agent)))->toBeTrue();

    expect($child->parent_key_id)->toBe($parent->id);
});

it('keeps revoked and expired agents out of the way until asked for', function (): void {
    ['environment' => $environment, 'subjectId' => $owner] = agentsAdmin();

    agentKey($environment->id, $owner, 'Live');
    [, $old] = agentKey($environment->id, $owner, 'Old');
    app(EnvironmentApiKeys::class)->revoke($environment->id, $old->id);

    $this->get(route('environment.agents'))->assertInertia(fn (AssertableInertia $page) => $page
        ->has('agents', 1)
        ->where('inactiveCount', 1));

    $all = collect((array) $this->get(route('environment.agents', ['all' => 1]))->inertiaProps('agents'))->keyBy('name');

    expect($all)->toHaveCount(2)
        ->and($all['Old']['lifecycle']['status'])->toBe('revoked')
        ->and($all['Old']['revokeHref'])->toBeNull()
        ->and($all['Old']['rotateHref'])->toBeNull()
        ->and($all['Live']['revokeHref'])->not->toBeNull();
});

it('is the environment console\'s management keys page: the old URL lands on it', function (): void {
    agentsAdmin();

    $this->get(route('environment.keys'))->assertRedirect(route('environment.agents'));

    // …and the Developers › API keys tab for management keys leads there too.
    $tabs = (array) $this->get(route('environment.keys.frontend'))->assertOk()->inertiaProps('tabs');

    expect(collect($tabs)->firstWhere('key', 'management')['href'] ?? null)->toBe(route('environment.agents'));
});

it('refuses somebody who does not administer the environment', function (): void {
    multiTenantDeployment();
    $account = provisionAccount('nobody-here@acme.example');
    serveOnTestHost($account['environment']);

    foreach (['environment.agents', 'environment.agent-connect', 'environment.approvals'] as $route) {
        $response = $this->get(route($route));

        expect($response->status())->not->toBe(200, "{$route} answered a visitor with no console session");
    }
})->group('security');

// ── Create ──────────────────────────────────────────────────────────────────

it('puts the create flow behind the step-up, and offers every scope with its risk and the actions to hold', function (): void {
    agentsAdmin();

    $this->get(route('environment.agents.create'))->assertRedirect(route('environment.sudo'));

    app(EnvironmentSudo::class)->confirm();

    $props = $this->get(route('environment.agents.create', ['preset' => 'support', 'name' => 'Claude Code']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('environment/agents/create')
            ->where('defaults.name', 'Claude Code')
            ->where('defaults.preset', 'support')
            ->where('defaults.lifetime', '90')
            ->where('urls.store', route('environment.keys.store')))
        ->inertiaProps();

    $scopes = collect($props['scopes'])->keyBy('value');

    // The risk is the registry's: the highest danger among the actions needing the scope.
    expect($scopes['apps:write']['risk'])->toBe('critical')
        ->and($scopes['apps:write']['held'])->toBeTrue()
        ->and($scopes['apps:read']['risk'])->toBe('read')
        ->and($scopes['branding:write']['risk'])->toBe('write')
        // The people endpoints are actions too, so approvals hold them like any other.
        ->and($scopes['users:write']['held'])->toBeTrue()
        ->and($scopes['users:write']['risk'])->toBe('critical');

    expect(collect($props['actions'])->pluck('name')->all())->toContain('keys.create', 'apps.secrets.rotate')
        ->and($props['defaults']['preset'])->toBe('support');

    // A preset the page does not know is ignored, not echoed.
    $this->get(route('environment.agents.create', ['preset' => 'root']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('defaults.preset', null));
});

it('mints an agent key with its purpose and approval policy through keys.create, and shows the value once', function (): void {
    ['environment' => $environment, 'organization' => $workspace, 'subjectId' => $owner] = agentsAdmin();
    app(EnvironmentSudo::class)->confirm();

    $this->from(route('environment.agents.create'))
        ->post(route('environment.keys.store'), [
            'environment' => $environment->id,
            'name' => 'Claude Code',
            'description' => 'Triage support tickets',
            'scopes' => ['apps:read', 'apps:write'],
            'approval' => 'destructive',
            'approvalActions' => ['apps.update'],
            'expires' => '30',
        ])
        ->assertRedirect(route('environment.agents.create'))
        ->assertSessionHasNoErrors();

    $key = EnvironmentApiKey::query()->where('name', 'Claude Code')->sole();

    expect($key->description)->toBe('Triage support tickets')
        ->and($key->step_up_policy)->toBe(['min_danger' => 'destructive', 'actions' => ['apps.update']])
        ->and($key->created_by_type)->toBe('organization_member')
        ->and($key->created_by_id)->toBe($owner)
        ->and($key->expires_at?->isAfter(CarbonImmutable::now()->addDays(29)))->toBeTrue()
        ->and(flashed('freshKey'))->toStartWith('cbid_env_')
        ->and(flashed('freshKeyName'))->toBe('Claude Code');

    expect(app(OrganizationActivity::class)->recent($workspace->id)->pluck('action')->all())
        ->toContain('organization.environment_key_created');
});

it('refuses an action name the registry does not know as one to hold', function (): void {
    ['environment' => $environment] = agentsAdmin();
    app(EnvironmentSudo::class)->confirm();

    $this->from(route('environment.agents.create'))
        ->post(route('environment.keys.store'), [
            'environment' => $environment->id,
            'name' => 'Sneaky',
            'scopes' => ['apps:read'],
            'approval' => 'none',
            'approvalActions' => ['keys.make-me-root'],
        ])
        ->assertSessionHasErrors('approvalActions.0');

    expect(EnvironmentApiKey::query()->where('name', 'Sneaky')->exists())->toBeFalse();
});

// ── Rotate and revoke ───────────────────────────────────────────────────────

it('rotates an agent through keys.rotate: a successor shown once, the old key on a grace period', function (): void {
    ['environment' => $environment, 'subjectId' => $owner] = agentsAdmin();
    [, $old] = agentKey($environment->id, $owner, 'Claude Code', ['keys:read'], ['min_danger' => 'critical', 'actions' => []]);

    // Behind the step-up, like minting.
    $this->from(route('environment.agents'))->post(route('environment.keys.rotate', $old->id))
        ->assertRedirect(route('environment.sudo'));

    app(EnvironmentSudo::class)->confirm();

    $this->from(route('environment.agents'))->post(route('environment.keys.rotate', $old->id))
        ->assertRedirect(route('environment.agents'));

    $successor = EnvironmentApiKey::query()->where('rotated_from_id', $old->id)->sole();

    expect($successor->name)->toBe('Claude Code')
        ->and($successor->step_up_policy)->toBe(['min_danger' => 'critical', 'actions' => []])
        ->and(flashed('freshKey'))->toStartWith('cbid_env_')
        ->and($old->fresh()?->expires_at?->isBefore(CarbonImmutable::now()->addHours(25)))->toBeTrue();
});

it('404s a rotate aimed at another environment\'s key', function (): void {
    agentsAdmin();
    app(EnvironmentSudo::class)->confirm();

    $theirs = app(EnvironmentApiKeys::class)->issue('env_somebody_else', 'Theirs', ['users:read']);

    $this->post(route('environment.keys.rotate', $theirs->key->id))->assertNotFound();

    expect(EnvironmentApiKey::query()->withoutGlobalScopes()->where('rotated_from_id', $theirs->key->id)->exists())->toBeFalse();
})->group('security');

it('revokes an agent and every key it minted', function (): void {
    ['environment' => $environment, 'subjectId' => $owner] = agentsAdmin();
    [, $parent] = agentKey($environment->id, $owner, 'Claude Code');
    [, $child] = agentKey($environment->id, null, 'Sub-agent', ['keys:read'], null, $parent->id);
    app(EnvironmentSudo::class)->confirm();

    $this->from(route('environment.agents'))
        ->delete(route('environment.keys.destroy', $parent->id), ['environment' => $environment->id])
        ->assertRedirect(route('environment.agents'));

    expect($parent->fresh()?->revoked_at)->not->toBeNull()
        ->and($child->fresh()?->revoked_at)->not->toBeNull();
});

// ── Connect ─────────────────────────────────────────────────────────────────

it('gives the MCP address of this environment and the snippets\' inputs, and offers sign-in only when the resource allows it', function (): void {
    agentsAdmin();

    $props = $this->get(route('environment.agent-connect'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('environment/agents/connect')
            ->where('title', 'Connect')
            // The /mcp resource accepts self-registered clients, so signing in is offered.
            ->where('oauthAvailable', true))
        ->inertiaProps();

    expect($props['mcpUrl'])->toEndWith('/mcp')
        ->and($props['metadataUrl'])->toEndWith('/.well-known/oauth-protected-resource/mcp')
        ->and($props['restBaseUrl'])->toEndWith('/api/v1')
        ->and($props['openApiUrl'])->toEndWith('/api/v1/environment/openapi.yaml')
        ->and($props['urls']['createAgent'])->toContain('preset=read-only')
        // …and the other way in: the platform root's, one connection for the whole workspace.
        ->and($props['workspace']['mcpUrl'] ?? null)->toEndWith('/mcp')
        ->and($props['workspace']['restBaseUrl'] ?? null)->toEndWith('/api/v1');

    // The metadata it points at is real.
    $this->get(parse_url($props['metadataUrl'], PHP_URL_PATH))->assertOk()
        ->assertJsonPath('resource', $props['mcpUrl']);
});

// ── Approvals ───────────────────────────────────────────────────────────────

it('finishes an agent\'s held request from the console: 202, approve with sudo, the repeat runs once', function (): void {
    ['environment' => $environment, 'subjectId' => $owner] = agentsAdmin();

    // Minted in the console by this person, with critical actions held for them.
    app(EnvironmentSudo::class)->confirm();
    $this->from(route('environment.agents.create'))->post(route('environment.keys.store'), [
        'environment' => $environment->id,
        'name' => 'Claude Code',
        'scopes' => ['keys:read', 'keys:write'],
        'approval' => 'critical',
    ])->assertSessionHasNoErrors();
    $token = (string) flashed('freshKey');
    app(EnvironmentSudo::class)->forget();

    // The agent asks to mint a narrower key — Critical, so it is held.
    $body = ['name' => 'Sub-agent', 'scopes' => ['keys:read'], 'description' => 'client_secret is not a secret here'];
    $held = $this->withToken($token)->postJson('/api/v1/keys', $body)->assertStatus(202)->json('approval');
    $this->flushHeaders();

    // The inbox shows it, readable, with the code the agent was given.
    $waiting = (array) $this->get(route('environment.approvals'))->assertOk()->inertiaProps('waiting');

    expect($waiting)->toHaveCount(1)
        ->and($waiting[0]['agent'])->toBe('Claude Code')
        ->and($waiting[0]['action']['name'])->toBe('keys.create')
        ->and($waiting[0]['action']['danger'])->toBe('critical')
        ->and($waiting[0]['bindingCode'])->toBe($held['binding_code'])
        ->and($waiting[0]['approver'])->toBe('You')
        ->and($waiting[0]['needsSudo'])->toBeTrue()
        ->and(collect($waiting[0]['arguments'])->pluck('value', 'field')->all())
        ->toMatchArray(['name' => 'Sub-agent', 'scopes' => 'keys:read']);

    // …and the rail counts it for the person it waits for.
    $shell = (array) $this->get(route('environment.agents'))->inertiaProps('shell');
    $page = collect($shell['areas'])->flatMap(fn (array $area): array => $area['pages'])->firstWhere('route', 'environment.approvals');
    expect($page['count'] ?? null)->toBe(1);

    // A critical action: the password first.
    $this->post(route('environment.approvals.actions.approve', $held['id']))->assertRedirect(route('environment.sudo'));

    app(EnvironmentSudo::class)->confirm();
    $this->post(route('environment.approvals.actions.approve', $held['id']))
        ->assertRedirect(route('environment.approvals'))
        ->assertSessionHas('status');

    // The SAME request the phone would have answered: the agent's repeat now runs, once.
    $this->withToken($token)->withHeader('Cbox-Approval', $held['id'])->postJson('/api/v1/keys', $body)
        ->assertCreated()->assertJsonPath('data.name', 'Sub-agent');
    $this->flushHeaders();
    $this->withToken($token)->withHeader('Cbox-Approval', $held['id'])->postJson('/api/v1/keys', $body)
        ->assertStatus(409)->assertJsonPath('error', 'approval_mismatch');
    $this->flushHeaders();

    $decided = (array) $this->get(route('environment.approvals'))->inertiaProps('decided');
    expect($decided[0]['status'])->toBe('consumed');
})->group('security');

it('lets only the person an action waits for approve it, and any administrator deny it', function (): void {
    ['environment' => $environment, 'organization' => $workspace] = agentsAdmin();

    // Held for somebody else: a colleague in the same workspace.
    $colleague = app(PlatformRoot::class)->run(fn () => app(Subjects::class)->create('colleague@acme.example', 'Colleague', 'a-strong-unbreached-passphrase')->id);
    [$token] = agentKey($environment->id, $colleague, 'Their agent', ['keys:read', 'keys:write'], ['min_danger' => 'critical', 'actions' => []]);

    $held = $this->withToken($token)->postJson('/api/v1/keys', ['name' => 'X', 'scopes' => ['keys:read']])->assertStatus(202)->json('approval');
    $this->flushHeaders();

    $row = ((array) $this->get(route('environment.approvals'))->inertiaProps('waiting'))[0];

    expect($row['approver'])->toBe('Colleague')
        ->and($row['approveHref'])->toBeNull()
        ->and($row['denyHref'])->not->toBeNull();

    app(EnvironmentSudo::class)->confirm();
    $this->post(route('environment.approvals.actions.approve', $held['id']))->assertForbidden();

    $this->post(route('environment.approvals.actions.deny', $held['id']))->assertRedirect(route('environment.approvals'));

    expect(app(PlatformRoot::class)->run(fn () => BackchannelAuthRequest::query()->whereKey($held['id'])->value('status')))
        ->toBe(GrantPollStatus::Denied);

    $this->withToken($token)->withHeader('Cbox-Approval', $held['id'])->postJson('/api/v1/keys', ['name' => 'X', 'scopes' => ['keys:read']])
        ->assertForbidden()->assertJsonPath('error', 'approval_denied');

    expect(app(OrganizationActivity::class)->recent($workspace->id)->pluck('action')->all())
        ->toContain('organization.action_approval_denied');
})->group('security');

it('404s an approval raised in another environment', function (): void {
    agentsAdmin();

    ActionApprovalRequest::query()->create([
        'id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ',
        'principal' => 'environment_key:elsewhere',
        'action' => 'keys.create',
        'environment_id' => 'env_somebody_else',
        'binding_code' => 'ABCD',
    ]);

    app(EnvironmentSudo::class)->confirm();

    $this->post(route('environment.approvals.actions.approve', '01JZZZZZZZZZZZZZZZZZZZZZZZ'))->assertNotFound();
    $this->post(route('environment.approvals.actions.deny', '01JZZZZZZZZZZZZZZZZZZZZZZZ'))->assertNotFound();
})->group('security');

it('keeps no secret in the copy of the input an approval stores', function (): void {
    $action = app(ActionRegistry::class)->named('keys.create');

    $kept = ApprovalInput::redact($action, [
        'name' => 'Agent',
        'client_secret' => 'shh',
        'secret' => 'shh',
        'password' => 'shh',
        'nested' => ['token' => 'shh', 'access_token_ttl' => 3600],
        'secret_id' => '01SECRETID',
        'token' => null,
        'long' => str_repeat('x', 1000),
    ]);

    expect(json_encode($kept))->not->toContain('shh')
        ->and($kept['client_secret'])->toBe(ApprovalInput::REDACTED)
        ->and($kept['nested']['token'])->toBe(ApprovalInput::REDACTED)
        // An id and a number are not secrets because their names mention one.
        ->and($kept['nested']['access_token_ttl'])->toBe(3600)
        ->and($kept['secret_id'])->toBe('01SECRETID')
        ->and($kept['token'])->toBeNull()
        ->and(mb_strlen((string) $kept['long']))->toBeLessThan(400);
});

it('shows a member who may not manage environments nothing of this console', function (): void {
    multiTenantDeployment();
    ['organization' => $workspace, 'environment' => $environment] = provisionAccount('owner-viewer@acme.example');
    [, $viewerId] = addMember($workspace->id, MembershipRole::Viewer, 'viewer@acme.example');

    serveOnTestHost($environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($environment->id));
    actAsEnvironmentAdmin($viewerId, $environment->id);

    expect($this->get(route('environment.agents'))->status())->not->toBe(200);
})->group('security');
