<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\ValueObjects\KeyProvenance;

/*
|--------------------------------------------------------------------------
| A key can be told to wait for its owner's approval, on their device, before it acts.
|--------------------------------------------------------------------------
*/

/** The person who owns a key: a subject in the platform root, where their devices are. */
function keyOwner(): string
{
    platformRootEnvironment();

    // A platform root makes an unmapped host resolve to the root; the keys under test live
    // in `env_test`, so that environment answers on the test host.
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

    return app(PlatformRoot::class)->run(fn () => app(Subjects::class)->create('owner@workspace.test', 'Ada Owner', 'supersecret123')->id);
}

/** A key minted "in the console" by $owner, with a step-up policy. */
function supervisedKey(?string $owner, ?array $policy, array $scopes = ['keys:read', 'keys:write']): array
{
    $issued = app(EnvironmentApiKeys::class)->issue('env_test', 'Deploy agent', $scopes, null, new KeyProvenance(
        createdByType: $owner === null ? null : 'organization_member',
        createdById: $owner,
        stepUpPolicy: $policy,
    ));

    return [$issued->plaintext, $issued->key];
}

function approveAsOwner(string $approvalId, string $owner): bool
{
    return app(PlatformRoot::class)->run(fn () => app(BackchannelAuthentication::class)->approve($approvalId, $owner));
}

it('holds a critical action for the owner\'s approval, then runs it once', function (): void {
    $owner = keyOwner();
    [$key] = supervisedKey($owner, ['min_danger' => 'critical', 'actions' => []]);
    $body = ['name' => 'Child', 'scopes' => ['keys:read']];

    $held = $this->withToken($key)->withHeader('Idempotency-Key', 'mint-held')->postJson('/api/v1/keys', $body)
        ->assertStatus(202)
        ->assertJsonPath('error', 'approval_required')
        ->assertHeader('Retry-After');

    $approval = $held->json('approval');

    expect($approval['binding_code'])->toMatch('/^[0-9A-F]{4}$/')
        ->and(app(PlatformRoot::class)->run(fn () => BackchannelAuthRequest::query()->whereKey($approval['id'])->value('binding_message')))
        ->toContain('Deploy agent')->toContain($approval['binding_code']);

    $this->withToken($key)->getJson("/api/v1/action-approvals/{$approval['id']}")->assertOk()->assertJsonPath('data.status', 'pending');

    // Repeating before approval is refused, not run.
    $this->withToken($key)->withHeader('Idempotency-Key', 'mint-held')->withHeader('Cbox-Approval', $approval['id'])
        ->postJson('/api/v1/keys', $body)->assertStatus(409)->assertJsonPath('error', 'approval_pending');

    expect(approveAsOwner($approval['id'], $owner))->toBeTrue();

    $this->withToken($key)->withHeader('Idempotency-Key', 'mint-held')->withHeader('Cbox-Approval', $approval['id'])
        ->postJson('/api/v1/keys', $body)->assertCreated()->assertJsonPath('data.name', 'Child');

    // Spent: the same approval cannot run a second request. (Headers persist between
    // requests in a test; this one is a NEW request, not a replay of the first.)
    $this->flushHeaders();
    $this->withToken($key)->withHeader('Cbox-Approval', $approval['id'])
        ->postJson('/api/v1/keys', $body)->assertStatus(409)->assertJsonPath('error', 'approval_mismatch');
})->group('security');

it('never lets an approval for one request run a different one', function (): void {
    $owner = keyOwner();
    [$key] = supervisedKey($owner, ['min_danger' => 'critical', 'actions' => []]);

    $approval = $this->withToken($key)->postJson('/api/v1/keys', ['name' => 'Narrow', 'scopes' => ['keys:read']])->json('approval');
    approveAsOwner($approval['id'], $owner);

    $this->withToken($key)->withHeader('Cbox-Approval', $approval['id'])
        ->postJson('/api/v1/keys', ['name' => 'Wide', 'scopes' => ['keys:read', 'keys:write']])
        ->assertStatus(409)->assertJsonPath('error', 'approval_mismatch');
})->group('security');

