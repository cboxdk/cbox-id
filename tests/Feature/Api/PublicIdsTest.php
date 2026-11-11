<?php

declare(strict_types=1);

use App\Platform\Api\ApiHost;
use App\Platform\Api\CredentialEnvironment;
use App\Platform\Api\V1\V1Routes;
use App\Platform\PublicIds\BareIdInput;
use App\Platform\PublicIds\IdNames;
use App\Platform\PublicIds\PublicId;
use App\Platform\PublicIds\WebhookIdFormat;
use Cbox\Id\Identity\Contracts\SubjectEraser;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Kernel\Events\Contracts\EventBus;
use Cbox\Id\Kernel\Events\ValueObjects\DomainEvent;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\TokenIntrospector;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\UserProperties\Models\PropertyNamespace;
use Cbox\Id\Webhooks\Contracts\WebhookDispatcher;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;
use Cbox\Id\Webhooks\Enums\DeliveryStatus;
use Cbox\Id\Webhooks\Models\WebhookDelivery;
use Cbox\Id\Webhooks\Models\WebhookEndpoint;
use Cbox\Telemetry\Facades\Telemetry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

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

it('delivers user.metadata.updated to an endpoint that receives public ids: the policy reads the bare id', function (): void {
    Queue::fake();
    Http::fake(['hooks.acme.example/*' => Http::response('', 200)]);
    $ada = app(Subjects::class)->create('ada@public-ids.example', 'Ada');
    $client = app(ClientRegistry::class)->register(new NewClient('Properties app', ClientType::Confidential, scopes: ['user_properties:read']))->client->client_id;

    $endpoint = app(WebhookRegistry::class)->registerForEnvironment('https://hooks.acme.example/in', ['user.metadata.updated'])->endpoint;
    $endpoint->forceFill(['id_format' => 'prefixed'])->save();
    PropertyNamespace::query()->create(['environment_id' => $endpoint->environment_id, 'name' => 'team', 'readers' => [$client], 'writers' => [$client], 'webhooks' => [$endpoint->id => $client]]);

    app(WebhookDispatcher::class)->dispatch('user.metadata.updated', ['user_id' => $ada->id, 'namespace' => 'team', 'version' => 1]);

    $delivery = WebhookDelivery::query()->where('endpoint_id', $endpoint->id)->sole();
    expect($delivery->payload['user_id'])->toBe('usr_'.$ada->id);

    app(WebhookDispatcher::class)->deliver($delivery->id);

    expect($delivery->fresh()?->status)->toBe(DeliveryStatus::Delivered);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://hooks.acme.example/in' && $request['data']['user_id'] === 'usr_'.$ada->id);
});

it('erases the name from a delivery to an endpoint that receives public ids, one naming the person by id alone', function (): void {
    Queue::fake();
    $acme = app(Organizations::class)->create(new NewOrganization('Acme', 'acme'));
    $ada = app(Subjects::class)->create('ada.lovelace@public-ids.example', 'Ada Lovelace');

    $public = app(WebhookRegistry::class)->register($acme->id, 'https://public.acme.example/in', ['membership.created'])->endpoint;
    $public->forceFill(['id_format' => 'prefixed'])->save();

    // No email in it: only the id and the name say whose it is.
    app(WebhookDispatcher::class)->dispatch('membership.created', ['user_id' => $ada->id, 'name' => 'Ada Lovelace', 'organization_id' => $acme->id], $acme->id);

    expect(WebhookDelivery::query()->where('endpoint_id', $public->id)->sole()->payload)->toMatchArray(['user_id' => 'usr_'.$ada->id, 'name' => 'Ada Lovelace']);

    app(SubjectEraser::class)->erase($ada->id, AuditActor::system());

    expect(WebhookDelivery::query()->where('endpoint_id', $public->id)->sole()->payload['name'])->not->toBe('Ada Lovelace');
});

