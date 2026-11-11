<?php

declare(strict_types=1);

use App\Platform\Api\ApiHost;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Events\Contracts\EventBus;
use Cbox\Id\Kernel\Events\ValueObjects\DomainEvent;
use Cbox\Id\OAuthServer\Contracts\TokenIntrospector;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Webhooks\Contracts\WebhookDispatcher;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;
use Cbox\Id\Webhooks\Models\WebhookDelivery;
use Cbox\Id\Webhooks\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
| Plan step 7: `/v1` speaks public ids (`usr_01j…`) in and out; `/api/v1` keeps the bare ids
| its live callers hold and reads public ones too; a prefix of the wrong kind is a 404; and
| webhook endpoints receive public ids only when they ask for them.
*/

beforeEach(function (): void {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    Environment::query()->firstOrCreate(['id' => 'env_test'], ['name' => 'Test root', 'slug' => 'test-root', 'type' => EnvironmentType::Production, 'status' => EnvironmentStatus::Active, 'is_default' => true, 'settings' => []]);
});

function publicIdsApi(string $path): string
{
    return 'http://'.ApiHost::name().'/'.ltrim($path, '/');
}

/** @param  list<string>  $scopes */
function publicIdsUserInfo(string $subject, array $scopes, string $org): void
{
    $token = Introspection::active($subject, 'client-a', $scopes, ['sub' => $subject, 'org' => $org]);
    app()->instance(TokenIntrospector::class, new class($token) implements TokenIntrospector
    {
        public function __construct(private readonly Introspection $token) {}

        public function introspect(string $token): Introspection
        {
            return $token === 'valid' ? $this->token : Introspection::inactive();
        }

        public function revoke(string $jti): void {}
    });
}

/** @param  list<string>  $scopes */
function publicIdsKey(array $scopes): string
{
    return app(EnvironmentApiKeys::class)->issue('env_test', 'public ids', $scopes)->plaintext;
}

it('answers /v1 with public ids and reads a record by either form', function (): void {
    $key = publicIdsKey(['users:read', 'users:write']);
    $ada = app(Subjects::class)->create('ada@public-ids.example', 'Ada');

    $public = $this->withToken($key)->getJson(publicIdsApi("/v1/users/{$ada->id}"))
        ->assertOk()
        ->assertJsonPath('object', 'user')
        ->assertJsonPath('id', 'usr_'.$ada->id)
        ->json('id');

    $this->withToken($key)->getJson(publicIdsApi("/v1/users/{$public}"))
        ->assertOk()
        ->assertJsonPath('email', 'ada@public-ids.example');

    $this->withToken($key)->getJson(publicIdsApi('/v1/users'))
        ->assertOk()
        ->assertJsonPath('data.0.id', 'usr_'.$ada->id);

    $created = $this->withToken($key)->postJson(publicIdsApi('/v1/users'), ['email' => 'grace@public-ids.example', 'name' => 'Grace'])
        ->assertCreated()
        ->json('id');

    expect($created)->toStartWith('usr_');
});

it('answers a public id of another kind with 404 on /v1, never the record inside it', function (): void {
    $key = publicIdsKey(['users:read']);
    $ada = app(Subjects::class)->create('ada@public-ids.example', 'Ada');

    $this->withToken($key)->getJson(publicIdsApi("/v1/users/org_{$ada->id}"))
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json');
})->group('security');

it('prefixes the ids a /v1 record points at, and reads them back from the query and the body', function (): void {
    $key = publicIdsKey(['members:read', 'members:write', 'organizations:read']);
    $ada = app(Subjects::class)->create('ada@public-ids.example', 'Ada');
    $grace = app(Subjects::class)->create('grace@public-ids.example', 'Grace');
    $acme = app(Organizations::class)->create(new NewOrganization('Acme', 'acme'));
    app(Memberships::class)->add($acme->id, $ada->id, MembershipRole::Member);

    $this->withToken($key)->getJson(publicIdsApi("/v1/organization-memberships?organization_id=org_{$acme->id}"))
        ->assertOk()
        ->assertJsonPath('data.0.user_id', 'usr_'.$ada->id)
        ->assertJsonPath('data.0.organization_id', 'org_'.$acme->id)
        ->assertJsonPath('data.0.id', fn (string $id): bool => str_starts_with($id, 'mbr_'));

    $this->withToken($key)->postJson(publicIdsApi('/v1/organization-memberships'), [
        'organization_id' => 'org_'.$acme->id,
        'user_id' => 'usr_'.$grace->id,
        'role' => 'member',
    ])->assertSuccessful()->assertJsonPath('user_id', 'usr_'.$grace->id);

    expect(app(Memberships::class)->of($acme->id, $grace->id))->not->toBeNull();

    // A user's id where the organization belongs names no organization.
    $this->withToken($key)->getJson(publicIdsApi("/v1/organization-memberships?organization_id=usr_{$acme->id}"))
        ->assertNotFound();
});

