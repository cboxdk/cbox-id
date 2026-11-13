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
use App\ServiceConnections\ConnectionEvents;
use App\ServiceConnections\Contracts\ReadsConnectionEvents;
use App\ServiceConnections\ServiceConnection;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\TokenIntrospector;
use Cbox\Id\OAuthServer\Contracts\TokenIssuer;
use Cbox\Id\OAuthServer\Enums\ClientType;
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
