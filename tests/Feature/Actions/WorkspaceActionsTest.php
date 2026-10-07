<?php

declare(strict_types=1);

use App\Platform\Actions\Idempotency\IdempotencyRecord;
use App\Platform\OrganizationActivity;
use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Platform\Models\EnvironmentApiKey;
use Cbox\Id\Platform\Models\OrganizationApiKey;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| The workspace plane, as actions: one workspace key can stand an environment up and hand
| an agent everything it needs to configure it — and nothing wider than the key it holds.
|--------------------------------------------------------------------------
*/

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/**
 * A workspace key for $workspace: a role, and — unless null — scopes narrowing it.
 *
 * @param  list<string>|null  $scopes
 */
function workspaceKey(Organization $workspace, MembershipRole $role = MembershipRole::Admin, ?array $scopes = null): string
{
    return app(OrganizationApiKeys::class)->issue($workspace->id, $role->label().' agent', $role, null, $scopes)->plaintext;
}

/** @return list<AuditEntry> The workspace's own log, newest first. */
function workspaceLog(string $workspaceId, string $action): array
{
    return array_values(app(OrganizationActivity::class)->recent($workspaceId)
        ->filter(static fn (AuditEntry $entry): bool => $entry->action === $action)
        ->all());
}

it('lets one workspace key bootstrap an environment end to end, and the environment key configure it', function (): void {
    $workspace = provisionAccount()['organization'];
    $agent = workspaceKey($workspace, MembershipRole::Admin, ['workspace:read', 'projects:write', 'environments:write']);
    $agentId = OrganizationApiKey::query()->where('organization_id', $workspace->id)->sole()->id;

    // 1. A new product…
    $project = $this->withToken($agent)
        ->postJson('/api/v1/workspace/projects', ['name' => 'Agent Product', 'environment_limit' => 2])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Agent Product')
        ->json('data.id');

    // 2. …an environment under it, with its first management key in the same answer.
    $created = $this->withToken($agent)->postJson('/api/v1/workspace/environments', [
        'name' => 'Production',
        'project_id' => $project,
        'initial_key' => ['name' => 'Agent bootstrap', 'scopes' => ['apis:read', 'apis:write']],
    ])->assertCreated()
        ->assertJsonPath('data.project_id', $project)
        ->assertJsonPath('data.initial_key.name', 'Agent bootstrap')
        ->assertJsonPath('data.initial_key.scopes', ['apis:read', 'apis:write']);

    $environmentId = $created->json('data.id');
    $token = $created->json('data.initial_key.token');

    expect($token)->toBeString()->toStartWith('cbid_env_')
        ->and($created->json('data.initial_key.environment_id'))->toBe($environmentId);

    // The key records that a workspace key made it, and which one.
    $minted = app(PlatformRoot::class)->run(fn () => EnvironmentApiKey::query()
        ->withoutGlobalScopes()
        ->whereKey($created->json('data.initial_key.id'))
        ->first());

    expect($minted?->created_by_type)->toBe('workspace_key')
        ->and($minted?->created_by_id)->toBe($agentId);

    // 3. On the environment's OWN host, that key registers an API — the workspace key is
    // not a credential there, and needs not be.
    serveOnTestHost(Environment::query()->findOrFail($environmentId));

    $this->withToken($agent)->getJson('/api/v1/apis')->assertUnauthorized();

    $this->withToken($token)->postJson('/api/v1/apis', [
        'identifier' => 'https://agent-api.example',
        'name' => 'Agent API',
        'scopes' => [['key' => 'agent:run', 'description' => 'Run the agent']],
    ])->assertCreated()->assertJsonPath('data.identifier', 'https://agent-api.example');

    $this->withToken($token)->getJson('/api/v1/apis')->assertOk()->assertJsonCount(1, 'data');

    // Every step is on the workspace's own log, as the key that did it.
    foreach (['organization.environment_created', 'organization.environment_key_created'] as $action) {
        $entry = workspaceLog($workspace->id, $action)[0] ?? null;

        expect($entry)->not->toBeNull("{$action} was not recorded")
            ->and($entry->actor_type)->toBe(ActorType::Service)
            ->and($entry->actor_id)->toBe($agentId)
            ->and($entry->target_id)->toBe($environmentId);
    }
});

