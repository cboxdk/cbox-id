<?php

declare(strict_types=1);

use App\ServiceConnections\ServiceConnection;
use Cbox\Id\Kernel\Events\Contracts\EventBus;
use Cbox\Id\Kernel\Events\ValueObjects\DomainEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
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

it('reads ID events through a real leased organization grant and stops after revocation', function (): void {
    $issuer = app(IssuerResolver::class)->issuer();
    config(['service-connections.services.cbox_id_events.audience' => $issuer]);
    $grant = $this->withToken('admin')->postJson('/api/v1/service-connections/cbox_id_events')->assertOk()->json('id');
    $lease = $this->withToken('machine')->postJson('/api/v1/service-connections/'.$grant.'/token')->assertOk()->json('access_token');
    $bus = app(EventBus::class);
    $first = $bus->emit(new DomainEvent('signals.acceptance', ['example' => 1], $this->organization->id));
    $second = $bus->emit(new DomainEvent('signals.acceptance', ['example' => 2], $this->organization->id));
    $bus->emit(new DomainEvent('signals.acceptance', ['example' => 'foreign'], 'other-org'));
    app()->instance(TokenIntrospector::class, $this->realIntrospector);
    $url = '/api/v1/service-connections/'.$grant.'/events?types[]=signals.acceptance&limit=1';
    $this->withToken($lease)->getJson($url)->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $first->id)->assertJsonPath('meta.has_more', true);
    $this->withToken($lease)->getJson($url.'&after='.$first->id)->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $second->id)->assertJsonPath('meta.has_more', false);
    $this->withToken($lease)->getJson($url.'&organization_id=other-org')->assertUnprocessable();
    ServiceConnection::query()->whereKey($grant)->update(['revoked_at' => now()]);
    $this->withToken($lease)->getJson($url)->assertNotFound();
});

it('stops event reads when consumer trust is removed, even with an unexpired lease', function (): void {
    config(['service-connections.services.cbox_id_events.audience' => app(IssuerResolver::class)->issuer()]);
    $grant = $this->withToken('admin')->postJson('/api/v1/service-connections/cbox_id_events')->assertOk()->json('id');
    $lease = $this->withToken('machine')->postJson('/api/v1/service-connections/'.$grant.'/token')->assertOk()->json('access_token');
    app()->instance(TokenIntrospector::class, $this->realIntrospector);
    config(['service-connections.consumers' => []]);
    $this->withToken($lease)->getJson('/api/v1/service-connections/'.$grant.'/events')->assertForbidden();
});
