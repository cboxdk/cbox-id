<?php

declare(strict_types=1);

use App\Platform\Actions\Approvals\ActionApprovalRequest;
use App\Platform\Console\WebhookEventCatalogue;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\ValueObjects\KeyProvenance;
use Cbox\Ssrf\Contracts\Resolver;

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
        // The environment is named, so an owner with keys in several knows which one is asking.
        ->toContain('Key "Deploy agent" in Test wants to run keys.')->toContain($approval['binding_code']);

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

/*
|--------------------------------------------------------------------------
| A refusal the input already decides is answered BEFORE anyone is asked to approve.
|--------------------------------------------------------------------------
|
| The live run: a key whose policy named webhooks.create had its owner approve on their
| phone, and the repeat was then refused `unsafe_url` — an approval spent on a request that
| could never run. Validation and the action's own preflight come first; the gate after.
*/

const PREFLIGHT_SCOPES = ['webhooks:read', 'webhooks:write', 'hooks:write', 'log_streams:write'];

/** Nothing was filed for anyone to approve. */
function nothingHeldForApproval(): void
{
    expect(ActionApprovalRequest::query()->count())->toBe(0)
        ->and(app(PlatformRoot::class)->run(fn () => BackchannelAuthRequest::query()->count()))->toBe(0);
}

it('refuses an unsafe webhook URL before holding it for approval', function (): void {
    $owner = keyOwner();
    [$key] = supervisedKey($owner, ['min_danger' => null, 'actions' => ['webhooks.create']], PREFLIGHT_SCOPES);
    app(Resolver::class)->set('internal.acme.example', ['10.0.0.5']);
    $body = ['event_types' => [WebhookEventCatalogue::offered()[0]], 'environment_wide' => true];

    $this->withToken($key)->postJson('/api/v1/webhooks', [...$body, 'url' => 'https://internal.acme.example/in'])
        ->assertUnprocessable()->assertJsonPath('error', 'unsafe_url');
    $this->withToken($key)->postJson('/api/v1/webhooks', [...$body, 'url' => 'http://hooks.acme.example/in'])
        ->assertUnprocessable()->assertJsonPath('error', 'unsafe_url');
    $this->withToken($key)->postJson('/api/v1/webhooks', ['url' => 'https://hooks.acme.example/in', 'event_types' => $body['event_types']])
        ->assertUnprocessable()->assertJsonPath('error', 'owner_required');

    nothingHeldForApproval();

    // A request that can run is still held.
    $this->withToken($key)->postJson('/api/v1/webhooks', [...$body, 'url' => 'https://hooks.acme.example/in'])
        ->assertStatus(202)->assertJsonPath('error', 'approval_required');
})->group('security');

it('refuses repointing a webhook at a private address, or a foreign one, before holding it', function (): void {
    $owner = keyOwner();
    [$key] = supervisedKey($owner, ['min_danger' => null, 'actions' => ['webhooks.update']], PREFLIGHT_SCOPES);
    app(Resolver::class)->set('internal.acme.example', ['10.0.0.5']);
    $id = $this->withToken($key)->postJson('/api/v1/webhooks', ['url' => 'https://a.acme.example/in', 'event_types' => [WebhookEventCatalogue::offered()[0]], 'environment_wide' => true])
        ->assertCreated()->json('data.id');

    $this->withToken($key)->patchJson("/api/v1/webhooks/{$id}", ['url' => 'https://internal.acme.example/in'])
        ->assertUnprocessable()->assertJsonPath('error', 'unsafe_url');
    $this->withToken($key)->patchJson('/api/v1/webhooks/whe_nope', ['url' => 'https://b.acme.example/in'])
        ->assertNotFound();

    nothingHeldForApproval();

    $this->withToken($key)->patchJson("/api/v1/webhooks/{$id}", ['url' => 'https://b.acme.example/in'])->assertStatus(202);
})->group('security');

it('refuses an unsafe hook or log stream endpoint before holding a critical action', function (): void {
    $owner = keyOwner();
    [$key] = supervisedKey($owner, ['min_danger' => 'critical', 'actions' => []], PREFLIGHT_SCOPES);
    app(Resolver::class)->set('internal.acme.example', ['10.0.0.5']);

    $this->withToken($key)->postJson('/api/v1/hooks', ['hook_point' => 'post_login', 'url' => 'https://internal.acme.example/login', 'environment_wide' => true])
        ->assertUnprocessable()->assertJsonPath('error', 'unsafe_url');
    $this->withToken($key)->postJson('/api/v1/log-streams', ['name' => 'S', 'destination' => 'generic_json', 'endpoint_url' => 'https://internal.acme.example/c', 'environment_wide' => true])
        ->assertUnprocessable()->assertJsonPath('error', 'unsafe_url');
    $this->withToken($key)->postJson('/api/v1/log-streams', ['name' => 'S', 'destination' => 'generic_json', 'environment_wide' => true])
        ->assertUnprocessable();

    nothingHeldForApproval();
})->group('security');