it('mints a management key for any of the workspace\'s environments, and revokes it', function (): void {
    $account = provisionAccount();
    $agent = workspaceKey($account['organization'], MembershipRole::Developer, ['keys:write']);
    $environment = $account['environment'];

    $key = $this->withToken($agent)->postJson("/api/v1/workspace/environments/{$environment->id}/keys", [
        'name' => 'CI',
        'scopes' => ['users:read'],
        'expires_at' => now()->addDays(30)->toIso8601String(),
    ])->assertCreated()->assertJsonPath('data.environment_id', $environment->id);

    expect($key->json('data.token'))->toStartWith('cbid_env_');

    $this->withToken($agent)->deleteJson("/api/v1/workspace/environments/{$environment->id}/keys/".$key->json('data.id'))
        ->assertNoContent();

    // Revoking again is no new act.
    $this->withToken($agent)->deleteJson("/api/v1/workspace/environments/{$environment->id}/keys/".$key->json('data.id'))
        ->assertNoContent();

    expect(workspaceLog($account['organization']->id, 'organization.environment_key_revoked'))->toHaveCount(1);

    // A scope no management key may carry, and an expiry already past, are refused.
    $this->withToken($agent)->postJson("/api/v1/workspace/environments/{$environment->id}/keys", ['name' => 'X', 'scopes' => ['vault.manage']])
        ->assertUnprocessable()->assertJsonPath('error', 'invalid_scopes');
    $this->withToken($agent)->postJson("/api/v1/workspace/environments/{$environment->id}/keys", ['name' => 'X', 'scopes' => ['users:read'], 'expires_at' => now()->subDay()->toIso8601String()])
        ->assertUnprocessable()->assertJsonPath('error', 'invalid_expiry');
});

it('bounds a workspace key by its scopes AND its role', function (): void {
    $workspace = provisionAccount()['organization'];

    // Scoped to reading: every write is refused, naming the scope.
    $reader = workspaceKey($workspace, MembershipRole::Admin, ['workspace:read']);

    $this->withToken($reader)->getJson('/api/v1/workspace/projects')->assertOk();
    $this->withToken($reader)->postJson('/api/v1/workspace/projects', ['name' => 'Nope'])
        ->assertForbidden()->assertJsonPath('message', 'This key is missing the required scope: projects:write.');

    // A scope never lifts a key above its role: a Viewer carrying every scope still may not
    // create anything, and a Developer may not read the roster however it is scoped.
    $viewer = workspaceKey($workspace, MembershipRole::Viewer, ['projects:write', 'workspace:read']);
    $this->withToken($viewer)->postJson('/api/v1/workspace/projects', ['name' => 'Nope'])
        ->assertForbidden()->assertJsonPath('message', "This key's role may not manage-environments.");

    $developer = workspaceKey($workspace, MembershipRole::Developer, ['team:read']);
    $this->withToken($developer)->getJson('/api/v1/workspace/members')->assertForbidden();

    // The action's own capability, beyond the scope's: a Developer holding keys:write may
    // mint environment keys, but not workspace keys — that is a team manager's act.
    $developerKeys = workspaceKey($workspace, MembershipRole::Developer, ['keys:write']);
    $this->withToken($developerKeys)->postJson('/api/v1/workspace/keys', ['name' => 'X', 'role' => 'viewer'])
        ->assertForbidden();

    // Nothing was created by any of it.
    expect(Project::query()->where('organization_id', $workspace->id)->count())->toBe(1);
});

it('answers 404 for another workspace\'s ids, whatever the endpoint', function (): void {
    $mine = provisionAccount();
    $theirs = provisionAccount('owner@other.example');
    $agent = workspaceKey($mine['organization']);

    $theirKey = app(OrganizationApiKeys::class)->issue($theirs['organization']->id, 'Theirs', MembershipRole::Admin);
    $theirMember = $theirs['member'];

    $this->withToken($agent)->patchJson("/api/v1/workspace/projects/{$theirs['project']->id}", ['name' => 'Mine now'])->assertNotFound();
    $this->withToken($agent)->postJson("/api/v1/workspace/projects/{$theirs['project']->id}/suspend")->assertNotFound();
    $this->withToken($agent)->postJson('/api/v1/workspace/environments', ['name' => 'X', 'project_id' => $theirs['project']->id])->assertNotFound();
    $this->withToken($agent)->postJson("/api/v1/workspace/environments/{$theirs['environment']->id}/keys", ['name' => 'X', 'scopes' => ['users:read']])->assertNotFound();
    $this->withToken($agent)->deleteJson("/api/v1/workspace/keys/{$theirKey->key->id}")->assertNotFound();
    $this->withToken($agent)->patchJson("/api/v1/workspace/members/{$theirMember->id}/role", ['role' => 'viewer'])->assertNotFound();
    $this->withToken($agent)->deleteJson("/api/v1/workspace/members/{$theirMember->id}")->assertNotFound();

    expect($theirKey->key->fresh()?->revoked_at)->toBeNull()
        ->and($theirs['project']->fresh()?->name)->toBe('Acme');
});

