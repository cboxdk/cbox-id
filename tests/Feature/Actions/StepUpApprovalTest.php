<?php

declare(strict_types=1);

use App\Mail\ApprovalRequestMail;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Actions\Approvals\ActionApprovalRequest;
use App\Platform\Actions\Danger;
use App\Platform\Api\ApiHost;
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
use Illuminate\Support\Facades\Mail;

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

it('mails the owner of a key whose action was held, with a link to their Approvals page', function (): void {
    $owner = keyOwner();
    Mail::fake();
    [$key] = supervisedKey($owner, ['min_danger' => 'critical', 'actions' => []]);

    $this->withToken($key)->withHeader('Idempotency-Key', 'mail-held')
        ->postJson('/api/v1/keys', ['name' => 'Child', 'scopes' => ['keys:read']])
        ->assertStatus(202);

    Mail::assertQueued(ApprovalRequestMail::class, fn (ApprovalRequestMail $mail): bool => $mail->hasTo('owner@workspace.test')
        && str_ends_with($mail->url, '/approvals')
        && str_contains($mail->app, 'Deploy agent'));

    expect(app(PlatformRoot::class)->run(fn () => BackchannelAuthRequest::query()->where('user_id', $owner)->count()))->toBe(1);
});

it('keeps the ids a held webhook create asked for: the surface it was asked on, never the one it repeats on', function (): void {
    $owner = keyOwner();
    [$key] = supervisedKey($owner, ['min_danger' => null, 'actions' => ['webhooks.create']], PREFLIGHT_SCOPES);
    $body = ['url' => 'https://hooks.acme.example/in', 'event_types' => [WebhookEventCatalogue::offered()[0]], 'environment_wide' => true];
    $v1 = 'http://'.ApiHost::name().'/v1/webhooks';

    // Held on /api/v1: what was asked for is a bare-id endpoint.
    $held = $this->withToken($key)->postJson('/api/v1/webhooks', $body)->assertStatus(202)->json('approval.id');
    expect(approveAsOwner($held, $owner))->toBeTrue();

    // Repeated on /v1, whose default differs: not the request that was approved, so refused.
    $this->withToken($key)->withHeader('Cbox-Approval', $held)->postJson($v1, $body)
        ->assertStatus(409);

    $this->withToken($key)->withHeader('Cbox-Approval', $held)->postJson('/api/v1/webhooks', $body)
        ->assertCreated()->assertJsonPath('data.id_format', 'raw');

    // Held on /v1: the default is part of what the owner approves.
    $this->flushHeaders();
    $heldOnV1 = $this->withToken($key)->postJson($v1, [...$body, 'url' => 'https://v1.acme.example/in'])->assertStatus(202)->json('id');
    $heldOnV1 = substr((string) $heldOnV1, strlen('apr_'));

    expect(ActionApprovalRequest::query()->whereKey($heldOnV1)->value('input'))->toMatchArray(['id_format' => 'prefixed'])
        ->and(approveAsOwner($heldOnV1, $owner))->toBeTrue();

    $this->withToken($key)->withHeader('Cbox-Approval', $heldOnV1)->postJson($v1, [...$body, 'url' => 'https://v1.acme.example/in'])
        ->assertCreated()->assertJsonPath('id_format', 'prefixed');
})->group('security');

/*
|--------------------------------------------------------------------------
| A Write action that reaches a Critical sibling's effect waits like the sibling.
|--------------------------------------------------------------------------
| Audit finding: `webhooks.update`, `apps.update`, `radar.lists.add`, `radar.settings.update`,
| `signin.social.enable` and `apps.settings.client_credentials` were rated Write, so a key or
| an agent token whose policy holds Critical actions repointed a webhook, added a redirect
| URI or allow-listed 0.0.0.0/0 with nobody asked. The run is rated by what it changes now.
*/

