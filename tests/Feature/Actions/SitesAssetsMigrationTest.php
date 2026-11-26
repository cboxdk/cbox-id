<?php

declare(strict_types=1);

use App\Platform\Actions\Approvals\ActionApprovalRequest;
use App\Platform\Api\ApiHost;
use App\ServiceConnections\ServiceConnection;
use App\Support\CliClient;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\ProtectedResources;
use Cbox\Id\OAuthServer\JwtTokenIssuer;
use Cbox\Id\OAuthServer\ValueObjects\AuthenticationEvent;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Organizations;
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
        $orgs = [
            app(Organizations::class)->create(new NewOrganization('First admitted Sites org', 'sites-first'))->id,
            app(Organizations::class)->create(new NewOrganization('Second admitted Sites org', 'sites-second'))->id,
        ];
        sort($orgs);
        $this->orgs = $orgs;
        config([
            'service-connections.consumers' => [$consumer->client_id],
            'service-connections.sites_consumer_client_id' => $consumer->client_id,
            'service-connections.services.cbox_assets.client_id' => $provider->client_id,
            'service-connections.sites_assets_migration' => [
                'environment_id' => $this->root->model()->id, 'consumer_client_id' => $consumer->client_id,
                'organization_ids' => $orgs, 'evidence' => 'isolated-test-inventory',
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
    $this->base = 'http://'.ApiHost::name().'/v1/admin/sites-assets-migrations/';
});

it('shows every grant and provisions it only once after real operator approval', function (): void {
    $plan = $this->withToken($this->token)->getJson($this->base.'plan')->assertOk()->json('plan');
    expect($plan['organization_ids'])->toBe(array_map(fn ($id) => 'org_'.$id, $this->orgs))
        ->and($plan['service'])->toBe('cbox_assets')
        ->and($plan['audience'])->toBe('https://api.cboxassets.com')
        ->and($plan['scopes'])->toBe(['assets:consumer:read', 'assets:consumer:write'])
        ->and($this->root->run(fn () => ServiceConnection::query()->count()))->toBe(0);
    $body = ['plan' => $plan];
    $approval = $this->withHeader('Idempotency-Key', 'sites-migration')->postJson($this->base.'provision', $body)
        ->assertStatus(202)->assertJsonPath('object', 'approval')->json('id');
    expect($this->root->run(fn () => ServiceConnection::query()->count()))->toBe(0)
        ->and(ActionApprovalRequest::query()->findOrFail(substr($approval, 6))->input['plan'])->toBe($plan);
    $this->withHeader('Cbox-Approval', $approval)->postJson($this->base.'provision', $body)->assertConflict();
    expect($this->root->run(fn () => app(BackchannelAuthentication::class)->approve(substr($approval, 6), (string) $this->operator->subject_id)))->toBeTrue();
    $result = $this->postJson($this->base.'provision', $body)->assertOk()->json();
    expect($result['connections'])->toHaveCount(2)
        ->and($this->root->run(fn () => ServiceConnection::query()->count()))->toBe(2);
    foreach ($result['connections'] as $index => $connection) {
        expect(array_keys($connection))->toBe(['object', 'id', 'organization_id', 'service_client_id'])
            ->and($connection['object'])->toBe('service_connection')
            ->and($connection['id'])->toBe($this->root->run(fn () => ServiceConnection::query()->where('organization_id', $this->orgs[$index])->value('id')))
            ->and($connection['organization_id'])->toBe('org_'.$this->orgs[$index]);
    }
    $this->postJson($this->base.'provision', $body)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
    $this->withoutHeader('Idempotency-Key')->postJson($this->base.'provision', $body)->assertConflict();
    $entries = $this->root->run(fn () => AuditEntry::query()->where('action', 'service_connection.created')->get());
    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('actor_type')->unique()->all())->toBe([ActorType::Operator])
        ->and($entries->pluck('actor_id')->unique()->all())->toBe([$this->operator->id]);
});

it('rejects a changed grant instead of asking approval for broader authority', function (string $field, mixed $value): void {
    $plan = $this->withToken($this->token)->getJson($this->base.'plan')->assertOk()->json('plan');
    if ($field === 'organization_ids') {
        // An active ID organization is not evidence of Sites product admission.
        $unlisted = $this->root->run(fn () => app(Organizations::class)->create(new NewOrganization('Not admitted in Sites', 'sites-unlisted'))->id);
        $value = [...$plan['organization_ids'], 'org_'.$unlisted];
    }
    $plan[$field] = $value;
    $this->postJson($this->base.'provision', ['plan' => $plan])->assertConflict();
    expect($this->root->run(fn () => ServiceConnection::query()->count()))->toBe(0)
        ->and(ActionApprovalRequest::query()->count())->toBe(0);
})->with([
    'extra organization' => ['organization_ids', ['org_01j00000000000000000000000']],
    'other consumer' => ['consumer_client_id', 'cid_other'],
    'other service' => ['service', 'cbox_billing'],
    'scope widening' => ['scopes', ['assets:consumer:read', 'assets:admin:write']],
    'other audience' => ['audience', 'https://api.cboxassets.com/mcp'],
]);

