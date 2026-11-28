<?php

declare(strict_types=1);

use App\Platform\Actions\Approvals\ActionApprovalRequest;
use App\Platform\Actions\Idempotency\IdempotencyRecord;
use App\Platform\Api\ApiHost;
use App\Platform\OAuth\PlatformRealmClients;
use App\ServiceConnections\Contracts\Connections;
use App\ServiceConnections\ServiceConnection;
use App\ServiceConnections\ServiceConnectionTransition;
use App\Support\CliClient;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\ProtectedResources;
use Cbox\Id\OAuthServer\Contracts\ServiceAccounts;
use Cbox\Id\OAuthServer\Contracts\TokenIntrospector;
use Cbox\Id\OAuthServer\Enums\ServiceAccountStatus;
use Cbox\Id\OAuthServer\JwtTokenIssuer;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\Models\ServiceAccount;
use Cbox\Id\OAuthServer\ValueObjects\AuthenticationEvent;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Enums\OperatorStatus;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    Mail::fake();
    $this->operator = actAsOperator();
    forgetSubjectSession();
    multiTenantDeployment((string) parse_url(config('app.url'), PHP_URL_HOST));
    $this->root = app(PlatformRoot::class);
    $this->root->run(function (): void {
        $consumer = app(ClientRegistry::class)->register(new NewClient(name: 'Sites', scopes: ['service_connections.lease']))->client;
        $provider = app(ClientRegistry::class)->register(new NewClient(name: 'Assets', scopes: ['service_connections.inspect']))->client;
        $org = app(Organizations::class)->create(new NewOrganization('Sites pilot', 'sites-transition'));
        $oldScopes = ['assets:consumer:read', 'assets:consumer:write'];
        $backing = PlatformRealmClients::forServiceConnection(fn () => app(ServiceAccounts::class)->create($org->id, 'Sites legacy', $oldScopes));
        $this->connection = ServiceConnection::query()->create([
            'organization_id' => $org->id, 'consumer_client_id' => $consumer->client_id, 'service' => 'cbox_assets',
            'service_client_id' => $backing->client->client_id, 'audience' => 'https://api.cboxassets.com',
            'scopes' => $oldScopes, 'created_by' => $this->operator->subject_id,
        ]);
        $this->oldClient = $backing->client->client_id;
        $this->oldAccount = ServiceAccount::query()->where('client_id', $this->oldClient)->sole()->id;
        $this->caller = Introspection::active($consumer->client_id, $consumer->client_id, ['service_connections.lease'], []);
        config([
            'service-connections.consumers' => [$consumer->client_id],
            'service-connections.sites_consumer_client_id' => $consumer->client_id,
            'service-connections.services.cbox_assets.client_id' => $provider->client_id,
            'service-connections.services.cbox_assets.legacy_connections' => [$this->connection->id => $oldScopes],
            'service-connections.sites_assets_transition' => [
                'environment_id' => $this->root->model()->id, 'organization_id' => $org->id,
                'connection_id' => $this->connection->id, 'consumer_client_id' => $consumer->client_id,
                'provider_client_id' => $provider->client_id, 'old_service_client_id' => $this->oldClient,
                'old_service_account_id' => $this->oldAccount, 'manifest_digest' => hash('sha256', 'verified byte-copy manifest'),
                'expires_at' => now()->addHour()->format('Y-m-d\TH:i:s\Z'),
            ],
        ]);
        $client = CliClient::provision(app(ClientRegistry::class))[0];
        $resource = app(ProtectedResources::class)->forMetadataPath('/.well-known/oauth-protected-resource/mcp');
        $this->token = app(JwtTokenIssuer::class)->issueForAuthenticatedUser(
            $client, (string) $this->operator->subject_id, null, ['openid', 'id:admin-service-connections:read', 'id:admin-service-connections:write'],
            new AuthenticationEvent(time(), ['pwd', 'otp']), $resource->identifier,
        )->token;
    });
    $this->base = 'http://'.ApiHost::name().'/v1/admin/sites-assets-transitions';
    $this->withToken($this->token);
});