it('holds repointing a webhook like creating one, and lets an events-only edit through', function (): void {
    $owner = keyOwner();
    [$free] = supervisedKey($owner, null, PREFLIGHT_SCOPES);
    [$key] = supervisedKey($owner, ['min_danger' => 'critical', 'actions' => []], PREFLIGHT_SCOPES);
    $events = WebhookEventCatalogue::offered();
    $id = $this->withToken($free)->postJson('/api/v1/webhooks', ['url' => 'https://a.acme.example/in', 'event_types' => [$events[0]], 'environment_wide' => true])
        ->assertCreated()->json('data.id');

    $this->withToken($key)->patchJson("/api/v1/webhooks/{$id}", ['url' => 'https://attacker.example/collect'])
        ->assertStatus(202)->assertJsonPath('error', 'approval_required');
    $this->withToken($key)->patchJson("/api/v1/webhooks/{$id}", ['url' => 'https://a.acme.example/in', 'event_types' => [$events[0], $events[1]]])
        ->assertOk();

    $this->withToken($free)->getJson("/api/v1/webhooks/{$id}")->assertOk()->assertJsonPath('data.url', 'https://a.acme.example/in');
})->group('security');

it('holds adding a redirect URI to an app like creating one, and lets a rename through', function (): void {
    $owner = keyOwner();
    [$free] = supervisedKey($owner, null, ['apps:read', 'apps:write']);
    [$key] = supervisedKey($owner, ['min_danger' => 'critical', 'actions' => []], ['apps:read', 'apps:write']);
    $id = $this->withToken($free)->postJson('/api/v1/apps', ['name' => 'Shop', 'type' => 'spa', 'redirect_uris' => ['https://shop.acme.example/callback']])
        ->assertCreated()->json('data.id');

    $this->withToken($key)->patchJson("/api/v1/apps/{$id}", ['redirect_uris' => ['https://shop.acme.example/callback', 'https://attacker.example/cb']])
        ->assertStatus(202)->assertJsonPath('error', 'approval_required');
    $this->withToken($key)->patchJson("/api/v1/apps/{$id}", ['name' => 'Shop 2', 'redirect_uris' => ['https://shop.acme.example/callback']])
        ->assertOk()->assertJsonPath('data.name', 'Shop 2');

    $this->withToken($free)->getJson("/api/v1/apps/{$id}")->assertOk()->assertJsonPath('data.redirect_uris', ['https://shop.acme.example/callback']);
})->group('security');

it('holds a Radar allow entry and every change to the built-in rules, and lets a deny entry through', function (): void {
    $owner = keyOwner();
    [$key] = supervisedKey($owner, ['min_danger' => 'critical', 'actions' => []], ['radar:read', 'radar:write']);

    $this->withToken($key)->postJson('/api/v1/radar/lists', ['list' => 'allow', 'kind' => 'ip', 'value' => '0.0.0.0/0'])
        ->assertStatus(202)->assertJsonPath('error', 'approval_required');
    $this->withToken($key)->patchJson('/api/v1/radar/settings', ['builtin_rules' => ['bot_velocity' => ['enabled' => false]]])
        ->assertStatus(202)->assertJsonPath('error', 'approval_required');
    $this->withToken($key)->postJson('/api/v1/radar/lists', ['list' => 'deny', 'kind' => 'ip', 'value' => '203.0.113.0/24'])
        ->assertCreated();
})->group('security');

it('rates the sign-in door and the machine grant as their Critical siblings are', function (): void {
    $registry = app(ActionRegistry::class);

    expect($registry->named('signin.social.enable')->danger)->toBe(Danger::Critical)
        ->and($registry->named('radar.settings.update')->danger)->toBe(Danger::Critical)
        // Rated by the input: these may be Critical, so consent flags their scopes and an
        // approval page asks for a fresh credential before approving a held run.
        ->and($registry->named('apps.settings.client_credentials')->mayBeCritical())->toBeTrue()
        ->and($registry->named('webhooks.update')->mayBeCritical())->toBeTrue()
        ->and($registry->named('apps.update')->mayBeCritical())->toBeTrue()
        ->and($registry->named('radar.lists.add')->mayBeCritical())->toBeTrue();
})->group('security');
