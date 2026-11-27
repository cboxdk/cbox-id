<?php

declare(strict_types=1);

use App\Actions\Apps\AppFields;
use App\Actions\Apps\RotateAppSecret;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionRunner;
use App\Platform\Actions\Principal\ConsoleSessionPrincipal;
use App\Platform\Actions\Principal\DelegatedTokenPrincipal;
use App\Platform\Api\ApiHost;
use App\Platform\Console\ConsoleScope;
use App\Platform\OAuth\ValueObjects\OrganizationChoice;
use App\Providers\ServiceConnectionsServiceProvider;
use App\ServiceConnections\ConnectionEvents;
use App\ServiceConnections\Contracts\ReadsConnectionEvents;
use App\ServiceConnections\ServiceConnection;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\ProtectedResources;
use Cbox\Id\OAuthServer\Contracts\TokenIntrospector;
use Cbox\Id\OAuthServer\Contracts\TokenIssuer;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Exceptions\InvalidAudience;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Cbox\Id\OAuthServer\ValueObjects\IssuedToken;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Database\UniqueConstraintViolationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->organization = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-services'));
    app(Memberships::class)->add($this->organization->id, 'user_admin', MembershipRole::Admin);
    app(Memberships::class)->add($this->organization->id, 'user_member', MembershipRole::Member);
    $this->consumer = app(ClientRegistry::class)->register(new NewClient(name: 'Engage', type: ClientType::Confidential, grantTypes: ['client_credentials', 'authorization_code'], scopes: ['service_connections.manage', 'service_connections.lease']))->client->client_id;
    config(['service-connections.consumers' => [$this->consumer], 'service-connections.services.cbox_billing.client_id' => 'billing-provider']);
    $this->realIntrospector = app(TokenIntrospector::class);
    $tokens = [
        'provider' => Introspection::active('billing-provider', 'billing-provider', ['service_connections.inspect'], []),
        'unrelated-machine' => Introspection::active('unrelated', 'unrelated', ['service_connections.inspect', 'service_connections.lease'], []),
        'admin' => Introspection::active('user_admin', $this->consumer, ['service_connections.manage'], ['org' => $this->organization->id]),
        'member' => Introspection::active('user_member', $this->consumer, ['service_connections.manage'], ['org' => $this->organization->id]),
        'machine' => Introspection::active($this->consumer, $this->consumer, ['service_connections.lease', 'service_connections.manage'], []),
        'lease-only' => Introspection::active($this->consumer, $this->consumer, ['service_connections.lease'], []),
        'manage-only-machine' => Introspection::active($this->consumer, $this->consumer, ['service_connections.manage'], []),
        'unscoped' => Introspection::active('user_admin', $this->consumer, [], ['org' => $this->organization->id]),
        'wrong-audience' => Introspection::active('user_admin', $this->consumer, ['service_connections.manage'], ['org' => $this->organization->id, 'aud' => 'https://other.test']),
        'other-org' => Introspection::active('user_admin', $this->consumer, ['service_connections.manage'], ['org' => 'org_other']),
    ];
    app()->instance(TokenIntrospector::class, new readonly class($tokens) implements TokenIntrospector
    {
        /** @param array<string, Introspection> $tokens */
        public function __construct(private array $tokens) {}

        public function introspect(string $token): Introspection
        {
            return $this->tokens[$token] ?? Introspection::inactive();
        }

        public function revoke(string $jti): void {}
    });
});

it('creates one organization grant, with no customer credentials, and reuses repeated requests', function (): void {
    foreach ([1, 2] as $attempt) {
        $this->withToken('admin')->postJson('/api/v1/service-connections/cbox_billing')->assertOk()->assertJsonPath('organization_id', $this->organization->id)->assertJsonMissingPath('client_secret');
    }
    expect(ServiceConnection::query()->count())->toBe(1);
    $grant = ServiceConnection::query()->sole();
    expect(Client::query()->where('client_id', $grant->service_client_id)->sole()->organization_id)->toBe($this->organization->id);
});

it('requires a human administrator, the right scope and the right audience', function (): void {
    foreach (['member', 'machine', 'unscoped', 'other-org'] as $token) {
        $this->withToken($token)->postJson('/api/v1/service-connections/cbox_billing')->assertForbidden();
    }
    $this->withToken('wrong-audience')->postJson('/api/v1/service-connections/cbox_billing')->assertUnauthorized();
    expect(ServiceConnection::query()->count())->toBe(0);
});