it('atomically replaces only the backing client and recovers the receipt without another rotation', function (): void {
    $oldToken = $this->root->run(fn () => app(Connections::class)->lease($this->caller, $this->connection->id));
    expect($this->root->run(fn () => app(TokenIntrospector::class)->introspect($oldToken->token)->active))->toBeTrue();
    $plan = $this->getJson($this->base.'/plan')->assertOk()->json('plan');
    expect($plan['old_scopes'])->toBe(['assets:consumer:read', 'assets:consumer:write'])
        ->and($plan['new_scopes'])->toBe(['organization:read', 'assets:read', 'assets:write', 'assets:webhooks:write']);
    $body = ['plan' => $plan];
    $approval = $this->withHeader('Idempotency-Key', 'transition')->postJson($this->base, $body)->assertStatus(202)->json('id');
    $this->root->run(function () use ($approval, $plan): void {
        expect($this->connection->fresh()->service_client_id)->toBe($this->oldClient)
            ->and(ActionApprovalRequest::query()->findOrFail(substr($approval, 6))->input['plan'])->toBe($plan);
        app(BackchannelAuthentication::class)->approve(substr($approval, 6), (string) $this->operator->subject_id);
    });
    $result = $this->withHeader('Cbox-Approval', $approval)->postJson($this->base, $body)->assertOk()->json();
    expect(array_keys($result['grant']))->toBe(['id', 'organization_id', 'consumer_client_id', 'service', 'audience', 'scopes'])
        ->and($result['grant']['id'])->toBe($this->connection->id)
        ->and($result['grant']['consumer_client_id'])->toBe($this->connection->consumer_client_id)
        ->and($result['receipt']['old_service_client_id'])->toBe($this->oldClient)
        ->and($result['receipt']['new_service_client_id'])->not->toBe($this->oldClient)
        ->and($result['receipt']['manifest_digest'])->toBe($plan['manifest_digest'])
        ->and($result['receipt']['approval_id'])->toBe($approval);
    $this->root->run(function () use ($result, $oldToken): void {
        expect(Client::query()->where('client_id', $this->oldClient)->exists())->toBeFalse()
            ->and(ServiceAccount::query()->findOrFail($this->oldAccount)->status)->toBe(ServiceAccountStatus::Retired)
            ->and(ServiceConnection::query()->count())->toBe(1)
            ->and(ServiceAccount::query()->count())->toBe(2)
            ->and(ServiceConnectionTransition::query()->count())->toBe(1)
            ->and($this->connection->fresh()->service_client_id)->toBe($result['receipt']['new_service_client_id'])
            ->and(app(TokenIntrospector::class)->introspect($oldToken->token)->active)->toBeFalse();
        $fresh = app(Connections::class)->lease($this->caller, $this->connection->id);
        expect($fresh->scopes)->toBe(['organization:read', 'assets:read', 'assets:write', 'assets:webhooks:write'])
            ->and($fresh->audience)->toBe('https://api.cboxassets.com')
            ->and(app(TokenIntrospector::class)->introspect($fresh->token)->active)->toBeTrue()
            ->and(app(TokenIntrospector::class)->introspect($fresh->token)->clientId)->toBe($result['receipt']['new_service_client_id']);
        $audit = AuditEntry::query()->where('action', 'service_connection.transitioned')->sole();
        expect($result['receipt']['audit_entry_id'])->toBe($audit->id)
            ->and($audit->context['approval_id'])->toBe(substr($result['receipt']['approval_id'], 6));
    });
    $this->postJson($this->base, $body)->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertExactJson($result);
    // Simulate the write committing but the response/idempotency persistence being lost.
    IdempotencyRecord::query()->delete();
    $this->getJson($this->base.'/'.$plan['plan_digest'])->assertOk()->assertExactJson($result);
    $this->postJson($this->base, $body)->assertConflict();
    $this->root->run(fn () => $this->connection->fresh()->update(['revoked_at' => now()]));
    config(['service-connections.sites_assets_transition.expires_at' => now()->subSecond()->format('Y-m-d\TH:i:s\Z')]);
    $this->getJson($this->base.'/'.$plan['plan_digest'])->assertOk()->assertExactJson($result);
    config(['service-connections.sites_assets_transition' => null]);
    $this->getJson($this->base.'/'.$plan['plan_digest'])->assertOk()->assertExactJson($result);
    $this->getJson($this->base.'/'.str_repeat('0', 64))->assertNotFound();
    $this->root->run(function (): void {
        $receipt = ServiceConnectionTransition::query()->sole();
        expect(fn () => $receipt->update(['payload' => []]))->toThrow(LogicException::class)
            ->and(fn () => $receipt->delete())->toThrow(LogicException::class)
            ->and(ServiceAccount::query()->count())->toBe(2);
    });
});

it('refuses altered plans before asking for approval', function (string $field, mixed $value): void {
    $plan = $this->getJson($this->base.'/plan')->assertOk()->json('plan');
    $plan[$field] = $value;
    $this->postJson($this->base, ['plan' => $plan])->assertConflict();
    expect(ActionApprovalRequest::query()->count())->toBe(0);
    $this->root->run(fn () => expect($this->connection->fresh()->service_client_id)->toBe($this->oldClient));
})->with([
    ['manifest_digest', str_repeat('a', 64)], ['plan_digest', str_repeat('b', 64)],
    ['old_service_client_id', 'cid_other'], ['consumer_client_id', 'cid_other'],
    ['new_scopes', ['organization:admin']], ['old_scopes', ['assets:read']], ['audience', 'https://other.test'],
]);