it('rechecks expiry, registration and organization standing after approval', function (string $change, int $status): void {
    if ($change === 'expiry') {
        config(['service-connections.sites_assets_migration.expires_at' => now()->addSeconds(30)->format('Y-m-d\TH:i:s\Z')]);
    }
    $plan = $this->withToken($this->token)->getJson($this->base.'plan')->assertOk()->json('plan');
    $body = ['plan' => $plan];
    $approval = $this->postJson($this->base.'provision', $body)->assertStatus(202)->json('id');
    $this->root->run(fn () => app(BackchannelAuthentication::class)->approve(substr($approval, 6), (string) $this->operator->subject_id));
    match ($change) {
        'expiry' => $this->travel(31)->seconds(),
        'evidence' => config(['service-connections.sites_assets_migration.evidence' => 'a-different-inventory']),
        'scope' => config(['service-connections.services.cbox_assets.scopes' => ['assets:admin:write']]),
        'consumer' => config(['service-connections.sites_consumer_client_id' => 'cid_other']),
        'realm' => config(['service-connections.sites_assets_migration.environment_id' => $this->orgs[0]]),
        'provider' => config(['service-connections.services.cbox_assets.client_id' => 'cid_missing']),
        'inactive' => $this->root->run(fn () => Organization::query()->whereKey($this->orgs[1])->update(['status' => OrganizationStatus::Suspended])),
    };
    $this->withHeader('Cbox-Approval', $approval)->postJson($this->base.'provision', $body)->assertStatus($status);
    expect($this->root->run(fn () => ServiceConnection::query()->count()))->toBe(0);
})->with([
    ['evidence', 409], ['scope', 503], ['consumer', 503], ['realm', 503], ['provider', 503], ['inactive', 409], ['expiry', 503],
]);

it('refuses an expired manifest in dry-run and provisioning without issuing an approval', function (): void {
    $plan = $this->withToken($this->token)->getJson($this->base.'plan')->assertOk()->json('plan');
    config(['service-connections.sites_assets_migration.expires_at' => now()->subSecond()->format('Y-m-d\TH:i:s\Z')]);
    $this->getJson($this->base.'plan')->assertStatus(503);
    $this->postJson($this->base.'provision', ['plan' => $plan])->assertStatus(503);
    expect(ActionApprovalRequest::query()->count())->toBe(0);
});

it('requires a live operator and the migration scope, not just a signed token', function (string $caller): void {
    $plan = $this->withToken($this->token)->getJson($this->base.'plan')->assertOk()->json('plan');
    $token = $this->token;
    if ($caller === 'suspended') {
        $this->operator->update(['status' => OperatorStatus::Suspended]);
    } else {
        $token = $this->root->run(function () use ($caller): string {
            $client = CliClient::provision(app(ClientRegistry::class))[0];
            $issuer = app(JwtTokenIssuer::class);
            $audience = app(ProtectedResources::class)->forMetadataPath('/.well-known/oauth-protected-resource/mcp')->identifier;

            return $caller === 'machine'
                ? $issuer->issueClientCredentials($client, ['operator:service-connections:write'], $audience)->token
                : $issuer->issueForAuthenticatedUser($client, (string) $this->operator->subject_id, null,
                    ['openid', $caller === 'read-only' ? 'operator:service-connections:read' : 'operator:workspaces:write'],
                    new AuthenticationEvent(time(), ['pwd', 'otp']), $audience)->token;
        });
    }
    $this->withToken($token)->getJson($this->base.'plan')->assertStatus($caller === 'read-only' ? 200 : 403);
    $this->postJson($this->base.'provision', ['plan' => $plan])->assertForbidden();
    expect($this->root->run(fn () => ServiceConnection::query()->count()))->toBe(0)
        ->and(ActionApprovalRequest::query()->count())->toBe(0);
})->with(['suspended', 'missing scope', 'read-only', 'machine']);

it('reuses active grants without credentials and never revives a revoked grant', function (): void {
    $plan = $this->withToken($this->token)->getJson($this->base.'plan')->assertOk()->json('plan');
    $body = ['plan' => $plan];
    $results = [];
    foreach ([1, 2] as $run) {
        $approval = $this->withoutHeader('Cbox-Approval')->postJson($this->base.'provision', $body)->assertStatus(202)->json('id');
        $this->root->run(fn () => app(BackchannelAuthentication::class)->approve(substr($approval, 6), (string) $this->operator->subject_id));
        $results[] = $this->withHeader('Cbox-Approval', $approval)->postJson($this->base.'provision', $body)->assertOk()->json('connections');
    }
    expect($results[1])->toBe($results[0])
        ->and($this->root->run(fn () => ServiceConnection::query()->count()))->toBe(2);

    // Revocation between the approval request and execution must still win.
    $approval = $this->withoutHeader('Cbox-Approval')->postJson($this->base.'provision', $body)->assertStatus(202)->json('id');
    $this->root->run(function () use ($approval): void {
        app(BackchannelAuthentication::class)->approve(substr($approval, 6), (string) $this->operator->subject_id);
        ServiceConnection::query()->where('organization_id', $this->orgs[1])->update(['revoked_at' => now()]);
    });
    $this->withHeader('Cbox-Approval', $approval)->postJson($this->base.'provision', $body)->assertConflict();
    $this->getJson($this->base.'plan')->assertConflict();
    expect($this->root->run(fn () => ServiceConnection::query()->whereNotNull('revoked_at')->count()))->toBe(1);
});

it('cannot spend an approval on a newly attested plan', function (): void {
    $plan = $this->withToken($this->token)->getJson($this->base.'plan')->assertOk()->json('plan');
    $approval = $this->postJson($this->base.'provision', ['plan' => $plan])->assertStatus(202)->json('id');
    $this->root->run(fn () => app(BackchannelAuthentication::class)->approve(substr($approval, 6), (string) $this->operator->subject_id));
    config(['service-connections.sites_assets_migration.evidence' => 'replacement-inventory']);
    $replacement = $this->getJson($this->base.'plan')->assertOk()->json('plan');
    expect($replacement['manifest_digest'])->not->toBe($plan['manifest_digest']);
    $this->withHeader('Cbox-Approval', $approval)->postJson($this->base.'provision', ['plan' => $replacement])->assertConflict();
    expect($this->root->run(fn () => ServiceConnection::query()->count()))->toBe(0);
});