it('leases to the consumer after logout and refuses a centrally revoked grant', function (): void {
    $response = $this->withToken('admin')->postJson('/api/v1/service-connections/cbox_billing')->assertOk();
    $id = $response->json('id');
    $issuer = Mockery::mock(TokenIssuer::class);
    $issuer->shouldReceive('issueClientCredentials')->once()->withArgs(fn (Client $client, array $scopes, string $audience): bool => $client->organization_id === $this->organization->id && $scopes === ['billing:engage:sync'] && $audience === 'https://app.cboxbilling.com/api/v1/service-connections')->andReturn(new IssuedToken('test-access-token', 'jti-test', 300, scopes: ['billing:engage:sync'], audience: 'https://app.cboxbilling.com/api/v1/service-connections'));
    app()->instance(TokenIssuer::class, $issuer);
    $this->withToken('machine')->postJson('/api/v1/service-connections/'.$id.'/token')->assertOk()->assertJsonPath('access_token', 'test-access-token')->assertHeader('Cache-Control', 'no-store, private');
    $this->withToken('admin')->deleteJson('/api/v1/service-connections/'.$id)->assertOk();
    $this->withToken('machine')->postJson('/api/v1/service-connections/'.$id.'/token')->assertNotFound();
});

it('stops leasing if platform trust is removed', function (): void {
    $id = $this->withToken('admin')->postJson('/api/v1/service-connections/cbox_telemetry')->assertOk()->json('id');
    config(['service-connections.consumers' => []]);
    $this->withToken('machine')->postJson('/api/v1/service-connections/'.$id.'/token')->assertForbidden();
});

it('issues real organization-bound tokens and invalidates them on central revocation', function (): void {
    $id = $this->withToken('admin')->postJson('/api/v1/service-connections/cbox_billing')->assertOk()->json('id');
    $response = $this->withToken('machine')->postJson('/api/v1/service-connections/'.$id.'/token')->assertOk();
    $token = $this->realIntrospector->introspect($response->json('access_token'));
    expect($token->active)->toBeTrue();
    expect($token->claims['org'])->toBe($this->organization->id);
    expect($token->claims['aud'])->toBe('https://app.cboxbilling.com/api/v1/service-connections');
    expect($token->scopes)->toBe(['billing:engage:sync']);
    $this->withToken('admin')->deleteJson('/api/v1/service-connections/'.$id)->assertOk();
    expect($this->realIntrospector->introspect($response->json('access_token'))->active)->toBeFalse();
});

