<?php

declare(strict_types=1);

use App\Platform\Actions\Idempotency\IdempotencyRecord;
use App\Platform\Console\WebhookEventCatalogue;
use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\AuditStreaming\Models\AuditStream;
use Cbox\Id\ExternalActions\Contracts\ExternalActions;
use Cbox\Id\ExternalActions\Enums\ActionEndpointStatus;
use Cbox\Id\ExternalActions\Enums\HookPoint;
use Cbox\Id\ExternalActions\Models\ExternalActionEndpoint;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Events\Contracts\EventBus;
use Cbox\Id\Kernel\Events\ValueObjects\DomainEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;
use Cbox\Id\Webhooks\Enums\EndpointStatus;
use Cbox\Id\Webhooks\Models\WebhookEndpoint;
use Cbox\LaravelSiem\Contracts\LogStreams;
use Cbox\LaravelSiem\Enums\AuthScheme;
use Cbox\LaravelSiem\Enums\Destination;
use Cbox\Ssrf\Contracts\Resolver;

/*
|--------------------------------------------------------------------------
| Webhooks, inline hooks, log streams, events and the audit log, as actions.
|--------------------------------------------------------------------------
|
| Every endpoint, its scope, its tenant boundary and its secret-once rule — and the line
| each write leaves on the trail, from the API and the console alike. The console's edit,
| pause, resume, re-key and delete used to be inline model writes that recorded nothing.
*/

const INTEG_WRITE = ['webhooks:read', 'webhooks:write', 'hooks:read', 'hooks:write', 'log_streams:read', 'log_streams:write', 'events:read', 'audit:read'];

/** @param  list<string>  $scopes */
function integrationKey(array $scopes = INTEG_WRITE): string
{
    return app(EnvironmentApiKeys::class)->issue('env_test', 'Integrations worker', $scopes)->plaintext;
}

function integrationOrg(string $slug = 'acme-integrations'): string
{
    return app(Organizations::class)->create(new NewOrganization('Acme', $slug))->id;
}

function integrationEvent(): string
{
    return WebhookEventCatalogue::offered()[0];
}

/** Run in ANOTHER environment than the one every request here resolves to. */
function inIntegrationsOtherEnvironment(Closure $callback): mixed
{
    return app(EnvironmentContext::class)->runAs(GenericEnvironment::of('env_other'), $callback);
}

/** @return list<AuditEntry> */
function integrationTrail(string $action): array
{
    return array_values(AuditEntry::query()->where('action', $action)->orderBy('id')->get()->all());
}

/** Sign an organization's administrator into its own console; returns [subject id, organization id]. */
function integrationOrgAdmin(): array
{
    $subject = app(Subjects::class)->create('integrations@acme.test', 'Integrations Admin', 'supersecret123');
    app(Subjects::class)->markEmailVerified($subject->id, (string) $subject->email);
    $subject = app(Subjects::class)->find($subject->id) ?? $subject;

    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-console'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);
    session([PlatformAuth::SESSION_KEY => $session->id]);

    return [$subject->id, $org->id];
}

// ── Webhooks ─────────────────────────────────────────────────────────────────