it('refuses another key\'s approval', function (): void {
    $owner = keyOwner();
    [$a] = supervisedKey($owner, ['min_danger' => 'critical', 'actions' => []]);
    [$b] = supervisedKey($owner, ['min_danger' => 'critical', 'actions' => []]);

    $approval = $this->withToken($a)->postJson('/api/v1/keys', ['name' => 'X', 'scopes' => ['keys:read']])->json('approval');
    approveAsOwner($approval['id'], $owner);

    $this->withToken($b)->getJson("/api/v1/action-approvals/{$approval['id']}")->assertNotFound();
    $this->withToken($b)->withHeader('Cbox-Approval', $approval['id'])
        ->postJson('/api/v1/keys', ['name' => 'X', 'scopes' => ['keys:read']])->assertForbidden()->assertJsonPath('error', 'approval_invalid');
})->group('security');

it('says when the owner denied it', function (): void {
    $owner = keyOwner();
    [$key] = supervisedKey($owner, ['min_danger' => 'critical', 'actions' => []]);
    $body = ['name' => 'X', 'scopes' => ['keys:read']];

    $approval = $this->withToken($key)->postJson('/api/v1/keys', $body)->json('approval');
    app(PlatformRoot::class)->run(fn () => app(BackchannelAuthentication::class)->deny($approval['id'], $owner));

    $this->withToken($key)->withHeader('Cbox-Approval', $approval['id'])->postJson('/api/v1/keys', $body)
        ->assertForbidden()->assertJsonPath('error', 'approval_denied');
});

it('runs actions below the threshold without asking', function (): void {
    $owner = keyOwner();
    [$key] = supervisedKey($owner, ['min_danger' => 'critical', 'actions' => []]);

    $this->withToken($key)->getJson('/api/v1/keys')->assertOk();
});

it('asks for an action named in the policy whatever its danger', function (): void {
    $owner = keyOwner();
    [$key] = supervisedKey($owner, ['min_danger' => null, 'actions' => ['keys.list']]);

    $this->withToken($key)->getJson('/api/v1/keys')->assertStatus(202);
});

it('refuses, never runs, when the policy needs approval and nobody owns the key', function (): void {
    [$key] = supervisedKey(null, ['min_danger' => 'critical', 'actions' => []]);

    $this->withToken($key)->postJson('/api/v1/keys', ['name' => 'X', 'scopes' => ['keys:read']])
        ->assertForbidden()->assertJsonPath('error', 'approval_unavailable');
})->group('security');

it('makes a key-minted key at least as supervised as its parent, and asks the parent\'s owner', function (): void {
    $owner = keyOwner();
    [$parent] = supervisedKey($owner, ['min_danger' => 'destructive', 'actions' => []]);

    $approval = $this->withToken($parent)->postJson('/api/v1/keys', [
        'name' => 'Unsupervised?',
        'scopes' => ['keys:read', 'keys:write'],
        'require_approval' => ['min_danger' => 'critical'],
    ])->json('approval');
    approveAsOwner($approval['id'], $owner);

    $child = $this->withToken($parent)->withHeader('Cbox-Approval', $approval['id'])->postJson('/api/v1/keys', [
        'name' => 'Unsupervised?',
        'scopes' => ['keys:read', 'keys:write'],
        'require_approval' => ['min_danger' => 'critical'],
    ])->assertCreated()->json('data');

    expect($child['require_approval']['min_danger'])->toBe('destructive');

    // The child's held action goes to the same person, through the minting chain.
    $this->flushHeaders();
    $held = $this->withToken($child['token'])->deleteJson("/api/v1/keys/{$child['id']}")->assertStatus(202)->json('approval');

    expect(approveAsOwner($held['id'], $owner))->toBeTrue();
})->group('security');
