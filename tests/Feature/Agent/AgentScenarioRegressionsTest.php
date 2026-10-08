<?php

declare(strict_types=1);

use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Actions\ActionTrail;
use App\Platform\Actions\Approvals\StepUpClient;
use App\Platform\EnvironmentKeyAuditLog;
use App\Platform\OrganizationActivity;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Platform\Models\EnvironmentApiKey;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\ValueObjects\KeyProvenance;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;

/*
|--------------------------------------------------------------------------
| What the agent acceptance scenarios found, each pinned on its own.
|--------------------------------------------------------------------------
|
| AgentBootstrapScenarioTest walks an agent through the whole programme over both doors; it
| failed on four things the per-action tests had never put together. Each is fixed where it
| lives and held here by the smallest test that shows it.
*/

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/** @return list<AuditEntry> The workspace's own log for $action, oldest first. */
function regressionWorkspaceLog(string $workspaceId, string $action): array
{
    return array_values(app(OrganizationActivity::class)->recent($workspaceId)
        ->filter(static fn (AuditEntry $entry): bool => $entry->action === $action)
        ->sortBy('sequence')
        ->all());
}

/*
 * 1. A held `POST /apps` answered 202, and the contract test refused it: the 46 hand-written
 *    operations documented neither the 202 nor `Cbox-Approval`, though any action can be held.
 */
it('documents the approval answer and header on every action, hand-written or generated', function (ActionPlane $plane): void {
    $spec = Yaml::parseFile(resource_path("openapi/{$plane->value}.yaml"));

    foreach (app(ActionRegistry::class)->forPlane($plane) as $action) {
        $operation = $spec['paths'][$action->documentedPath()][strtolower($action->method)] ?? [];

        expect($operation['responses'][202]['$ref'] ?? null)->toBe('#/components/responses/ApprovalRequired', "{$action->name} does not document 202")
            ->and($operation['parameters'] ?? [])->toContain(['$ref' => '#/components/parameters/CboxApproval']);
    }
})->with(ActionPlane::cases());

/*
 * 2. Revoking an environment key from the WORKSPACE (the API's `keys.environment.revoke`
 *    and the workspace console's `keys.destroy`) stopped that key and left the keys it had
 *    minted working.
 */
it('stops every key an environment key minted when the workspace revokes it', function (): void {
    $account = provisionAccount();
    $environment = serveOnTestHost($account['environment']);
    $workspaceKey = app(OrganizationApiKeys::class)->issue($account['organization']->id, 'Ops', MembershipRole::Admin, null, ['workspace:read', 'keys:write'])->plaintext;

    $parent = $this->withToken($workspaceKey)->postJson("/api/v1/workspace/environments/{$environment->id}/keys", ['name' => 'Parent', 'scopes' => ['keys:read', 'keys:write']])
        ->assertCreated()->json('data');
    $child = $this->withToken($parent['token'])->postJson('/api/v1/keys', ['name' => 'Child', 'scopes' => ['keys:read', 'keys:write']])->assertCreated()->json('data');
    $grandchild = $this->withToken($child['token'])->postJson('/api/v1/keys', ['name' => 'Grandchild', 'scopes' => ['keys:read']])->assertCreated()->json('data');

    $this->withToken($workspaceKey)->deleteJson("/api/v1/workspace/environments/{$environment->id}/keys/{$parent['id']}")->assertNoContent();

    foreach ([$parent, $child, $grandchild] as $key) {
        $this->withToken($key['token'])->getJson('/api/v1/keys')->assertUnauthorized();
    }

    $revoked = collect(regressionWorkspaceLog($account['organization']->id, 'organization.environment_key_revoked'))
        ->mapWithKeys(static fn (AuditEntry $entry): array => [(string) ($entry->context['key_id'] ?? '') => $entry->context['because_parent_revoked'] ?? false]);

    expect($revoked->all())->toEqual([$parent['id'] => false, $child['id'] => true, $grandchild['id'] => true]);
})->group('security');