it('isolates Assets leases by organization and consumer and rotates a revoked connection without reviving old tokens', function (): void {
    // Production declares this same audience for MCP. A lease must not need a second
    // API registration, which would shadow that declaration and break MCP clients.
    config(['cbox-id.oauth.protected_resources' => [[
        'identifier' => 'https://api.cboxassets.com',
        'scopes' => ['mcp:read', 'mcp:write'],
        'dynamic_clients' => true,
    ]]]);
    (new ServiceConnectionsServiceProvider(app()))->boot();

    // Exercise the public API with real signed caller tokens, not the fixture introspector.
    app()->instance(TokenIntrospector::class, $this->realIntrospector);
    $issuer = app(TokenIssuer::class);
    $person = app(Subjects::class)->create('assets-admin@example.test', 'Assets admin');
    app(Memberships::class)->add($this->organization->id, $person->id, MembershipRole::Admin);
    $consumer = Client::query()->where('client_id', $this->consumer)->sole();
    $provider = app(ClientRegistry::class)->register(new NewClient(name: 'Assets', scopes: ['service_connections.inspect']))->client;
    $unrelated = app(ClientRegistry::class)->register(new NewClient(name: 'Unrelated', scopes: ['service_connections.lease', 'service_connections.inspect']))->client;
    config(['service-connections.services.cbox_assets.client_id' => $provider->client_id]);
    $base = 'http://'.ApiHost::name().'/v1/service-connections/';
    $admin = $issuer->issueForUser($consumer, $person->id, $this->organization->id, ['service_connections.manage'])->token;
    $machine = $issuer->issueClientCredentials($consumer, ['service_connections.lease'])->token;
    $providerToken = $issuer->issueClientCredentials($provider, ['service_connections.inspect'])->token;
    $otherMachine = $issuer->issueClientCredentials($unrelated)->token;

    $id = $this->withToken($admin)->putJson($base.'cbox_assets')->assertOk()->assertJsonMissingPath('client_secret')->json('id');
    $this->withToken($admin)->putJson($base.'cbox_assets')->assertOk()->assertJsonPath('id', $id);
    $otherOrg = app(Organizations::class)->create(new NewOrganization('Other', 'other-assets'));
    app(Memberships::class)->add($otherOrg->id, $person->id, MembershipRole::Admin);
    $otherAdmin = $issuer->issueForUser($consumer, $person->id, $otherOrg->id, ['service_connections.manage'])->token;
    $otherId = $this->withToken($otherAdmin)->putJson($base.'cbox_assets')->assertOk()->json('id');
    expect($otherId)->not->toBe($id);

    $lease = $this->withToken($machine)->postJson($base.$id.'/lease')->assertOk()->json('access_token');
    $otherLease = $this->withToken($machine)->postJson($base.$otherId.'/lease')->assertOk()->json('access_token');
    $token = $this->realIntrospector->introspect($lease);
    $otherToken = $this->realIntrospector->introspect($otherLease);
    expect($token->active)->toBeTrue()
        ->and($token->claims['org'])->toBe($this->organization->id)
        ->and($token->claims['aud'])->toBe('https://api.cboxassets.com')
        ->and($token->scopes)->toBe(['assets:consumer:read', 'assets:consumer:write'])
        ->and($token->subject)->toBe($token->clientId)
        ->and($token->clientId)->not->toBe($this->consumer)
        ->and($otherToken->claims['org'])->toBe($otherOrg->id)
        ->and($otherToken->clientId)->not->toBe($token->clientId);
    $this->withToken($providerToken)->postJson($base.$id.'/inspect')->assertOk()
        ->assertJsonPath('active', true)->assertJsonPath('organization_id', 'org_'.$this->organization->id)
        ->assertJsonPath('service_client_id', $token->clientId)->assertJsonPath('consumer_client_id', $this->consumer);
    $this->withToken($otherMachine)->postJson($base.$id.'/lease')->assertForbidden();
    $this->withToken($otherMachine)->postJson($base.$id.'/inspect')->assertForbidden();
    $this->withToken($otherAdmin)->deleteJson($base.$id)->assertNotFound();
    // An Assets lease is never an ID management credential, regardless of the path.
    $this->withToken($lease)->postJson($base.$id.'/inspect')->assertUnauthorized();

    $this->withToken($admin)->deleteJson($base.$id)->assertOk();
    $this->withToken($providerToken)->postJson($base.$id.'/inspect')->assertOk()->assertJsonPath('active', false);
    $this->withToken($machine)->postJson($base.$id.'/lease')->assertNotFound();
    expect($this->realIntrospector->introspect($lease)->active)->toBeFalse()
        ->and($this->realIntrospector->introspect($otherLease)->active)->toBeTrue();

    $this->withToken($admin)->putJson($base.'cbox_assets')->assertOk()->assertJsonPath('id', $id);
    $renewed = $this->withToken($machine)->postJson($base.$id.'/lease')->assertOk()->json('access_token');
    expect($this->realIntrospector->introspect($renewed)->active)->toBeTrue()
        ->and($this->realIntrospector->introspect($renewed)->clientId)->not->toBe($token->clientId)
        ->and($this->realIntrospector->introspect($lease)->active)->toBeFalse()
        ->and(ServiceConnection::query()->count())->toBe(2);
});