it('never keeps a minted key\'s value for an idempotent replay', function (): void {
    $account = provisionAccount();
    $agent = workspaceKey($account['organization']);
    $body = ['name' => 'Staging', 'initial_key' => ['name' => 'Bootstrap', 'scopes' => ['users:read']]];

    $first = $this->withToken($agent)->withHeader('Idempotency-Key', 'env-1')
        ->postJson('/api/v1/workspace/environments', $body)->assertCreated();
    $again = $this->withToken($agent)->withHeader('Idempotency-Key', 'env-1')
        ->postJson('/api/v1/workspace/environments', $body)->assertCreated();

    expect($first->json('data.initial_key.token'))->toStartWith('cbid_env_')
        ->and($again->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($again->json('data.id'))->toBe($first->json('data.id'))
        ->and($again->json('data.initial_key.id'))->toBe($first->json('data.initial_key.id'))
        ->and($again->json('data.initial_key.token'))->toBeNull()
        // Ran once: one environment more than provisioning made, and one key.
        ->and(Environment::query()->where('project_id', $account['project']->id)->count())->toBe(2);

    // The store itself holds no secret — only the key's non-secret prefix.
    $stored = IdempotencyRecord::query()->where('idempotency_key', 'env-1')->sole();
    expect((string) json_encode($stored->payload))->not->toContain((string) $first->json('data.initial_key.token'))
        ->and($stored->payload['initial_key'] ?? null)->toHaveKey('token', null);

    // The same for a workspace key.
    $minted = $this->withToken($agent)->withHeader('Idempotency-Key', 'ws-1')
        ->postJson('/api/v1/workspace/keys', ['name' => 'Child', 'role' => 'developer'])->assertCreated();
    $replayed = $this->withToken($agent)->withHeader('Idempotency-Key', 'ws-1')
        ->postJson('/api/v1/workspace/keys', ['name' => 'Child', 'role' => 'developer'])->assertCreated();

    expect($minted->json('data.token'))->toStartWith('cbid_ws_')
        ->and($replayed->json('data.token'))->toBeNull()
        ->and($replayed->json('data.id'))->toBe($minted->json('data.id'));
});

it('lets a key mint workspace keys only within itself, and revokes what it minted with it', function (): void {
    $workspace = provisionAccount()['organization'];
    $parent = workspaceKey($workspace, MembershipRole::Admin, ['workspace:read', 'keys:write', 'projects:write']);
    $parentId = OrganizationApiKey::query()->where('organization_id', $workspace->id)->sole()->id;

    // Not above its role, not outside its scopes…
    $this->withToken($parent)->postJson('/api/v1/workspace/keys', ['name' => 'X', 'role' => 'admin', 'scopes' => ['team:write']])
        ->assertUnprocessable()->assertJsonPath('error', 'scope_exceeds_parent');

    // …and with no scopes asked, it inherits its parent's rather than the role's whole reach.
    $child = $this->withToken($parent)->postJson('/api/v1/workspace/keys', ['name' => 'Child', 'role' => 'developer'])
        ->assertCreated()
        ->assertJsonPath('data.parent_key_id', $parentId)
        ->assertJsonPath('data.created_by_type', 'workspace_key')
        ->assertJsonPath('data.scopes', ['workspace:read', 'keys:write', 'projects:write']);

    $childToken = $child->json('data.token');

    $this->withToken($childToken)->getJson('/api/v1/workspace/projects')->assertOk();

    // A person's key (no parent) is the parent's peer: revoking the parent revokes the child.
    $owner = workspaceKey($workspace, MembershipRole::Owner);
    $this->withToken($owner)->deleteJson("/api/v1/workspace/keys/{$parentId}")->assertNoContent();

    $this->withToken($childToken)->getJson('/api/v1/workspace/projects')->assertUnauthorized();

    $revoked = workspaceLog($workspace->id, 'organization.api_key_revoked');

    expect($revoked)->toHaveCount(2)
        ->and(collect($revoked)->firstWhere('target_id', $child->json('data.id'))?->context['because_parent_revoked'] ?? null)->toBeTrue();
});

it('runs the team from the API as the console does, recorded as the key', function (): void {
    $account = provisionAccount();
    $workspace = $account['organization'];
    $agent = workspaceKey($workspace, MembershipRole::Admin, ['team:read', 'team:write']);
    $agentId = OrganizationApiKey::query()->where('organization_id', $workspace->id)->sole()->id;

    $member = app(PlatformRoot::class)->run(function () use ($workspace) {
        $subject = app(Subjects::class)->create('dev@acme.example', 'Dev');

        return app(Memberships::class)->add($workspace->id, $subject->id, MembershipRole::Developer);
    });

    $this->withToken($agent)->patchJson("/api/v1/workspace/members/{$member->id}/role", ['role' => 'viewer'])
        ->assertOk()->assertJsonPath('data.role', 'viewer');

    $this->withToken($agent)->putJson("/api/v1/workspace/members/{$member->id}/access", [
        'all_environments' => false,
        'environment_ids' => [$account['environment']->id],
    ])->assertOk()->assertJsonPath('data.all_environments', false);

    // The owner is changed only by a transfer, and a key never transfers it.
    $this->withToken($agent)->patchJson("/api/v1/workspace/members/{$account['member']->id}/role", ['role' => 'viewer'])
        ->assertUnprocessable()->assertJsonPath('error', 'not_manageable');
    $this->withToken($agent)->postJson("/api/v1/workspace/members/{$member->id}/transfer-ownership")
        ->assertForbidden()->assertJsonPath('error', 'owner_only');

    $this->withToken($agent)->deleteJson("/api/v1/workspace/members/{$member->id}")->assertNoContent();

    foreach (['organization.member_role_changed', 'organization.member_removed'] as $action) {
        $entry = workspaceLog($workspace->id, $action)[0] ?? null;

        expect($entry)->not->toBeNull()
            ->and($entry->actor_type)->toBe(ActorType::Service)
            ->and($entry->actor_id)->toBe($agentId)
            ->and($entry->target_id)->toBe($member->id);
    }
});

it('renames the workspace, and records it once', function (): void {
    $workspace = provisionAccount()['organization'];
    $agent = workspaceKey($workspace, MembershipRole::Admin, ['settings:write', 'workspace:read']);

    $this->withToken($agent)->patchJson('/api/v1/workspace', ['name' => 'Acme Group'])
        ->assertOk()->assertJsonPath('data.name', 'Acme Group');
    $this->withToken($agent)->patchJson('/api/v1/workspace', ['name' => 'Acme Group'])->assertOk();

    $this->withToken($agent)->getJson('/api/v1/workspace')->assertOk()->assertJsonPath('data.name', 'Acme Group');

    $renamed = workspaceLog($workspace->id, 'organization.renamed');

    expect($renamed)->toHaveCount(1)
        ->and($renamed[0]->context)->toMatchArray(['from' => 'Acme', 'to' => 'Acme Group'])
        ->and($renamed[0]->actor_type)->toBe(ActorType::Service);
});

it('runs the same actions from the workspace console, recorded as the person', function (): void {
    $account = provisionAccount();
    $workspace = $account['organization'];

    signInAsMember($account['subjectId']);

    // Projects › new environment: the action, the console's own wording on a refusal.
    $this->from(route('projects.show', $account['project']->id))
        ->post(route('projects.environments.store', $account['project']->id), ['name' => 'Staging', 'type' => 'sandbox'])
        ->assertSessionHasNoErrors();
    $this->from(route('projects.show', $account['project']->id))
        ->post(route('projects.environments.store', $account['project']->id), ['name' => 'Third', 'type' => 'sandbox'])
        ->assertSessionHasErrors(['name' => 'This project is at its environment limit. Upgrade its plan to add more.']);

    $entry = workspaceLog($workspace->id, 'organization.environment_created')[0] ?? null;

    expect($entry)->not->toBeNull()
        ->and($entry->actor_type)->toBe(ActorType::OrganizationMember)
        ->and($entry->actor_id)->toBe($account['subjectId'])
        ->and($entry->context)->toMatchArray(['name' => 'Staging', 'type' => 'sandbox']);

    // A key the console mints records the person who did it.
    app(Sudo::class)->confirm();

    $this->from(route('keys.workspace'))
        ->post(route('keys.workspace.store'), ['name' => 'From the console', 'role' => 'developer'])
        ->assertSessionHasNoErrors();

    $key = OrganizationApiKey::query()->where('name', 'From the console')->sole();

    expect($key->created_by_type)->toBe('organization_member')
        ->and($key->created_by_id)->toBe($account['subjectId'])
        ->and($key->parent_key_id)->toBeNull()
        ->and($key->scopes)->toBeNull();
});

it('never lets an environment key onto the workspace plane, or a workspace key onto an environment', function (): void {
    $account = provisionAccount();
    serveOnTestHost($account['environment']);

    $environmentKey = app(EnvironmentApiKeys::class)->issue($account['environment']->id, 'Env', ['apis:read'])->plaintext;
    $workspaceKey = workspaceKey($account['organization']);

    $this->withToken($environmentKey)->getJson('/api/v1/workspace')->assertUnauthorized();
    $this->withToken($workspaceKey)->getJson('/api/v1/apis')->assertUnauthorized();
});