it('stops a child that outlived its revoked parent the next time the parent is revoked', function (): void {
    $account = provisionAccount();
    $environment = serveOnTestHost($account['environment']);
    $workspaceKey = app(OrganizationApiKeys::class)->issue($account['organization']->id, 'Ops', MembershipRole::Admin, null, ['workspace:read', 'keys:write'])->plaintext;

    $parent = $this->withToken($workspaceKey)->postJson("/api/v1/workspace/environments/{$environment->id}/keys", ['name' => 'Parent', 'scopes' => ['keys:read', 'keys:write']])
        ->assertCreated()->json('data');
    $child = $this->withToken($parent['token'])->postJson('/api/v1/keys', ['name' => 'Child', 'scopes' => ['keys:read']])->assertCreated()->json('data');

    // Revoked the way this endpoint used to: the parent alone.
    app(EnvironmentApiKeys::class)->revoke($environment->id, $parent['id']);
    $this->withToken($child['token'])->getJson('/api/v1/keys')->assertOk();

    $this->withToken($workspaceKey)->deleteJson("/api/v1/workspace/environments/{$environment->id}/keys/{$parent['id']}")->assertNoContent();

    $this->withToken($child['token'])->getJson('/api/v1/keys')->assertUnauthorized();

    // Only what changed now is recorded.
    expect(array_map(static fn (AuditEntry $entry): mixed => $entry->context['key_id'] ?? null, regressionWorkspaceLog($account['organization']->id, 'organization.environment_key_revoked')))
        ->toBe([$child['id']]);
})->group('security');

/*
 * 3. The platform registers its step-up client the first time any approval is asked for —
 *    in the middle of somebody's action. Its `app.created` was written as that caller's,
 *    through their door: "an agent's key created a first-party client in the root, over REST".
 */
it('registers the step-up client as the platform, not as the key whose action first needed it', function (): void {
    $account = provisionAccount();
    $environment = serveOnTestHost($account['environment']);
    $key = app(EnvironmentApiKeys::class)->issue($environment->id, 'Supervised', ['keys:read', 'keys:write'], null, new KeyProvenance(
        createdByType: 'organization_member',
        createdById: $account['subjectId'],
        stepUpPolicy: ['min_danger' => 'critical', 'actions' => []],
    ));

    $this->withToken($key->plaintext)->postJson('/api/v1/keys', ['name' => 'Child', 'scopes' => ['keys:read']])->assertStatus(202);

    $registered = app(PlatformRoot::class)->run(static fn (): ?AuditEntry => AuditEntry::query()
        ->where('action', 'app.created')
        ->get()
        ->first(static fn (AuditEntry $entry): bool => ($entry->context['name'] ?? null) === StepUpClient::NAME));

    expect($registered)->not->toBeNull()
        ->and($registered?->actor_type)->not->toBe(ActorType::Service)
        ->and($registered?->actor_id)->toBeNull()
        ->and($registered?->context ?? [])->not->toHaveKey(ActionTrail::VIA)
        ->and($registered?->context ?? [])->not->toHaveKey(EnvironmentKeyAuditLog::CONTEXT_KEY)
        ->and(AuditEntry::query()->withoutGlobalScopes()->where('actor_id', $key->key->id)->count())->toBe(0);

    // The key is the caller again once the client is there: its approved request is its own.
    expect(EnvironmentApiKey::query()->withoutGlobalScopes()->where('name', 'Child')->exists())->toBeFalse();
})->group('security');

/*
 * 4. Projects are the workspace's billing anchors, and creating, renaming, suspending or
 *    reactivating one recorded nothing at all — from the API or the console.
 */
it('records every project write on the workspace\'s log, as whoever made it', function (): void {
    $workspace = provisionAccount()['organization'];
    $issued = app(OrganizationApiKeys::class)->issue($workspace->id, 'Ops', MembershipRole::Admin, null, ['workspace:read', 'projects:write']);
    $key = $issued->plaintext;

    $project = $this->withToken($key)->postJson('/api/v1/workspace/projects', ['name' => 'Product', 'environment_limit' => 3])->assertCreated()->json('data.id');
    $this->withToken($key)->patchJson("/api/v1/workspace/projects/{$project}", ['name' => 'Product Two'])->assertOk();
    $this->withToken($key)->postJson("/api/v1/workspace/projects/{$project}/suspend")->assertOk();
    $this->withToken($key)->postJson("/api/v1/workspace/projects/{$project}/reactivate")->assertOk();

    $expected = [
        'organization.project_created' => ['name' => 'Product', 'environment_limit' => 3],
        'organization.project_renamed' => ['from' => 'Product', 'name' => 'Product Two'],
        'organization.project_suspended' => ['name' => 'Product Two'],
        'organization.project_reactivated' => ['name' => 'Product Two'],
    ];

    foreach ($expected as $action => $context) {
        $entries = regressionWorkspaceLog($workspace->id, $action);

        expect($entries)->toHaveCount(1, "{$action} was not recorded once")
            ->and($entries[0]->actor_type)->toBe(ActorType::Service)
            ->and($entries[0]->actor_id)->toBe($issued->key->id)
            ->and($entries[0]->target_type)->toBe('project')
            ->and($entries[0]->target_id)->toBe($project)
            ->and($entries[0]->context)->toMatchArray([...$context, ActionTrail::VIA => 'rest']);
    }
});