it('rechecks old identity and standing after approval and never repairs a changed grant', function (string $change, int $status): void {
    $plan = $this->getJson($this->base.'/plan')->assertOk()->json('plan');
    $approval = $this->postJson($this->base, ['plan' => $plan])->assertStatus(202)->json('id');
    $this->root->run(function () use ($approval, $change): void {
        app(BackchannelAuthentication::class)->approve(substr($approval, 6), (string) $this->operator->subject_id);
        match ($change) {
            'revoked' => $this->connection->update(['revoked_at' => now()]),
            'missing grant' => $this->connection->delete(),
            'backing changed' => $this->connection->update(['service_client_id' => 'cid_other']),
            'scopes changed' => $this->connection->update(['scopes' => ['assets:read']]),
            'consumer changed' => $this->connection->update(['consumer_client_id' => 'cid_other']),
            'org inactive' => Organization::query()->whereKey($this->connection->organization_id)->update(['status' => OrganizationStatus::Suspended]),
            'root inactive' => $this->root->model()->update(['status' => EnvironmentStatus::Suspended]),
            'client missing' => Client::query()->where('client_id', $this->oldClient)->delete(),
            'account missing' => ServiceAccount::query()->whereKey($this->oldAccount)->delete(),
            'account retired' => ServiceAccount::query()->whereKey($this->oldAccount)->update(['status' => ServiceAccountStatus::Retired]),
            'account mismatch' => ServiceAccount::query()->whereKey($this->oldAccount)->update(['client_id' => 'cid_other']),
            'client scope changed' => Client::query()->where('client_id', $this->oldClient)->update(['scopes' => ['assets:read']]),
            'provider missing' => Client::query()->where('client_id', config('service-connections.services.cbox_assets.client_id'))->delete(),
            'manifest changed' => config(['service-connections.sites_assets_transition.manifest_digest' => str_repeat('c', 64)]),
            'expired' => config(['service-connections.sites_assets_transition.expires_at' => now()->subSecond()->format('Y-m-d\TH:i:s\Z')]),
        };
    });
    $this->withHeader('Cbox-Approval', $approval)->postJson($this->base, ['plan' => $plan])->assertStatus($status);
    $this->root->run(fn () => expect(ServiceConnectionTransition::query()->count())->toBe(0)
        ->and(AuditEntry::query()->where('action', 'service_connection.transitioned')->count())->toBe(0));
})->with([
    ['revoked', 409], ['missing grant', 409], ['backing changed', 409], ['scopes changed', 409], ['consumer changed', 409],
    ['org inactive', 409], ['root inactive', 503], ['client missing', 409], ['account missing', 409],
    ['account retired', 409], ['account mismatch', 409], ['client scope changed', 409], ['provider missing', 503],
    ['manifest changed', 409], ['expired', 503],
]);

it('rolls back creation retirement and grant changes when the durable receipt cannot be stored', function (): void {
    $plan = $this->getJson($this->base.'/plan')->assertOk()->json('plan');
    $approval = $this->postJson($this->base, ['plan' => $plan])->assertStatus(202)->json('id');
    $this->root->run(fn () => app(BackchannelAuthentication::class)->approve(substr($approval, 6), (string) $this->operator->subject_id));
    ServiceConnectionTransition::creating(static fn () => throw new RuntimeException('Receipt storage unavailable'));
    try {
        $this->withHeader('Cbox-Approval', $approval)->postJson($this->base, ['plan' => $plan])->assertStatus(500);
    } finally {
        ServiceConnectionTransition::flushEventListeners();
        ServiceConnectionTransition::clearBootedModels();
    }
    $this->root->run(fn () => expect($this->connection->fresh()->service_client_id)->toBe($this->oldClient)
        ->and(Client::query()->where('client_id', $this->oldClient)->exists())->toBeTrue()
        ->and(ServiceAccount::query()->findOrFail($this->oldAccount)->status)->toBe(ServiceAccountStatus::Active)
        ->and(ServiceAccount::query()->count())->toBe(1)
        ->and(ServiceConnectionTransition::query()->count())->toBe(0)
        ->and(AuditEntry::query()->where('action', 'service_connection.transitioned')->count())->toBe(0));
    $this->getJson($this->base.'/'.$plan['plan_digest'])->assertNotFound();
});

it('denies nonoperators and unscoped callers without disclosing receipts', function (string $caller): void {
    $plan = $this->getJson($this->base.'/plan')->assertOk()->json('plan');
    if ($caller === 'suspended') {
        $this->operator->update(['status' => OperatorStatus::Suspended]);
    } else {
        $token = $this->root->run(function () use ($caller): string {
            $client = CliClient::provision(app(ClientRegistry::class))[0];
            $audience = app(ProtectedResources::class)->forMetadataPath('/.well-known/oauth-protected-resource/mcp')->identifier;

            return $caller === 'machine'
                ? app(JwtTokenIssuer::class)->issueClientCredentials($client, ['operator:service-connections:write'], $audience)->token
                : app(JwtTokenIssuer::class)->issueForAuthenticatedUser($client, (string) $this->operator->subject_id, null,
                    ['openid', 'operator:workspaces:write'], new AuthenticationEvent(time(), ['pwd', 'otp']), $audience)->token;
        });
        $this->withToken($token);
    }
    $this->postJson($this->base, ['plan' => $plan])->assertForbidden();
    $this->getJson($this->base.'/'.$plan['plan_digest'])->assertForbidden();
    expect(ActionApprovalRequest::query()->count())->toBe(0);
})->with(['suspended', 'machine', 'unscoped']);
