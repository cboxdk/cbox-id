<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Contracts\Projects;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\ValueObjects\KeyProvenance;

/**
 * A KEY'S APPROVER IS WHOEVER MINTED IT ONLY WHILE THEY STILL RUN THE WORKSPACE.
 *
 * The person behind a key with an approval policy was read off the minting chain and never
 * re-checked: an administrator removed from the workspace stayed the one person who could
 * approve the key's held Critical actions (minting keys, rotating secrets), from their own
 * account and phone, and no current owner could. Removed, they are nobody to ask; an
 * approval they gave before the removal is spent by nobody.
 *
 * @return array{key: string, workspace: string, admin: string}
 */
function raWorkspaceKey(): array
{
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

    [$workspace, $admin] = app(PlatformRoot::class)->run(function (): array {
        $workspace = app(Organizations::class)->create(new NewOrganization('Workspace', 'workspace-'.uniqid()));
        $owner = app(Subjects::class)->create('owner@workspace.test', 'Olive Owner', 'supersecret123');
        $admin = app(Subjects::class)->create('admin@workspace.test', 'Ada Admin', 'supersecret123');
        app(Memberships::class)->add($workspace->id, $owner->id, MembershipRole::Owner);
        app(Memberships::class)->add($workspace->id, $admin->id, MembershipRole::Owner);

        return [$workspace->id, $admin->id];
    });

    $project = app(Projects::class)->createForOrganization($workspace, 'Product');
    Environment::query()->whereKey('env_test')->update(['project_id' => $project->id]);

    $key = app(EnvironmentApiKeys::class)->issue('env_test', 'Deploy agent', ['keys:read', 'keys:write'], null, new KeyProvenance(
        createdByType: 'organization_member',
        createdById: $admin,
        stepUpPolicy: ['min_danger' => 'critical', 'actions' => []],
    ))->plaintext;

    return ['key' => $key, 'workspace' => $workspace, 'admin' => $admin];
}

function raRemove(string $workspace, string $admin): void
{
    app(PlatformRoot::class)->run(fn () => app(Memberships::class)->remove($workspace, $admin));
}

it('files a held action with nobody once the minter has left the workspace (#38)', function (): void {
    ['key' => $key, 'workspace' => $workspace, 'admin' => $admin] = raWorkspaceKey();

    // While they run the workspace, the action waits for them.
    $this->withToken($key)->withHeader('Idempotency-Key', 'before')->postJson('/api/v1/keys', ['name' => 'Child', 'scopes' => ['keys:read']])
        ->assertStatus(202)->assertJsonPath('error', 'approval_required');
    $this->flushHeaders();

    raRemove($workspace, $admin);

    $this->withToken($key)->withHeader('Idempotency-Key', 'after')->postJson('/api/v1/keys', ['name' => 'Child', 'scopes' => ['keys:read']])
        ->assertForbidden()->assertJsonPath('error', 'approval_unavailable');
})->group('security');

it('spends no approval the minter gave before they were removed (#38)', function (): void {
    ['key' => $key, 'workspace' => $workspace, 'admin' => $admin] = raWorkspaceKey();
    $body = ['name' => 'Child', 'scopes' => ['keys:read']];

    $approval = (string) $this->withToken($key)->withHeader('Idempotency-Key', 'held')->postJson('/api/v1/keys', $body)
        ->assertStatus(202)->json('approval.id');

    expect(app(PlatformRoot::class)->run(fn () => app(BackchannelAuthentication::class)->approve($approval, $admin)))->toBeTrue();

    raRemove($workspace, $admin);

    $this->withToken($key)->withHeader('Idempotency-Key', 'held')->withHeader('Cbox-Approval', $approval)
        ->postJson('/api/v1/keys', $body)->assertForbidden()->assertJsonPath('error', 'approval_unavailable');
})->group('security');