it('keeps bare ids on /api/v1 for its live callers, and reads public ids there too', function (): void {
    $key = publicIdsKey(['users:read']);
    $ada = app(Subjects::class)->create('ada@public-ids.example', 'Ada');

    $this->withToken($key)->getJson("/api/v1/users/{$ada->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $ada->id);

    $this->withToken($key)->getJson("/api/v1/users/usr_{$ada->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $ada->id);

    $this->withToken($key)->getJson("/api/v1/users/org_{$ada->id}")->assertNotFound();
})->group('security');

it('leaves token claims as they are: sub and org stay bare', function (): void {
    $ada = app(Subjects::class)->create('ada@public-ids.example', 'Ada');
    $acme = app(Organizations::class)->create(new NewOrganization('Acme', 'acme'));
    app(Memberships::class)->add($acme->id, $ada->id, MembershipRole::Member);
    publicIdsUserInfo($ada->id, ['openid', 'organizations'], $acme->id);

    $this->withToken('valid')->getJson('/oauth/userinfo')
        ->assertOk()
        ->assertJsonPath('sub', $ada->id)
        ->assertJsonPath('org', $acme->id)
        ->assertJsonPath('organizations.0.id', $acme->id);
});

it('sends a webhook endpoint bare ids unless it asked for public ones', function (): void {
    Queue::fake();
    $acme = app(Organizations::class)->create(new NewOrganization('Acme', 'acme'));
    $ada = app(Subjects::class)->create('ada@public-ids.example', 'Ada');

    $raw = app(WebhookRegistry::class)->register($acme->id, 'https://raw.acme.example/in', ['user.created'])->endpoint;
    $public = app(WebhookRegistry::class)->register($acme->id, 'https://public.acme.example/in', ['user.created'])->endpoint;
    $public->forceFill(['id_format' => 'prefixed'])->save();

    app(WebhookDispatcher::class)->dispatch('user.created', ['id' => $ada->id, 'user_id' => $ada->id, 'email' => 'ada@public-ids.example'], $acme->id);

    $payloadOf = fn (WebhookEndpoint $endpoint): array => WebhookDelivery::query()->where('endpoint_id', $endpoint->id)->sole()->payload;

    expect($payloadOf($raw))->toMatchArray(['id' => $ada->id, 'user_id' => $ada->id])
        ->and($payloadOf($public))->toMatchArray(['id' => 'usr_'.$ada->id, 'user_id' => 'usr_'.$ada->id, 'email' => 'ada@public-ids.example']);
});

it('starts an endpoint made on /v1 with public ids, one made on /api/v1 with bare ids, and moves either', function (): void {
    $key = publicIdsKey(['webhooks:write', 'webhooks:read']);
    $body = ['url' => 'https://hooks.acme.example/in', 'event_types' => ['user.created'], 'environment_wide' => true];

    $new = $this->withToken($key)->postJson(publicIdsApi('/v1/webhooks'), $body)
        ->assertCreated()
        ->assertJsonPath('id_format', 'prefixed')
        ->json('id');

    expect($new)->toStartWith('whe_');

    $this->withToken($key)->postJson('/api/v1/webhooks', [...$body, 'url' => 'https://old.acme.example/in'])
        ->assertCreated()
        ->assertJsonPath('data.id_format', 'raw');

    $this->withToken($key)->patchJson(publicIdsApi("/v1/webhooks/{$new}"), ['id_format' => 'raw'])
        ->assertOk()
        ->assertJsonPath('id_format', 'raw');

    expect(WebhookEndpoint::query()->whereKey(substr($new, strlen('whe_')))->value('id_format'))->toBe('raw');
});

it('does not touch the domain event bus: public ids are written at the boundary only', function (): void {
    Queue::fake();
    $acme = app(Organizations::class)->create(new NewOrganization('Acme', 'acme'));

    $event = app(EventBus::class)->emit(new DomainEvent('organization.updated', ['id' => $acme->id], $acme->id));

    expect($event->payload['id'])->toBe($acme->id);
});