it('registers a webhook for an organization, shows its secret once, and records who did it', function (): void {
    $key = integrationKey();
    $org = integrationOrg();

    $created = $this->withToken($key)->postJson('/api/v1/webhooks', [
        'url' => 'https://hooks.acme.example/in',
        'event_types' => [integrationEvent()],
        'organization_id' => $org,
    ])->assertCreated()->json('data');

    expect($created['secret'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($created['organization_id'])->toBe($org)
        ->and($created['active'])->toBeTrue();

    $this->withToken($key)->getJson('/api/v1/webhooks')->assertOk()
        ->assertJsonPath('data.0.id', $created['id'])
        ->assertJsonMissingPath('data.0.secret');
    $this->withToken($key)->getJson("/api/v1/webhooks/{$created['id']}")->assertOk()
        ->assertJsonMissingPath('data.secret');

    [$entry] = integrationTrail('webhook.created');

    expect($entry->actor_type)->toBe(ActorType::Service)
        ->and($entry->organization_id)->toBe($org)
        ->and($entry->target_id)->toBe($created['id'])
        ->and(json_encode($entry->context))->not->toContain($created['secret']);
});

it('makes the owner explicit: an organization, or environment_wide, never neither', function (): void {
    $key = integrationKey();
    $body = ['url' => 'https://hooks.acme.example/in', 'event_types' => [integrationEvent()]];

    $this->withToken($key)->postJson('/api/v1/webhooks', $body)
        ->assertUnprocessable()->assertJsonPath('error', 'owner_required');
    $this->withToken($key)->postJson('/api/v1/webhooks', [...$body, 'environment_wide' => true, 'organization_id' => integrationOrg()])
        ->assertUnprocessable()->assertJsonPath('error', 'ambiguous_owner');
    $this->withToken($key)->postJson('/api/v1/webhooks', [...$body, 'organization_id' => 'org_missing'])
        ->assertUnprocessable()->assertJsonPath('error', 'organization_not_found');

    $this->withToken($key)->postJson('/api/v1/webhooks', [...$body, 'environment_wide' => true])
        ->assertCreated()->assertJsonPath('data.organization_id', null);
});

it('refuses a private address, a non-URL and an event nothing sends', function (): void {
    $key = integrationKey();
    app(Resolver::class)->set('internal.acme.example', ['10.0.0.5']);
    $body = ['event_types' => [integrationEvent()], 'environment_wide' => true];

    $this->withToken($key)->postJson('/api/v1/webhooks', [...$body, 'url' => 'https://internal.acme.example/in'])
        ->assertUnprocessable()->assertJsonPath('error', 'unsafe_url');
    $this->withToken($key)->postJson('/api/v1/webhooks', [...$body, 'url' => 'not a url'])
        ->assertUnprocessable()->assertJsonPath('error', 'invalid_url');
    $this->withToken($key)->postJson('/api/v1/webhooks', [...$body, 'url' => 'https://hooks.acme.example/in', 'event_types' => ['nothing.sends_this']])
        ->assertUnprocessable()->assertJsonPath('error', 'validation_failed');

    expect(WebhookEndpoint::query()->count())->toBe(0)
        ->and(integrationTrail('webhook.created'))->toBe([]);
});

it('never keeps the signing secret for an idempotent replay', function (): void {
    $key = integrationKey();
    $body = ['url' => 'https://hooks.acme.example/in', 'event_types' => [integrationEvent()], 'environment_wide' => true];

    $first = $this->withToken($key)->withHeader('Idempotency-Key', 'wh-1')->postJson('/api/v1/webhooks', $body)->assertCreated();
    $again = $this->withToken($key)->withHeader('Idempotency-Key', 'wh-1')->postJson('/api/v1/webhooks', $body)->assertCreated();

    expect($first->json('data.secret'))->toMatch('/^[0-9a-f]{64}$/')
        ->and($again->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($again->json('data.id'))->toBe($first->json('data.id'))
        ->and($again->json('data.secret'))->toBeNull()
        ->and(json_encode(IdempotencyRecord::query()->sole()->payload))->not->toContain((string) $first->json('data.secret'))
        ->and(WebhookEndpoint::query()->count())->toBe(1);
});

it('repoints and resubscribes an endpoint, recording what changed and nothing for a no-op', function (): void {
    $key = integrationKey();
    $events = WebhookEventCatalogue::offered();
    $id = $this->withToken($key)->postJson('/api/v1/webhooks', ['url' => 'https://a.acme.example/in', 'event_types' => [$events[0]], 'environment_wide' => true])->json('data.id');

    $this->withToken($key)->patchJson("/api/v1/webhooks/{$id}", ['url' => 'https://b.acme.example/in', 'event_types' => [$events[0], $events[1]]])
        ->assertOk()
        ->assertJsonPath('data.url', 'https://b.acme.example/in')
        ->assertJsonPath('data.event_types', [$events[0], $events[1]]);

    $this->withToken($key)->patchJson("/api/v1/webhooks/{$id}", ['url' => 'https://b.acme.example/in'])->assertOk();

    $trail = integrationTrail('webhook.updated');

    expect($trail)->toHaveCount(1)
        ->and($trail[0]->context['changes']['url'])->toBe(['from' => 'https://a.acme.example/in', 'to' => 'https://b.acme.example/in']);
});

it('refuses repointing at a private address, and an event the endpoint may not hear', function (): void {
    $key = integrationKey();
    app(Resolver::class)->set('internal.acme.example', ['10.0.0.5']);
    $id = $this->withToken($key)->postJson('/api/v1/webhooks', ['url' => 'https://a.acme.example/in', 'event_types' => [integrationEvent()], 'environment_wide' => true])->json('data.id');

    $this->withToken($key)->patchJson("/api/v1/webhooks/{$id}", ['url' => 'https://internal.acme.example/in'])
        ->assertUnprocessable()->assertJsonPath('error', 'unsafe_url');
    $this->withToken($key)->patchJson("/api/v1/webhooks/{$id}", ['event_types' => ['nothing.sends_this']])
        ->assertUnprocessable()->assertJsonPath('error', 'unknown_event');

    expect(WebhookEndpoint::query()->whereKey($id)->value('url'))->toBe('https://a.acme.example/in');
});

it('pauses and resumes an endpoint, once each on the trail', function (): void {
    $key = integrationKey();
    $id = $this->withToken($key)->postJson('/api/v1/webhooks', ['url' => 'https://a.acme.example/in', 'event_types' => [integrationEvent()], 'environment_wide' => true])->json('data.id');

    $this->withToken($key)->postJson("/api/v1/webhooks/{$id}/pause")->assertOk()->assertJsonPath('data.active', false);
    $this->withToken($key)->postJson("/api/v1/webhooks/{$id}/pause")->assertOk()->assertJsonPath('data.active', false);
    $this->withToken($key)->postJson("/api/v1/webhooks/{$id}/resume")->assertOk()->assertJsonPath('data.active', true);

    expect(integrationTrail('webhook.paused'))->toHaveCount(1)
        ->and(integrationTrail('webhook.resumed'))->toHaveCount(1)
        ->and(WebhookEndpoint::query()->whereKey($id)->value('status'))->toBe(EndpointStatus::Active);
});

it('rotates the signing secret, returns it once, and records that it happened but not what it is', function (): void {
    $key = integrationKey();
    $created = $this->withToken($key)->postJson('/api/v1/webhooks', ['url' => 'https://a.acme.example/in', 'event_types' => [integrationEvent()], 'environment_wide' => true])->json('data');
    $sealed = WebhookEndpoint::query()->whereKey($created['id'])->value('secret_encrypted');

    $rotated = $this->withToken($key)->withHeader('Idempotency-Key', 'rot-1')->postJson("/api/v1/webhooks/{$created['id']}/rotate")->assertOk()->json('data.secret');
    $replayed = $this->withToken($key)->withHeader('Idempotency-Key', 'rot-1')->postJson("/api/v1/webhooks/{$created['id']}/rotate")->assertOk();

    [$entry] = integrationTrail('webhook.secret_rotated');

    expect($rotated)->toMatch('/^[0-9a-f]{64}$/')
        ->and($rotated)->not->toBe($created['secret'])
        ->and(WebhookEndpoint::query()->whereKey($created['id'])->value('secret_encrypted'))->not->toBe($sealed)
        ->and($replayed->json('data.secret'))->toBeNull()
        ->and(json_encode($entry->context))->not->toContain($rotated)
        ->and(integrationTrail('webhook.secret_rotated'))->toHaveCount(1);
});

it('deletes an endpoint and keeps where it pointed on the trail', function (): void {
    $key = integrationKey();
    $id = $this->withToken($key)->postJson('/api/v1/webhooks', ['url' => 'https://a.acme.example/in', 'event_types' => [integrationEvent()], 'environment_wide' => true])->json('data.id');

    $this->withToken($key)->deleteJson("/api/v1/webhooks/{$id}")->assertNoContent();
    $this->withToken($key)->getJson("/api/v1/webhooks/{$id}")->assertNotFound();

    [$entry] = integrationTrail('webhook.deleted');

    expect($entry->target_id)->toBe($id)
        ->and($entry->context['url'])->toBe('https://a.acme.example/in');
});

it('needs webhooks:write to change and webhooks:read to look', function (): void {
    $reader = integrationKey(['webhooks:read']);
    $id = $this->withToken(integrationKey())->postJson('/api/v1/webhooks', ['url' => 'https://a.acme.example/in', 'event_types' => [integrationEvent()], 'environment_wide' => true])->json('data.id');

    $this->withToken($reader)->getJson('/api/v1/webhooks')->assertOk();
    $this->withToken($reader)->postJson('/api/v1/webhooks', ['url' => 'https://b.acme.example/in', 'event_types' => [integrationEvent()], 'environment_wide' => true])->assertForbidden();
    $this->withToken($reader)->postJson("/api/v1/webhooks/{$id}/rotate")->assertForbidden();
    $this->withToken($reader)->deleteJson("/api/v1/webhooks/{$id}")->assertForbidden();
    $this->withToken(integrationKey(['hooks:read']))->getJson('/api/v1/webhooks')->assertForbidden();
});

it('never reaches another environment\'s endpoint', function (): void {
    $key = integrationKey();
    $foreign = inIntegrationsOtherEnvironment(fn (): string => app(WebhookRegistry::class)
        ->registerForEnvironment('https://other.acme.example/in', [integrationEvent()])->endpoint->id);

    $this->withToken($key)->getJson('/api/v1/webhooks')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($key)->getJson("/api/v1/webhooks/{$foreign}")->assertNotFound();
    $this->withToken($key)->postJson("/api/v1/webhooks/{$foreign}/rotate")->assertNotFound();
    $this->withToken($key)->deleteJson("/api/v1/webhooks/{$foreign}")->assertNotFound();

    expect(inIntegrationsOtherEnvironment(fn (): bool => WebhookEndpoint::query()->whereKey($foreign)->exists()))->toBeTrue();
})->group('security');

// ── Inline hooks ─────────────────────────────────────────────────────────────

it('registers an inline hook, shows its secret once, and records who asked', function (): void {
    $key = integrationKey();
    $org = integrationOrg();

    $hook = $this->withToken($key)->withHeader('Idempotency-Key', 'hk-1')->postJson('/api/v1/hooks', [
        'hook_point' => HookPoint::TokenMinting->value,
        'url' => 'https://hooks.acme.example/token',
        'organization_id' => $org,
    ])->assertCreated()->json('data');
    $replayed = $this->withToken($key)->withHeader('Idempotency-Key', 'hk-1')->postJson('/api/v1/hooks', [
        'hook_point' => HookPoint::TokenMinting->value,
        'url' => 'https://hooks.acme.example/token',
        'organization_id' => $org,
    ])->assertCreated();

    expect($hook['secret'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($hook['hook_point'])->toBe('token_minting')
        ->and($replayed->json('data.secret'))->toBeNull();

    $this->withToken($key)->getJson('/api/v1/hooks')->assertOk()->assertJsonMissingPath('data.0.secret');
    $this->withToken($key)->getJson("/api/v1/hooks/{$hook['id']}")->assertOk()->assertJsonPath('data.active', true);

    [$entry] = integrationTrail('inline_hook.created');

    expect($entry->actor_type)->toBe(ActorType::Service)
        ->and($entry->organization_id)->toBe($org)
        ->and($entry->context['hook_point'])->toBe('token_minting');
});

it('pauses and activates a hook by the state it should end in, and removes it', function (): void {
    $key = integrationKey();
    $id = $this->withToken($key)->postJson('/api/v1/hooks', ['hook_point' => 'post_login', 'url' => 'https://hooks.acme.example/login', 'environment_wide' => true])->json('data.id');

    $this->withToken($key)->patchJson("/api/v1/hooks/{$id}", ['active' => false])->assertOk()->assertJsonPath('data.active', false);
    $this->withToken($key)->patchJson("/api/v1/hooks/{$id}", ['active' => false])->assertOk();
    $this->withToken($key)->patchJson("/api/v1/hooks/{$id}", ['active' => true])->assertOk()->assertJsonPath('data.active', true);
    $this->withToken($key)->patchJson("/api/v1/hooks/{$id}", [])->assertUnprocessable()->assertJsonPath('error', 'validation_failed');
    $this->withToken($key)->deleteJson("/api/v1/hooks/{$id}")->assertNoContent();

    expect(integrationTrail('inline_hook.paused'))->toHaveCount(1)
        ->and(integrationTrail('inline_hook.activated'))->toHaveCount(1)
        ->and(integrationTrail('inline_hook.deleted'))->toHaveCount(1)
        ->and(ExternalActionEndpoint::query()->whereKey($id)->exists())->toBeFalse();
});

it('refuses a hook at a private address, and needs hooks:write', function (): void {
    app(Resolver::class)->set('internal.acme.example', ['10.0.0.5']);

    $this->withToken(integrationKey())->postJson('/api/v1/hooks', ['hook_point' => 'post_login', 'url' => 'https://internal.acme.example/login', 'environment_wide' => true])
        ->assertUnprocessable()->assertJsonPath('error', 'unsafe_url');
    $this->withToken(integrationKey(['hooks:read']))->postJson('/api/v1/hooks', ['hook_point' => 'post_login', 'url' => 'https://hooks.acme.example/login', 'environment_wide' => true])
        ->assertForbidden();

    expect(ExternalActionEndpoint::query()->count())->toBe(0);
});

it('never reaches another environment\'s hook', function (): void {
    config(['cbox-id.external_actions.verify_url' => false]);
    $key = integrationKey();
    $foreign = inIntegrationsOtherEnvironment(fn (): string => app(ExternalActions::class)
        ->registerForEnvironment(HookPoint::PostLogin, 'https://other.acme.example/login')->endpoint->id);

    $this->withToken($key)->getJson('/api/v1/hooks')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($key)->getJson("/api/v1/hooks/{$foreign}")->assertNotFound();
    $this->withToken($key)->patchJson("/api/v1/hooks/{$foreign}", ['active' => false])->assertNotFound();
    $this->withToken($key)->deleteJson("/api/v1/hooks/{$foreign}")->assertNotFound();

    expect(inIntegrationsOtherEnvironment(fn (): ?ActionEndpointStatus => ExternalActionEndpoint::query()->whereKey($foreign)->value('status')))
        ->toBe(ActionEndpointStatus::Active);
})->group('security');

// ── Log streams ──────────────────────────────────────────────────────────────

it('creates a log stream, returning a generated key once and never echoing a supplied token', function (): void {
    $key = integrationKey();

    $hmac = $this->withToken($key)->withHeader('Idempotency-Key', 'ls-1')->postJson('/api/v1/log-streams', [
        'name' => 'Acme SIEM',
        'destination' => 'generic_json',
        'endpoint_url' => 'https://siem.acme.example/collector',
        'auth' => 'hmac',
        'environment_wide' => true,
    ])->assertCreated();
    $replayed = $this->withToken($key)->withHeader('Idempotency-Key', 'ls-1')->postJson('/api/v1/log-streams', [
        'name' => 'Acme SIEM',
        'destination' => 'generic_json',
        'endpoint_url' => 'https://siem.acme.example/collector',
        'auth' => 'hmac',
        'environment_wide' => true,
    ])->assertCreated();

    $bearer = $this->flushHeaders()->withToken($key)->postJson('/api/v1/log-streams', [
        'name' => 'Acme Splunk',
        'destination' => 'splunk_hec',
        'endpoint_url' => 'https://splunk.acme.example/services/collector',
        'auth' => 'bearer',
        'secret' => 'the-callers-own-token',
        'organization_id' => integrationOrg(),
    ])->assertCreated();

    expect($hmac->json('data.secret'))->toMatch('/^[0-9a-f]{64}$/')
        ->and($replayed->json('data.secret'))->toBeNull()
        ->and($bearer->json('data'))->not->toHaveKey('secret')
        ->and(AuditStream::query()->whereKey($bearer->json('data.id'))->value('organization_id'))->toBe($bearer->json('data.organization_id'))
        ->and(integrationTrail('log_stream.created'))->toHaveCount(2);

    $this->withToken($key)->getJson('/api/v1/log-streams')->assertOk()->assertJsonCount(2, 'data')->assertJsonMissingPath('data.0.secret');
});

it('disables, resumes and deletes a stream, each on the trail', function (): void {
    $key = integrationKey();
    $id = $this->withToken($key)->postJson('/api/v1/log-streams', ['name' => 'S', 'destination' => 'generic_json', 'endpoint_url' => 'https://siem.acme.example/c', 'auth' => 'none', 'environment_wide' => true])->json('data.id');

    $this->withToken($key)->patchJson("/api/v1/log-streams/{$id}", ['enabled' => false])->assertOk()->assertJsonPath('data.enabled', false);
    $this->withToken($key)->patchJson("/api/v1/log-streams/{$id}", ['enabled' => true])->assertOk()->assertJsonPath('data.enabled', true);
    $this->withToken($key)->getJson("/api/v1/log-streams/{$id}")->assertOk();
    $this->withToken($key)->deleteJson("/api/v1/log-streams/{$id}")->assertNoContent();

    [$disabled] = integrationTrail('log_stream.disabled');

    expect($disabled->actor_type)->toBe(ActorType::Service)
        ->and(integrationTrail('log_stream.enabled'))->toHaveCount(1)
        ->and(integrationTrail('log_stream.deleted'))->toHaveCount(1)
        ->and(AuditStream::query()->whereKey($id)->exists())->toBeFalse();
});

it('refuses a stream to a private address, and needs log_streams:write', function (): void {
    app(Resolver::class)->set('internal.acme.example', ['10.0.0.5']);

    $this->withToken(integrationKey())->postJson('/api/v1/log-streams', ['name' => 'S', 'destination' => 'generic_json', 'endpoint_url' => 'https://internal.acme.example/c', 'environment_wide' => true])
        ->assertUnprocessable()->assertJsonPath('error', 'unsafe_url');
    $this->withToken(integrationKey(['log_streams:read']))->postJson('/api/v1/log-streams', ['name' => 'S', 'destination' => 'generic_json', 'endpoint_url' => 'https://siem.acme.example/c', 'environment_wide' => true])
        ->assertForbidden();

    expect(AuditStream::query()->count())->toBe(0);
});

it('never reaches another environment\'s stream', function (): void {
    $key = integrationKey();
    $foreign = inIntegrationsOtherEnvironment(fn (): string => app(LogStreams::class)
        ->create('Other', Destination::GenericJson, 'https://other.acme.example/c', 'k', AuthScheme::Bearer)->stream->id);

    $this->withToken($key)->getJson('/api/v1/log-streams')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($key)->getJson("/api/v1/log-streams/{$foreign}")->assertNotFound();
    $this->withToken($key)->patchJson("/api/v1/log-streams/{$foreign}", ['enabled' => false])->assertNotFound();
    $this->withToken($key)->deleteJson("/api/v1/log-streams/{$foreign}")->assertNotFound();

    expect(inIntegrationsOtherEnvironment(fn (): mixed => AuditStream::query()->whereKey($foreign)->value('enabled')))->toBeTrue();
})->group('security');

// ── Events and the audit log ─────────────────────────────────────────────────

it('reads this environment\'s events and no other\'s, by type and by cursor', function (): void {
    $bus = app(EventBus::class);
    $first = $bus->emit(new DomainEvent('user.created', ['user_id' => 'u1']));
    $second = $bus->emit(new DomainEvent('user.deleted', ['user_id' => 'u1']));
    $third = $bus->emit(new DomainEvent('user.created', ['user_id' => 'u2']));
    inIntegrationsOtherEnvironment(fn () => $bus->emit(new DomainEvent('user.created', ['user_id' => 'foreign'])));

    $key = integrationKey(['events:read']);

    $all = $this->withToken($key)->getJson('/api/v1/events')->assertOk();

    expect(array_column($all->json('data'), 'id'))->toBe([$first->id, $second->id, $third->id])
        ->and(json_encode($all->json('data')))->not->toContain('foreign');

    $this->withToken($key)->getJson('/api/v1/events?types[]=user.created')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.1.payload.user_id', 'u2');

    $page = $this->withToken($key)->getJson('/api/v1/events?limit=1')->assertOk()
        ->assertJsonPath('meta.has_more', true)
        ->assertJsonPath('meta.next_cursor', $first->id);

    $this->withToken($key)->getJson('/api/v1/events?limit=5&after='.$page->json('meta.next_cursor'))->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.has_more', false);

    $this->withToken(integrationKey(['audit:read']))->getJson('/api/v1/events')->assertForbidden();
})->group('security');

it('reads this environment\'s audit trail and no other\'s, by action and actor type', function (): void {
    $log = app(AuditLog::class);
    $log->record(new AuditEvent(action: 'integration.test_a', actorType: ActorType::User, actorId: 'u1'));
    $log->record(new AuditEvent(action: 'integration.test_b', actorType: ActorType::System));
    inIntegrationsOtherEnvironment(fn () => $log->record(new AuditEvent(action: 'integration.test_foreign', actorType: ActorType::User, actorId: 'u9')));

    $key = integrationKey(['audit:read']);

    $all = $this->withToken($key)->getJson('/api/v1/audit-log?limit=100')->assertOk();
    $actions = array_column($all->json('data'), 'action');

    expect($actions)->toContain('integration.test_a', 'integration.test_b')
        ->and($actions)->not->toContain('integration.test_foreign');

    $this->withToken($key)->getJson('/api/v1/audit-log?action=integration.test_a')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.actor_id', 'u1');
    $this->withToken($key)->getJson('/api/v1/audit-log?actor_type=system&action=integration.test_b')->assertOk()
        ->assertJsonCount(1, 'data');
    $this->withToken($key)->getJson('/api/v1/audit-log?action=integration.test_foreign')->assertOk()
        ->assertJsonCount(0, 'data');
    $this->withToken($key)->getJson('/api/v1/audit-log?actor_type=robot')->assertUnprocessable();

    $this->withToken(integrationKey(['events:read']))->getJson('/api/v1/audit-log')->assertForbidden();
})->group('security');

// ── The console runs the same actions, and leaves the same trail ─────────────

it('records an organization administrator\'s console changes as theirs, and keeps the environment\'s own out of reach', function (): void {
    config(['cbox-id.webhooks.verify_url' => false, 'cbox-id.external_actions.verify_url' => false]);
    [$subjectId, $orgId] = integrationOrgAdmin();

    $own = app(WebhookRegistry::class)->register($orgId, 'https://own.acme.example/in', [integrationEvent()])->endpoint;
    $environments = app(WebhookRegistry::class)->registerForEnvironment('https://env.acme.example/in', [integrationEvent()])->endpoint;
    $other = app(WebhookRegistry::class)->register(integrationOrg('acme-other'), 'https://other.acme.example/in', [integrationEvent()])->endpoint;
    $hook = app(ExternalActions::class)->register(HookPoint::PostLogin, 'https://own.acme.example/login', $orgId)->endpoint;

    $from = route('webhooks.show', $own->id);

    $this->from($from)->post(route('webhooks.pause', $own->id))->assertRedirect($from);
    $this->from($from)->post(route('webhooks.resume', $own->id))->assertRedirect($from);
    $this->from($from)->patch(route('webhooks.update', $own->id), ['url' => 'https://own2.acme.example/in', 'eventTypes' => [integrationEvent()]])
        ->assertRedirect($from)->assertSessionHasNoErrors();
    $this->from(route('hooks.show', $hook->id))->post(route('hooks.toggle', $hook->id))->assertRedirect(route('hooks.show', $hook->id));

    foreach (['webhook.paused', 'webhook.resumed', 'webhook.updated', 'inline_hook.paused'] as $action) {
        $entries = integrationTrail($action);

        expect($entries)->toHaveCount(1, "{$action} was not recorded")
            ->and($entries[0]->actor_id)->toBe($subjectId)
            ->and($entries[0]->actor_type)->not->toBe(ActorType::Service)
            ->and($entries[0]->organization_id)->toBe($orgId);
    }

    // The environment's own endpoint: visible, never changeable. Another tenant's: not even visible.
    $this->post(route('webhooks.pause', $environments->id))->assertForbidden();
    $this->post(route('webhooks.pause', $other->id))->assertNotFound();

    expect(WebhookEndpoint::query()->whereKey($environments->id)->value('status'))->toBe(EndpointStatus::Active)
        ->and(WebhookEndpoint::query()->whereKey($other->id)->value('status'))->toBe(EndpointStatus::Active)
        ->and(integrationTrail('webhook.paused'))->toHaveCount(1);
})->group('security');

it('records an environment administrator\'s console changes the same way the API does', function (): void {
    craftedEnvAdmin();
    config(['cbox-id.webhooks.verify_url' => false]);

    $endpoint = app(WebhookRegistry::class)->registerForEnvironment('https://env.acme.example/in', [integrationEvent()])->endpoint;
    $stream = app(LogStreams::class)->create('S', Destination::GenericJson, 'https://siem.acme.example/c', 'k', AuthScheme::Bearer)->stream;

    $this->from(route('environment.webhooks.show', $endpoint->id))->post(route('environment.webhooks.pause', $endpoint->id))
        ->assertRedirect(route('environment.webhooks.show', $endpoint->id));
    $this->from(route('environment.audit-streams.show', $stream->id))->post(route('environment.audit-streams.toggle', $stream->id))
        ->assertRedirect(route('environment.audit-streams.show', $stream->id));
    $this->delete(route('environment.webhooks.destroy', $endpoint->id))->assertRedirect(route('environment.webhooks'));

    foreach (['webhook.paused', 'log_stream.disabled', 'webhook.deleted'] as $action) {
        $entries = integrationTrail($action);

        expect($entries)->toHaveCount(1, "{$action} was not recorded")
            ->and($entries[0]->actor_type)->toBe(ActorType::OrganizationMember)
            ->and($entries[0]->organization_id)->toBeNull();
    }
});
