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
use App\Platform\Api\ManagementAudience;
use App\Platform\Console\ConsoleScope;
use App\Platform\OAuth\PlatformRealmClients;
use App\Platform\OAuth\ValueObjects\OrganizationChoice;
use App\Providers\ServiceConnectionsServiceProvider;
use App\ServiceConnections\ConnectionEvents;
use App\ServiceConnections\Contracts\Connections;
use App\ServiceConnections\Contracts\ReadsConnectionEvents;
use App\ServiceConnections\ServiceConnection;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\OAuthServer\Contracts\AudienceResolver;
use Cbox\Id\OAuthServer\Contracts\ClientIdMetadataDocuments;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\ProtectedResources;
use Cbox\Id\OAuthServer\Contracts\ServiceAccounts;
use Cbox\Id\OAuthServer\Contracts\TokenIntrospector;
use Cbox\Id\OAuthServer\Contracts\TokenIssuer;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Exceptions\InvalidAudience;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\Testing\InteractsWithOAuth;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Cbox\Id\OAuthServer\ValueObjects\IssuedToken;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(InteractsWithOAuth::class);

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

describe('automatic Sites connections', function (): void {
    beforeEach(function (): void {
        multiTenantDeployment((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        platformRootEnvironment();
        app()->instance(TokenIntrospector::class, $this->realIntrospector);
        [$this->sites, $this->assets, $this->autoOrg] = app(PlatformRoot::class)->run(function (): array {
            $sites = app(ClientRegistry::class)->register(new NewClient(name: 'Sites', type: ClientType::Confidential,
                grantTypes: ['client_credentials'], scopes: ['id:service-connection-provisions:write', 'service_connections.lease']))->client;
            $assets = app(ClientRegistry::class)->register(new NewClient(name: 'Assets', type: ClientType::Confidential,
                grantTypes: ['client_credentials'], scopes: ['service_connections.inspect']))->client;

            return [$sites, $assets, app(Organizations::class)->create(new NewOrganization('Automatic', 'automatic'))];
        });
        config(['service-connections.sites_consumer_client_id' => $this->sites->client_id,
            'service-connections.consumers' => [$this->sites->client_id],
            'service-connections.services.cbox_assets.client_id' => $this->assets->client_id,
            'service-access.billing_url' => 'https://billing.example.test',
            'service-connections.admission_tokens' => ['cbox-sites' => 'sites-check', 'cbox-assets' => 'assets-check']]);
        $this->autoToken = app(PlatformRoot::class)->run(fn () => app(TokenIssuer::class)->issueClientCredentials($this->sites, ['id:service-connection-provisions:write'])->token);
        $this->autoUrl = 'http://'.ApiHost::name().'/v1/service-connections/cbox_assets/provision';
        $this->admission = fn (string $key): array => ['allowed' => true, 'reason' => 'granted', 'program' => ['key' => $key, 'kind' => 'service', 'scope' => 'organization']];
        Http::preventStrayRequests();
        $this->billingResponse = fn ($request) => Http::response(
            ($this->admission)(str_contains($request->url(), '/cbox-sites/') ? 'cbox-sites' : 'cbox-assets'),
        );
        Http::fake(fn ($request) => ($this->billingResponse)($request));
    });

    it('provisions without a person, reuses the grant, and leases the unchanged Assets identity', function (): void {
        $id = $this->withToken($this->autoToken)->postJson($this->autoUrl, ['organization_id' => 'org_'.$this->autoOrg->id])
            ->assertOk()->assertJsonPath('organization_id', 'org_'.$this->autoOrg->id)->assertJsonMissingPath('client_secret')->json('id');
        $this->withToken($this->autoToken)->postJson($this->autoUrl, ['organization_id' => $this->autoOrg->id])->assertOk()->assertJsonPath('id', $id);
        app(PlatformRoot::class)->run(function (): void {
            expect(ServiceConnection::query()->count())->toBe(1);
            $connection = ServiceConnection::query()->sole();
            expect($connection->created_by)->toBe($this->sites->client_id);
            $entry = AuditEntry::query()->where('action', 'service_connection.created')->sole();
            expect($entry->actor_id)->toBe($this->sites->client_id);
        });
        $leaseCaller = app(PlatformRoot::class)->run(fn () => app(TokenIssuer::class)->issueClientCredentials($this->sites, ['service_connections.lease'])->token);
        $lease = $this->withToken($leaseCaller)->postJson('http://'.ApiHost::name().'/v1/service-connections/'.$id.'/lease')->assertOk();
        $claims = app(PlatformRoot::class)->run(fn () => $this->realIntrospector->introspect($lease->json('access_token')));
        expect($claims->isMachine())->toBeTrue()->and($claims->claims['org'])->toBe($this->autoOrg->id)
            ->and($claims->claims['aud'])->toBe('https://api.cboxassets.com')->and($claims->scopes)->toBe(['organization:read', 'assets:read', 'assets:write', 'assets:webhooks:write']);
        Http::assertSent(fn ($request) => $request->url() === 'https://billing.example.test/api/v1/access/services/cbox-sites/check'
            && $request->hasHeader('Authorization', 'Bearer sites-check') && $request->data() === ['subject' => $this->sites->client_id, 'organization' => $this->autoOrg->id]);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/cbox-assets/check') && $request->hasHeader('Authorization', 'Bearer assets-check'));
    });

    it('keeps the live pilot lease unchanged and refuses an implicit scope upgrade', function (): void {
        // Seed the previous production grant, including its original backing scope ceiling.
        $connection = app(PlatformRoot::class)->run(function (): ServiceConnection {
            $backing = PlatformRealmClients::forServiceConnection(
                fn () => app(ServiceAccounts::class)->create($this->autoOrg->id, 'Existing pilot', ['assets:consumer:read', 'assets:consumer:write']),
            );

            return ServiceConnection::query()->create([
                'id' => '01m8edwdd7qdyvqatq86jp7wpf', 'organization_id' => $this->autoOrg->id,
                'consumer_client_id' => $this->sites->client_id, 'service' => 'cbox_assets',
                'service_client_id' => $backing->client->client_id, 'audience' => 'https://api.cboxassets.com',
                'scopes' => ['assets:consumer:read', 'assets:consumer:write'], 'created_by' => 'original-operator',
            ]);
        });
        $snapshot = app(PlatformRoot::class)->run(fn () => $connection->fresh()->getRawOriginal());
        config(['cbox-id.oauth.protected_resources' => [[
            'identifier' => 'https://api.cboxassets.com', 'scopes' => ['mcp:read', 'mcp:write'], 'dynamic_clients' => true,
        ]]]);
        (new ServiceConnectionsServiceProvider(app()))->boot();
        $base = 'http://'.ApiHost::name().'/v1/service-connections/'.$connection->id;
        $leaseCaller = app(PlatformRoot::class)->run(fn () => app(TokenIssuer::class)->issueClientCredentials($this->sites, ['service_connections.lease'])->token);
        $provider = app(PlatformRoot::class)->run(fn () => app(TokenIssuer::class)->issueClientCredentials($this->assets, ['service_connections.inspect'])->token);
        $lease = $this->withToken($leaseCaller)->postJson($base.'/lease')->assertOk()->json('access_token');
        $claims = app(PlatformRoot::class)->run(fn () => $this->realIntrospector->introspect($lease));
        expect($claims->active)->toBeTrue()->and($claims->clientId)->toBe($connection->service_client_id)
            ->and($claims->claims['org'])->toBe($this->autoOrg->id)
            ->and($claims->claims['aud'])->toBe('https://api.cboxassets.com')
            ->and($claims->scopes)->toBe(['assets:consumer:read', 'assets:consumer:write']);
        $this->withToken($provider)->postJson($base.'/inspect')->assertOk()->assertJsonPath('active', true)
            ->assertJsonPath('scopes', ['assets:consumer:read', 'assets:consumer:write'])
            ->assertJsonPath('service_client_id', $connection->service_client_id);
        $this->withToken($this->autoToken)->postJson($this->autoUrl, ['organization_id' => $this->autoOrg->id])->assertConflict();
        expect(app(PlatformRoot::class)->run(fn () => $connection->fresh()->getRawOriginal()))->toBe($snapshot);

        // The exception neither follows a changed audience nor survives removal or revocation.
        config(['service-connections.services.cbox_assets.audience' => 'https://other.example']);
        $this->withToken($leaseCaller)->postJson($base.'/lease')->assertForbidden();
        $this->withToken($provider)->postJson($base.'/inspect')->assertOk()->assertJsonPath('active', false);
        config(['service-connections.services.cbox_assets.audience' => 'https://api.cboxassets.com',
            'service-connections.services.cbox_assets.legacy_connections' => ['01m8edwdd7qdyvqatq86jp7wpz' => ['assets:consumer:read', 'assets:consumer:write']]]);
        $this->withToken($leaseCaller)->postJson($base.'/lease')->assertForbidden();
        $this->withToken($provider)->postJson($base.'/inspect')->assertOk()->assertJsonPath('active', false);
        config(['service-connections.services.cbox_assets.legacy_connections' => [$connection->id => ['assets:consumer:read', 'assets:consumer:write']]]);
        app(PlatformRoot::class)->run(fn () => $connection->update(['scopes' => ['assets:consumer:read', 'assets:consumer:write', 'organization:admin']]));
        $this->withToken($leaseCaller)->postJson($base.'/lease')->assertForbidden();
        $this->withToken($provider)->postJson($base.'/inspect')->assertOk()->assertJsonPath('active', false);
        app(PlatformRoot::class)->run(fn () => $connection->update(['scopes' => ['assets:consumer:read', 'assets:consumer:write']]));
        app(PlatformRoot::class)->run(fn () => $connection->update(['revoked_at' => now()]));
        $this->withToken($leaseCaller)->postJson($base.'/lease')->assertNotFound();
        $this->withToken($provider)->postJson($base.'/inspect')->assertOk()->assertJsonPath('active', false);
        $this->withToken($this->autoToken)->postJson($this->autoUrl, ['organization_id' => $this->autoOrg->id])->assertConflict();
    });

    it('does not revive revoked connections or replace conflicting grants', function (string $conflict): void {
        $this->withToken($this->autoToken)->postJson($this->autoUrl, ['organization_id' => $this->autoOrg->id])->assertOk();
        app(PlatformRoot::class)->run(function () use ($conflict): void {
            $connection = ServiceConnection::query()->sole();
            if ($conflict === 'missing-client') {
                Client::query()->where('client_id', $connection->service_client_id)->delete();
            } else {
                $connection->update($conflict === 'revoked' ? ['revoked_at' => now()] : ['audience' => 'https://other.example']);
            }
        });
        $this->withToken($this->autoToken)->postJson($this->autoUrl, ['organization_id' => $this->autoOrg->id])->assertConflict();
        expect(app(PlatformRoot::class)->run(fn () => ServiceConnection::query()->count()))->toBe(1);
    })->with(['revoked', 'audience', 'missing-client']);

    it('denies a different caller, missing scope, person token and inactive organization', function (string $case): void {
        $token = app(PlatformRoot::class)->run(function () use ($case): string {
            $issuer = app(TokenIssuer::class);
            if ($case === 'person') {
                $person = app(Subjects::class)->create('automatic@example.test', 'Person');

                return $issuer->issueForUser($this->sites, $person->id, $this->autoOrg->id, ['id:service-connection-provisions:write'])->token;
            }
            if ($case === 'other-client') {
                $other = app(ClientRegistry::class)->register(new NewClient(name: 'Other', type: ClientType::Confidential,
                    grantTypes: ['client_credentials'], scopes: ['id:service-connection-provisions:write']))->client;

                return $issuer->issueClientCredentials($other, ['id:service-connection-provisions:write'])->token;
            }
            if ($case === 'inactive') {
                $this->autoOrg->update(['status' => OrganizationStatus::Suspended]);
            }

            return $case === 'scope' ? $issuer->issueClientCredentials($this->sites, ['service_connections.lease'])->token : $this->autoToken;
        });
        $this->withToken($token)->postJson($this->autoUrl, ['organization_id' => $this->autoOrg->id])->assertForbidden();
        Http::assertNothingSent();
        expect(app(PlatformRoot::class)->run(fn () => ServiceConnection::query()->count()))->toBe(0);
    })->with(['person', 'other-client', 'scope', 'inactive']);

    it('refuses a non-root environment even with the trusted machine principal', function (): void {
        $caller = Introspection::active($this->sites->client_id, $this->sites->client_id, ['id:service-connection-provisions:write'], []);
        try {
            app(Connections::class)->provision($caller, $this->autoOrg->id, 'cbox_assets');
            $this->fail('The root client must not provision in the current customer environment.');
        } catch (HttpException $error) {
            expect($error->getStatusCode())->toBe(403);
        }
        Http::assertNothingSent();
    });

    it('refuses misconfiguration before creating a grant', function (string $key, mixed $value): void {
        config([$key => $value]);
        $this->withToken($this->autoToken)->postJson($this->autoUrl, ['organization_id' => $this->autoOrg->id])->assertServiceUnavailable();
        expect(app(PlatformRoot::class)->run(fn () => ServiceConnection::query()->count()))->toBe(0);
    })->with([
        ['service-access.billing_url', 'http://billing.example.test'],
        ['service-access.billing_url', 'https://user:secret@billing.example.test'],
        ['service-connections.admission_tokens.cbox-assets', null],
        ['service-connections.services.cbox_assets.scopes', ['assets:admin']],
    ]);

    it('fails closed for denied, mismatched or unavailable Billing answers', function (string $case, int $status): void {
        $this->billingResponse = function ($request) use ($case) {
            $key = str_contains($request->url(), '/cbox-sites/') ? 'cbox-sites' : 'cbox-assets';
            $body = ($this->admission)($key);
            if ($key === 'cbox-assets') {
                match ($case) {
                    'denied' => $body['allowed'] = false,
                    'open' => $body['reason'] = 'open',
                    'wrong-product' => $body['program']['key'] = 'cbox-sites',
                    'person-policy' => $body['program']['scope'] = 'subject',
                    'malformed' => $body['allowed'] = 'true',
                    default => null,
                };
                if ($case === 'timeout') {
                    throw new ConnectionException('test timeout');
                }
            }

            return Http::response($body, $key === 'cbox-assets' && $case === 'unavailable' ? 503 : 200);
        };
        $this->withToken($this->autoToken)->postJson($this->autoUrl, ['organization_id' => $this->autoOrg->id])->assertStatus($status);
        expect(app(PlatformRoot::class)->run(fn () => ServiceConnection::query()->count()))->toBe(0);
    })->with([['denied', 403], ['open', 403], ['wrong-product', 503], ['person-policy', 503], ['malformed', 503], ['unavailable', 503], ['timeout', 503]]);
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

it('offers person connection management on the explicit root API resource without changing implicit audiences', function (): void {
    multiTenantDeployment((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    platformRootEnvironment();
    app(PlatformRoot::class)->run(function (): void {
        $scopes = ['id:service-connections:write', 'service_connections.manage'];
        $client = app(ClientRegistry::class)->register(new NewClient(name: 'Sites', scopes: $scopes))->client;
        $resolver = app(AudienceResolver::class);
        $mcp = app(ProtectedResources::class)->forMetadataPath('/.well-known/oauth-protected-resource/mcp');
        foreach ($scopes as $scope) {
            $resolved = $resolver->resolve($client, [$scope], ManagementAudience::api());
            expect($resolved->scopes)->toBe([$scope])
                ->and($resolved->resource)->toBe(ManagementAudience::api())
                ->and($resolved->includesIssuer)->toBeFalse()
                ->and($resolver->resolve($client, [$scope], null)->resource)->toBeNull();
            expect(fn () => $resolver->resolve($client, [$scope], $mcp->identifier))->toThrow(InvalidAudience::class);
        }
        $resource = app(ProtectedResources::class)->find((string) ManagementAudience::api());
        expect($resource->scopes)->toContain('id:service-connections:write', 'webhooks:read');
        foreach (['service_connections.manage', 'service_connections.lease', 'id:service-connection-leases:write', 'service_connections.inspect', 'id:service-connections:read'] as $unoffered) {
            expect($resource->scopes)->not->toContain($unoffered);
        }
    });
    $metadata = $this->getJson('http://'.ApiHost::name().'/.well-known/oauth-protected-resource')->assertOk();
    expect($metadata->json('scopes_supported'))->toContain('id:service-connections:write', 'webhooks:read');
});

it('admits API-audienced person connection management only on the API host and keeps live authorization', function (string $scope): void {
    multiTenantDeployment((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    platformRootEnvironment();
    app()->instance(TokenIntrospector::class, $this->realIntrospector);
    [$person, $organization, $client, $otherClient] = app(PlatformRoot::class)->run(function () use ($scope): array {
        $person = app(Subjects::class)->create('sites-connect@example.test', 'Sites administrator');
        $organization = app(Organizations::class)->create(new NewOrganization('Sites customer', 'sites-customer'));
        app(Memberships::class)->add($organization->id, $person->id, MembershipRole::Admin);
        $client = app(ClientRegistry::class)->register(new NewClient(name: 'Sites', scopes: [$scope]))->client;
        $otherClient = app(ClientRegistry::class)->register(new NewClient(name: 'Other consumer', scopes: [$scope]))->client;

        return [$person, $organization, $client, $otherClient];
    });
    config(['service-connections.consumers' => [$client->client_id]]);
    $issue = fn (?string $resource, ?Client $consumer = null, ?string $org = null): string => app(PlatformRoot::class)->run(
        fn (): string => app(TokenIssuer::class)->issueForUser($consumer ?? $client, $person->id, $org ?? $organization->id, [$scope], $resource)->token,
    );
    $token = $issue(ManagementAudience::api());
    $claims = app(PlatformRoot::class)->run(fn () => $this->realIntrospector->introspect($token));
    expect($claims->claims['aud'])->toBe(ManagementAudience::api())
        ->and($claims->scopes)->toBe([$scope]);
    $base = 'http://'.ApiHost::name().'/v1/service-connections';
    $id = $this->withToken($token)->putJson($base.'/cbox_assets')->assertOk()
        ->assertJsonPath('organization_id', 'org_'.$organization->id)->assertJsonMissingPath('client_secret')->json('id');
    $this->withToken($token)->putJson($base.'/cbox_assets')->assertOk()->assertJsonPath('id', $id);
    $this->withToken($token)->getJson($base)->assertOk()->assertJsonPath('data.0.id', $id);
    $this->withToken($token)->postJson('/api/v1/service-connections/cbox_assets')->assertUnauthorized();
    $this->withToken($token)->postJson($base.'/'.$id.'/lease')->assertUnauthorized();
    $this->withToken($token)->postJson($base.'/'.$id.'/inspect')->assertUnauthorized();
    $this->withToken($token)->postJson('http://'.ApiHost::name().'/v1/vault/secrets')->assertUnauthorized();

    // Issuer-only tokens, including old clients omitting resource, keep working.
    $this->withToken($issue(null))->postJson('/api/v1/service-connections/cbox_assets')->assertOk();
    $issuer = app(PlatformRoot::class)->run(fn (): string => app(IssuerResolver::class)->issuer());
    $this->withToken($issue($issuer))->putJson($base.'/cbox_assets')->assertOk()->assertJsonPath('id', $id);
    $this->withToken($issue('https://api.cboxassets.com'))->putJson($base.'/cbox_assets')->assertUnauthorized();
    $this->withToken($issue((string) ManagementAudience::api().'/'))->putJson($base.'/cbox_assets')->assertUnauthorized();
    $this->withToken($issue(ManagementAudience::api(), $otherClient))->putJson($base.'/cbox_assets')->assertForbidden();
    $this->withToken($issue(ManagementAudience::api(), org: 'org_not_mine'))->putJson($base.'/cbox_assets')->assertForbidden();
    $machine = app(PlatformRoot::class)->run(fn (): string => app(TokenIssuer::class)->issueClientCredentials($client, [$scope], ManagementAudience::api())->token);
    $this->withToken($machine)->putJson($base.'/cbox_assets')->assertForbidden();
    $this->withToken($token)->deleteJson($base.'/'.$id)->assertOk();
    app(PlatformRoot::class)->run(fn () => app(Memberships::class)->changeRole($organization->id, $person->id, MembershipRole::Member));
    $this->withToken($token)->putJson($base.'/cbox_assets')->assertForbidden();
})->with(['id:service-connections:write', 'service_connections.manage'])->group('security');

it('never grants person or machine provisioning scopes to DCR or metadata-document clients', function (string $registration): void {
    multiTenantDeployment((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    platformRootEnvironment();
    $scopes = ['webhooks:read', 'id:service-connections:write', 'service_connections.manage', 'id:service-connection-provisions:write'];
    $document = [
        'client_name' => 'Self-registered', 'redirect_uris' => ['http://127.0.0.1:33418/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'], 'token_endpoint_auth_method' => 'none',
        'scope' => implode(' ', $scopes),
    ];
    if ($registration === 'dcr') {
        $response = $this->postJson('/oauth/register', $document)->assertCreated();
        $client = app(PlatformRoot::class)->run(fn () => Client::query()->where('client_id', $response->json('client_id'))->sole());
    } else {
        $url = 'https://agent.example/oauth/client.json';
        $this->fakeClientMetadataDocuments()->serve($url, ['client_id' => $url, ...$document]);
        $client = app(PlatformRoot::class)->run(fn () => app(ClientIdMetadataDocuments::class)->resolve($url));
    }
    expect($client->scopes)->toBe(['webhooks:read']);
    app(PlatformRoot::class)->run(function () use ($client, $scopes): void {
        // A stale self-registered client cannot bypass the reservation at token issuance.
        $client->scopes = $scopes;
        $person = app(Subjects::class)->create('self-registered@example.test', 'MCP user');
        $issuer = app(TokenIssuer::class);
        $issued = $issuer->issueForUser($client, $person->id, null, $scopes, ManagementAudience::api());
        expect($this->realIntrospector->introspect($issued->token)->scopes)->toBe(['webhooks:read']);
        foreach (array_slice($scopes, 1) as $scope) {
            expect(fn () => $issuer->issueForUser($client, $person->id, null, [$scope], ManagementAudience::api()))->toThrow(InvalidAudience::class);
        }
    });
})->with(['dcr', 'cimd'])->group('security');

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
        ->and($token->scopes)->toBe(['organization:read', 'assets:read', 'assets:write', 'assets:webhooks:write'])
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
            ['identifier' => 'https://other.test/mcp', 'scopes' => ['other:read', 'webhooks:write'], 'dynamic_clients' => true],
        ],
    ]);
    (new ServiceConnectionsServiceProvider(app()))->boot();
    $consumerScopes = ['assets:consumer:read', 'assets:consumer:write'];
    $registered = $this->postJson('/oauth/register', [
        'client_name' => 'Assets MCP client',
        'redirect_uris' => ['http://127.0.0.1:33418/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'token_endpoint_auth_method' => 'none',
        'scope' => 'mcp:read mcp:write other:read webhooks:write assets:consumer:read assets:consumer:write vault.lease host:reserved',
    ])->assertCreated()->assertJsonPath('scope', 'mcp:read mcp:write other:read webhooks:write');
    $client = Client::query()->where('client_id', $registered->json('client_id'))->sole();
    $person = app(Subjects::class)->create('assets-mcp@example.test', 'MCP user');
    $issuer = app(TokenIssuer::class);
    $otherToken = $issuer->issueForUser($client, $person->id, null, ['webhooks:write'], 'https://other.test/mcp');
    expect($this->realIntrospector->introspect($otherToken->token)->scopes)->toBe(['webhooks:write']);

    // Even a stale client carrying reserved scopes cannot put them in a signed token.
    $client->scopes = [...$client->scopes, ...$consumerScopes, 'vault.lease', 'host:reserved'];
    $issued = $issuer->issueForUser($client, $person->id, null, $client->scopes, 'https://api.cboxassets.com');
    $token = $this->realIntrospector->introspect($issued->token);
    expect($token->active)->toBeTrue()
        ->and($token->claims['aud'])->toBe('https://api.cboxassets.com')
        ->and($token->scopes)->toBe(['mcp:read', 'mcp:write']);
    expect(fn () => $issuer->issueForUser($client, $person->id, null, $consumerScopes, 'https://api.cboxassets.com'))
        ->toThrow(InvalidAudience::class);
    expect(app(ProtectedResources::class)->find('https://other.test/mcp')?->scopes)->toBe(['other:read', 'webhooks:write']);
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
