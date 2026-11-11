<?php

declare(strict_types=1);

use App\Actions\Apps\CreateApp;
use App\Http\Middleware\RefuseCustomerClientsInPlatformRealm;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\Principal\Principal;
use App\Platform\OAuth\PlatformRealmClients;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

/**
 * NO CUSTOMER-CREATED OAUTH CLIENTS IN THE PLATFORM'S OWN REALM
 * (`cbox-id.realm.platform_clients_only`): refused when created, by every door, and not
 * served when one is already there. Customers' own environments are unaffected.
 *
 * @return array{rootId: string, tenantId: string, workspaceId: string}
 */
function prcDeployment(bool $ruleOn = true): array
{
    multiTenantDeployment();
    config(['cbox-id.realm.platform_clients_only' => $ruleOn]);

    $tenant = provisionAccount();

    return [
        'rootId' => platformRootEnvironment()->id,
        'tenantId' => $tenant['environment']->id,
        // A workspace: an organization in the platform's own environment.
        'workspaceId' => $tenant['organization']->id,
    ];
}

/** A client row written straight to the table: one that was there before the rule. */
function prcClientRow(string $environmentId, ?string $organizationId): string
{
    $clientId = 'cid_'.Str::lower(Str::random(20));

    DB::table('oauth_clients')->insert([
        'id' => (string) Str::ulid(),
        'environment_id' => $environmentId,
        'client_id' => $clientId,
        'name' => 'Probe '.$clientId,
        'type' => 'public',
        'redirect_uris' => json_encode(['https://example.test/cb']),
        'grant_types' => json_encode(['authorization_code']),
        'scopes' => json_encode(['openid']),
        'first_party' => false,
        'organization_id' => $organizationId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $clientId;
}

function prcServes(Request $request): bool
{
    try {
        app(RefuseCustomerClientsInPlatformRealm::class)->handle($request, fn () => new Response('ok'));

        return true;
    } catch (NotFoundHttpException) {
        return false;
    }
}

it('refuses to register an organization\'s client in the platform realm, whoever asks', function (): void {
    ['workspaceId' => $workspaceId] = prcDeployment();

    $register = fn (?string $organizationId) => app(PlatformRoot::class)->run(
        fn () => app(ClientRegistry::class)->register(new NewClient(name: 'Probe', organizationId: $organizationId)),
    );

    expect(fn () => $register($workspaceId))->toThrow(AuthorizationException::class);

    // The platform's own client, owned by the environment, is what the realm is for.
    expect($register(null)->client->organization_id)->toBeNull();
})->group('security');

it('does not let an existing platform client be handed to an organization', function (): void {
    ['rootId' => $rootId, 'workspaceId' => $workspaceId] = prcDeployment();

    $clientId = prcClientRow($rootId, null);

    app(PlatformRoot::class)->run(function () use ($clientId, $workspaceId): void {
        $client = Client::query()->where('client_id', $clientId)->sole();
        $client->organization_id = $workspaceId;

        expect(fn () => $client->save())->toThrow(AuthorizationException::class);
    });
})->group('security');

it('leaves an organization\'s client that is already there open to cleanup', function (): void {
    ['rootId' => $rootId, 'workspaceId' => $workspaceId] = prcDeployment();

    $clientId = prcClientRow($rootId, $workspaceId);

    app(PlatformRoot::class)->run(function () use ($clientId): void {
        $client = Client::query()->where('client_id', $clientId)->sole();
        $client->name = 'Renamed before removal';
        $client->save();
        $client->delete();
    });

    expect(DB::table('oauth_clients')->where('client_id', $clientId)->exists())->toBeFalse();
});

it('leaves a customer\'s own environment alone', function (): void {
    ['tenantId' => $tenantId] = prcDeployment();

    $registered = app(EnvironmentContext::class)->runAs(GenericEnvironment::of($tenantId), function () {
        $organization = app(Organizations::class)
            ->create(new NewOrganization('Customer Co', 'customer-co'));

        return app(ClientRegistry::class)->register(new NewClient(name: 'Their app', organizationId: $organization->id));
    });

    expect($registered->client->organization_id)->not->toBeNull()
        ->and(app(PlatformRealmClients::class)->refusesToServe($registered->client->client_id))->toBeFalse();
});

it('does not serve an organization\'s client in the platform realm on /oauth, however it names itself', function (): void {
    ['rootId' => $rootId, 'tenantId' => $tenantId, 'workspaceId' => $workspaceId] = prcDeployment();

    $theirs = prcClientRow($rootId, $workspaceId);
    $ours = prcClientRow($rootId, null);
    $tenantApp = prcClientRow($tenantId, 'org_of_a_tenant');

    $assertion = 'e30.'.rtrim(strtr(base64_encode((string) json_encode(['sub' => $theirs, 'iss' => $theirs])), '+/', '-_'), '=').'.sig';

    expect(prcServes(Request::create('https://login.example/oauth/authorize?client_id='.$theirs)))->toBeFalse()
        ->and(prcServes(Request::create('https://login.example/oauth/token', 'POST', ['client_id' => $theirs])))->toBeFalse()
        ->and(prcServes(Request::create('https://login.example/oauth/token', 'POST', [], [], [], ['PHP_AUTH_USER' => $theirs, 'PHP_AUTH_PW' => 'x'])))->toBeFalse()
        ->and(prcServes(Request::create('https://login.example/oauth/token', 'POST', ['client_assertion' => $assertion])))->toBeFalse()
        // The platform's own client, a tenant's client, and a request that names none.
        ->and(prcServes(Request::create('https://login.example/oauth/authorize?client_id='.$ours)))->toBeTrue()
        ->and(prcServes(Request::create('https://acme.example/oauth/authorize?client_id='.$tenantApp)))->toBeTrue()
        ->and(prcServes(Request::create('https://login.example/oauth/token', 'POST')))->toBeTrue()
        // Not an OAuth path: nothing is asked.
        ->and(prcServes(Request::create('https://login.example/login?client_id='.$theirs)))->toBeTrue();
})->group('security');

it('answers 404 on the real route for such a client', function (): void {
    ['rootId' => $rootId, 'workspaceId' => $workspaceId] = prcDeployment();

    $theirs = prcClientRow($rootId, $workspaceId);

    $this->post('https://cboxid.com/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $theirs])
        ->assertNotFound();
})->group('security');

it('refuses apps.create for an organization in the platform realm, for the API and MCP alike', function (): void {
    ['workspaceId' => $workspaceId] = prcDeployment();

    // The action every door runs (console, management API, MCP), as a principal that may
    // act for the whole environment and as a person confined to their organization.
    $create = function (?string $confinedTo, ?string $organizationId): mixed {
        $principal = Mockery::mock(Principal::class);
        $principal->shouldReceive('confinedToOrganization')->andReturn($confinedTo);

        return app(PlatformRoot::class)->run(fn () => app(CreateApp::class)->handle(new ActionContext($principal, [
            'name' => 'Customer app',
            'type' => 'web',
            'redirect_uris' => ['https://app.example/cb'],
            'organization_id' => $organizationId,
        ])));
    };

    foreach ([null, $workspaceId] as $confinedTo) {
        try {
            $create($confinedTo, $workspaceId);
            $this->fail('An organization\'s app was registered in the platform realm.');
        } catch (ActionRefused $refused) {
            expect($refused->error)->toBe('organization_apps_not_allowed');
        }
    }

    expect(Client::query()->withoutGlobalScopes()->where('name', 'Customer app')->exists())->toBeFalse();
})->group('security');

it('changes nothing while the rule is off, and nothing on a single-tenant install', function (): void {
    ['rootId' => $rootId, 'workspaceId' => $workspaceId] = prcDeployment(ruleOn: false);

    $theirs = prcClientRow($rootId, $workspaceId);

    expect(app(PlatformRealmClients::class)->enabled())->toBeFalse()
        ->and(prcServes(Request::create('https://login.example/oauth/authorize?client_id='.$theirs)))->toBeTrue();

    app(PlatformRoot::class)->run(
        fn () => app(ClientRegistry::class)->register(new NewClient(name: 'Probe', organizationId: $workspaceId)),
    );

    // On, but single-tenant: the default environment is the product itself.
    config(['cbox-id.realm.platform_clients_only' => true, 'cbox-id.tenancy.multi_tenant' => false]);

    expect(app(PlatformRealmClients::class)->enabled())->toBeFalse();
});