it('gives a pipe connection\'s id no SSO connection prefix', function (): void {
    [$connection, $user] = [strtolower((string) Str::ulid()), strtolower((string) Str::ulid())];

    foreach (['pipe.connection.connected', 'pipe.connection.needs_reauth', 'pipe.connection.disconnected'] as $type) {
        $payload = WebhookIdFormat::payload($type, ['connection_id' => $connection, 'user_id' => $user, 'provider' => 'github']);

        expect($payload['connection_id'])->toBe($connection)
            ->and($payload['user_id'])->toBe('usr_'.$user);
    }
});

it('counts a bare id read on /v1, per caller and route, and not on /api/v1', function (): void {
    $telemetry = Telemetry::fake();
    $key = publicIdsKey(['users:read']);
    $ada = app(Subjects::class)->create('ada@public-ids.example', 'Ada');

    $this->withToken($key)->getJson(publicIdsApi("/v1/users/usr_{$ada->id}"))->assertOk();
    $telemetry->assertCounterNotIncremented(BareIdInput::METRIC);

    $this->withToken($key)->getJson("/api/v1/users/{$ada->id}")->assertOk();
    $telemetry->assertCounterNotIncremented(BareIdInput::METRIC);

    $this->withToken($key)->getJson(publicIdsApi("/v1/users/{$ada->id}"))->assertOk();
    $telemetry->assertCounterIncremented(BareIdInput::METRIC, [
        'surface' => 'v1',
        'kind' => 'user',
        'caller_kind' => 'environment_key',
        'caller' => CredentialEnvironment::keyId($key),
        'route' => 'GET /v1/users/{id}',
    ]);
});

/*
| Every `/v1` resource either answers with the prefix registered for its `object`, or is named
| here as a resource whose id stays as it is: a singleton (`profile`, `appearance`), a value
| that is not a record id (`scope`, `email`, `origin`), or a kind with no prefix in the
| registry yet (§3.3). A new resource, or a renamed one, lands on neither list and fails until
| someone decides which it is.
*/
const PUBLIC_IDS_BARE_OBJECTS = [
    'app_catalog', 'app_manifest', 'app_role', 'app_secret', 'appearance', 'application',
    'audit_log_export', 'audit_log_schema', 'audit_log_setting', 'authenticator', 'billing_account',
    'certificate', 'client_credential', 'connection', 'credential', 'email', 'environment_domain',
    'environment_role_assignment', 'fga', 'grant', 'group_role', 'legacy_login', 'mfa', 'onboarding',
    'origin', 'payment_method', 'pipe_connection', 'pipe_grant', 'pipe_lease', 'portal_link', 'profile',
    'radar_decision', 'radar_list', 'radar_setting', 'role_assignment', 'role_permission',
    'saml_metadata_import', 'scope', 'secret', 'service', 'service_connection', 'sign_in',
    'sign_in_method', 'signals_source', 'social', 'social_connection', 'sync_setting',
    'user_property_namespace', 'vault_secret_grant', 'whitelabel',
];

it('maps every /v1 resource to a registered prefix, or names it as one that stays bare', function (): void {
    $objects = [];

    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        $object = $route->defaults[V1Routes::OBJECT] ?? null;

        if (is_string($object)) {
            $objects[$object][] = implode('|', $route->methods()).' /'.$route->uri();
        }
    }

    expect($objects)->not->toBeEmpty();

    $unmapped = [];

    foreach ($objects as $object => $routes) {
        $kind = IdNames::object($object);

        if ($kind !== null) {
            expect(array_key_exists($kind, PublicId::PREFIXES))->toBeTrue("`{$object}` maps to `{$kind}`, which has no prefix");
            expect(in_array($object, PUBLIC_IDS_BARE_OBJECTS, true))->toBeFalse("`{$object}` is mapped and on the bare list");

            continue;
        }

        if (! in_array($object, PUBLIC_IDS_BARE_OBJECTS, true)) {
            $unmapped[$object] = $routes[0];
        }
    }

    expect($unmapped)->toBe([])
        ->and(array_values(array_diff(PUBLIC_IDS_BARE_OBJECTS, array_keys($objects))))->toBe([]);
});