it('keeps Assets consumer scopes out of dynamic registrations and tokens while preserving MCP and existing reservations', function (): void {
    config([
        'cbox-id.oauth.dynamic_registration.mode' => 'mcp',
        'cbox-id.oauth.reserved_scopes' => ['vault.lease', 'host:reserved'],
        'cbox-id.oauth.protected_resources' => [
            ['identifier' => 'https://api.cboxassets.com', 'scopes' => 'mcp:read mcp:write vault.lease host:reserved', 'dynamic_clients' => true],
            ['identifier' => 'https://other.test/mcp', 'scopes' => ['other:read'], 'dynamic_clients' => true],
        ],
    ]);
    (new ServiceConnectionsServiceProvider(app()))->boot();
    $consumerScopes = ['assets:consumer:read', 'assets:consumer:write'];
    $registered = $this->postJson('/oauth/register', [
        'client_name' => 'Assets MCP client',
        'redirect_uris' => ['http://127.0.0.1:33418/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'token_endpoint_auth_method' => 'none',
        'scope' => 'mcp:read mcp:write other:read assets:consumer:read assets:consumer:write vault.lease host:reserved',
    ])->assertCreated()->assertJsonPath('scope', 'mcp:read mcp:write other:read');
    $client = Client::query()->where('client_id', $registered->json('client_id'))->sole();
    $person = app(Subjects::class)->create('assets-mcp@example.test', 'MCP user');
    $issuer = app(TokenIssuer::class);

    // Even a stale client carrying reserved scopes cannot put them in a signed token.
    $client->scopes = [...$client->scopes, ...$consumerScopes, 'vault.lease', 'host:reserved'];
    $issued = $issuer->issueForUser($client, $person->id, null, $client->scopes, 'https://api.cboxassets.com');
    $token = $this->realIntrospector->introspect($issued->token);
    expect($token->active)->toBeTrue()
        ->and($token->claims['aud'])->toBe('https://api.cboxassets.com')
        ->and($token->scopes)->toBe(['mcp:read', 'mcp:write']);
    expect(fn () => $issuer->issueForUser($client, $person->id, null, $consumerScopes, 'https://api.cboxassets.com'))
        ->toThrow(InvalidAudience::class);
    expect(app(ProtectedResources::class)->find('https://other.test/mcp')?->scopes)->toBe(['other:read']);
})->group('security');

it('limits live grant inspection to its provider and consumer and reports central revocation', function (): void {
    $id = $this->withToken('admin')->postJson('/api/v1/service-connections/cbox_billing')->assertOk()->json('id');
    $this->withToken('provider')->postJson('/api/v1/service-connections/'.$id.'/inspect')->assertOk()->assertJsonPath('active', true)->assertJsonPath('organization_id', $this->organization->id);
    $this->withToken('machine')->postJson('/api/v1/service-connections/'.$id.'/status')->assertOk()->assertJsonPath('active', true);
    $this->withToken('machine')->postJson('/api/v1/service-connections/'.$id.'/inspect')->assertForbidden();
    $this->withToken('unrelated-machine')->postJson('/api/v1/service-connections/'.$id.'/inspect')->assertForbidden();
    $this->withToken('unrelated-machine')->postJson('/api/v1/service-connections/'.$id.'/status')->assertForbidden();
    $this->withToken('admin')->deleteJson('/api/v1/service-connections/'.$id)->assertOk();
    $this->withToken('provider')->postJson('/api/v1/service-connections/'.$id.'/inspect')->assertOk()->assertJsonPath('active', false);
    $this->withToken('machine')->postJson('/api/v1/service-connections/'.$id.'/status')->assertOk()->assertJsonPath('active', false);
});

it('never returns a leased token with a different audience or scopes than the approved connection', function (): void {
    $id = $this->withToken('admin')->postJson('/api/v1/service-connections/cbox_billing')->assertOk()->json('id');
    $issuer = Mockery::mock(TokenIssuer::class);
    $issuer->shouldReceive('issueClientCredentials')->once()->andReturn(new IssuedToken('must-not-leak', 'bad-jti', 300, scopes: ['billing:engage:sync']));
    app()->instance(TokenIssuer::class, $issuer);
    $this->withToken('machine')->postJson('/api/v1/service-connections/'.$id.'/token')->assertStatus(503)->assertDontSee('must-not-leak');
});

it('preserves long client and service identifiers while enforcing the tenant owner key', function (): void {
    $connection = $this->withToken('admin')->postJson('/api/v1/service-connections/cbox_billing')->assertOk()->json('id');
    $row = ServiceConnection::query()->findOrFail($connection);
    $row->update(['consumer_client_id' => str_repeat('c', 255), 'service' => str_repeat('s', 255)]);
    expect($row->fresh()->consumer_client_id)->toHaveLength(255)
        ->and($row->fresh()->service)->toHaveLength(255);

    expect(fn () => $row->replicate()->save())
        ->toThrow(UniqueConstraintViolationException::class);
});

it('reads connection events through a bound reader, so the events API never fails to resolve it', function (): void {
    expect(app(ReadsConnectionEvents::class))->toBeInstanceOf(ConnectionEvents::class);
});

it('inspects a grant on the API host with either an inspect-only or a lease-only token, and with neither refuses', function (): void {
    $id = $this->withToken('admin')->postJson('/api/v1/service-connections/cbox_billing')->assertOk()->json('id');
    $inspect = 'http://'.ApiHost::name().'/v1/service-connections/'.$id.'/inspect';

    // `/status` (lease) folded into `/inspect` on /v1: the consumer's lease-only machine
    // token, which laravel-id-client uses today, keeps its answer there.
    $this->withToken('provider')->postJson($inspect)->assertOk()->assertJsonPath('active', true)->assertJsonPath('organization_id', 'org_'.$this->organization->id);
    $this->withToken('lease-only')->postJson($inspect)->assertOk()->assertJsonPath('active', true);

    $this->withToken('manage-only-machine')->postJson($inspect)
        ->assertForbidden()
        ->assertHeader('Content-Type', 'application/problem+json');
});

/**
 * Audit finding #54. The backing client of a service connection is filed under the
 * organization, so the organization console treated it as one of the organization's own
 * apps: an owner could rotate its secret, take the plaintext, and mint provider-audience
 * tokens at /oauth/token that skipped every check lease() makes — consumer, actor, active
 * organization, catalog audience, audit. Nobody gets that client's credentials.
 */
it('never hands anybody the backing client of a service connection to rotate or change', function (): void {
    [$ownerId, $org] = actingAsRole(MembershipRole::Owner);
    confirmConsoleStepUp();

    $backing = app(ClientRegistry::class)->register(new NewClient(
        name: 'Service connection: cbox_billing', type: ClientType::Confidential, grantTypes: ['client_credentials'], organizationId: $org->id,
    ))->client;
    ServiceConnection::query()->create([
        'organization_id' => $org->id, 'consumer_client_id' => $this->consumer, 'service' => 'cbox_billing',
        'service_client_id' => $backing->client_id, 'audience' => 'https://billing.test', 'scopes' => ['billing:engage:sync'],
        'created_by' => 'user_admin',
    ]);
    $ordinaryFirst = app(ClientRegistry::class)->register(new NewClient(name: 'Reports', type: ClientType::Confidential, grantTypes: ['client_credentials'], organizationId: $org->id))->client;
    $secrets = fn (): int => count(app(ClientRegistry::class)->secrets($backing->refresh()));
    $before = $secrets();

    // The organization console: no rotation, no edit.
    $this->post(route('clients.rotate', $backing->id))->assertForbidden();
    expect($secrets())->toBe($before);

    // Any other door an organization-confined principal has: the app does not exist for it.
    $principal = new ConsoleSessionPrincipal(app(ConsoleScope::class));
    expect(fn () => app(ActionRunner::class)->run(RotateAppSecret::class, $principal, ['id' => $backing->client_id, 'grace_seconds' => 0]))
        ->toThrow(HttpException::class);
    expect($secrets())->toBe($before);

    // A token the owner signed in for, confined to the organization: a 404, as for an app
    // that is not theirs.
    $token = new DelegatedTokenPrincipal(
        subjectId: (string) $ownerId,
        personName: 'Owner',
        clientId: 'owner-cli',
        clientName: 'Owner CLI',
        environmentId: 'env_test',
        scopes: ['apps:write', 'apps:read'],
        organization: new OrganizationChoice($org->id, $org->name, MembershipRole::Owner),
        customerConsole: false,
    );
    $context = new ActionContext($token, ['id' => $backing->client_id]);
    expect(fn () => AppFields::find($context, $backing->client_id))
        ->toThrow(ActionRefused::class);
    expect(AppFields::find($context, $ordinaryFirst->client_id)->client_id)->toBe($ordinaryFirst->client_id);
    expect($secrets())->toBe($before);

    // An ordinary app of the same organization still rotates.
    $ordinary = app(ClientRegistry::class)->register(new NewClient(name: 'Billing', type: ClientType::Confidential, grantTypes: ['client_credentials'], organizationId: $org->id))->client;
    $this->from(route('clients.show', $ordinary->id))->post(route('clients.rotate', $ordinary->id))->assertSessionHasNoErrors();
})->group('security');
