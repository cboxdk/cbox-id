<?php

declare(strict_types=1);

use App\Platform\Apis\ApiAudit;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\CurrentUser;
use App\Platform\Entitlements;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\PlaneResolver;
use App\Platform\PlatformAuth;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\OAuthServer\Contracts\Apis;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Api;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\ApiScopeDefinition;
use Cbox\Id\OAuthServer\ValueObjects\NewApi;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| Developers › APIs, and the scopes an organization's app may not squat
|--------------------------------------------------------------------------
| An API's identifier and its scope keys are first come per environment, so the APIs are
| registered from the environment console only. The half that matters is what that buys:
| once the environment has registered `tax:assess` and kept it for its own apps, an
| organization's administrator cannot get it onto their app through any console door —
| not by ticking it, not by typing it, not by registering a new app with it.
*/

beforeEach(function (): void {
    installedDeployment();
});

/**
 * Register an API the way the console's form does: every field.
 *
 * @param  array<string, mixed>  $changes
 */
function registerApiInConsole(array $changes = []): TestResponse
{
    return test()->from(route('environment.apis.create'))->post(route('environment.apis.store'), [
        'name' => 'Tax',
        'identifier' => 'https://tax.example.com',
        'owner' => 'environment',
        'clientId' => '',
        ...$changes,
    ]);
}

function defineApiScopeInConsole(Api $api, string $key, bool $requestable, ?string $description = null): TestResponse
{
    return test()->from(route('environment.apis.show', $api->id))->post(route('environment.apis.scopes.store', $api->id), [
        'key' => $key,
        'description' => $description ?? '',
        'tenantRequestable' => $requestable,
    ]);
}

/** The Tax API, through the console: `tax:read` for everyone, `tax:assess` kept back. */
function taxApiThroughConsole(): Api
{
    registerApiInConsole()->assertSessionHasNoErrors();

    $api = Api::query()->where('identifier', 'https://tax.example.com')->firstOrFail();

    defineApiScopeInConsole($api, 'tax:read', true, 'Read returns')->assertSessionHasNoErrors();
    defineApiScopeInConsole($api, 'tax:assess', false, 'Assess returns')->assertSessionHasNoErrors();

    return $api->refresh();
}

/** @return list<string> */
function apiAuditActions(string $identifier): array
{
    return array_values(AuditEntry::query()
        ->where('target_type', 'api')
        ->where('target_id', $identifier)
        ->orderBy('sequence')
        ->pluck('action')
        ->all());
}

it('registers an API and the scopes it owns from the environment console, on the trail', function (): void {
    ['subjectId' => $adminId] = crudSetup();

    $api = taxApiThroughConsole();

    expect($api->organization_id)->toBeNull()
        ->and($api->scopes->pluck('tenant_requestable', 'key')->all())->toBe(['tax:assess' => false, 'tax:read' => true])
        ->and(apiAuditActions('https://tax.example.com'))->toBe([ApiAudit::CREATED, ApiAudit::SCOPE_DEFINED, ApiAudit::SCOPE_DEFINED]);

    $entry = AuditEntry::query()->where('action', ApiAudit::SCOPE_DEFINED)->where('context->scope', 'tax:assess')->sole();

    expect($entry->actor_type)->toBe(ActorType::OrganizationMember)
        ->and($entry->actor_id)->toBe($adminId)
        ->and($entry->organization_id)->toBeNull()
        ->and($entry->context['tenant_requestable'] ?? null)->toBeFalse();

    // The list and the page render what was registered.
    $rows = collect((array) test()->get(route('environment.apis'))->assertOk()->inertiaProps('apis'));

    expect($rows->pluck('identifier')->all())->toBe(['https://tax.example.com'])
        ->and($rows->first()['scopeCount'] ?? null)->toBe(2)
        ->and($rows->first()['owner'] ?? null)->toBe('This environment');

    $scopes = collect((array) test()->get(route('environment.apis.show', $api->id))->assertOk()->inertiaProps('scopes'));

    expect($scopes->pluck('key')->all())->toBe(['tax:assess', 'tax:read']);
});

it('changes a scope\'s description and who may request it, and records what it was', function (): void {
    crudSetup();
    $api = taxApiThroughConsole();
    $assess = $api->scopes->firstWhere('key', 'tax:assess');

    test()->from(route('environment.apis.show', $api->id))
        ->patch(route('environment.apis.scopes.update', [$api->id, $assess?->id]), [
            'description' => 'Assess a return',
            'tenantRequestable' => true,
        ])
        ->assertSessionHasNoErrors();

    expect($assess?->refresh()->tenant_requestable)->toBeTrue()
        ->and($assess?->description)->toBe('Assess a return');

    $entry = AuditEntry::query()->where('action', ApiAudit::SCOPE_DEFINED)->orderByDesc('sequence')->first();

    expect($entry?->context['from'] ?? null)->toBe(['description' => 'Assess returns', 'tenant_requestable' => false]);
});

it('keeps an API\'s identifier as it was registered', function (): void {
    crudSetup();
    $api = taxApiThroughConsole();

    // Every token already minted carries it; nothing on the page edits it, and a crafted
    // field is not read.
    test()->from(route('environment.apis.show', $api->id))
        ->patch(route('environment.apis.update', $api->id), [
            'name' => 'Tax (EU)',
            'identifier' => 'https://evil.example.com',
            'clientId' => '',
        ])
        ->assertSessionHasNoErrors();

    expect($api->refresh()->identifier)->toBe('https://tax.example.com')
        ->and($api->name)->toBe('Tax (EU)')
        ->and(apiAuditActions('https://tax.example.com'))->toContain(ApiAudit::UPDATED);
});

it('refuses an identifier that is not an absolute URL, or is already registered', function (): void {
    crudSetup();
    taxApiThroughConsole();

    registerApiInConsole(['identifier' => 'tax-api'])
        ->assertSessionHasErrors(['identifier' => 'Use an absolute URL with a host and no fragment, at most 255 characters — for example https://api.example.com.']);

    registerApiInConsole(['name' => 'Second tax'])
        ->assertSessionHasErrors(['identifier' => 'An API with this identifier is already registered in this environment.']);

    expect(Api::query()->count())->toBe(1);
});

it('refuses a scope key another API owns, one it already owns, and a sign-in scope', function (): void {
    crudSetup();
    $tax = taxApiThroughConsole();

    registerApiInConsole(['name' => 'Books', 'identifier' => 'https://books.example.com'])->assertSessionHasNoErrors();
    $books = Api::query()->where('identifier', 'https://books.example.com')->firstOrFail();

    defineApiScopeInConsole($books, 'tax:read', true)
        ->assertSessionHasErrors(['key' => '"tax:read" already belongs to another API in this environment. A token request names a scope by its key alone, so each key can belong to one API.']);

    defineApiScopeInConsole($tax, 'tax:read', true)
        ->assertSessionHasErrors(['key' => 'This API already owns "tax:read". Change it in the list below.']);

    defineApiScopeInConsole($books, 'openid', true)
        ->assertSessionHasErrors(['key' => '"openid" is a sign-in scope Cbox ID defines itself, so no API can own it.']);

    expect($books->refresh()->scopes)->toHaveCount(0);
});

it('links an API only to an app with the same owner', function (): void {
    crudSetup();
    $organization = app(Organizations::class)->create(new NewOrganization('Acme Retail', 'acme-retail'));

    $theirs = app(ClientRegistry::class)->register(new NewClient(
        name: 'Retail portal',
        type: ClientType::Confidential,
        redirectUris: ['https://retail.example/cb'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
        organizationId: $organization->id,
    ))->client;

    // An organization's app named as the enforcer of the environment's own API would
    // stamp that organization's roles into every token for it.
    registerApiInConsole(['clientId' => $theirs->client_id])
        ->assertSessionHasErrors(['clientId' => 'Link an app with the same owner as the API. Tokens for this API carry the linked app\'s roles and permissions, so it has to be the owner\'s own app.']);

    expect(Api::query()->count())->toBe(0);
});

it('registers an API for the organization being administered, and for no other', function (): void {
    crudSetup();
    $acme = app(Organizations::class)->create(new NewOrganization('Acme Retail', 'acme-retail'));
    $globex = app(Organizations::class)->create(new NewOrganization('Globex', 'globex'));

    test()->post(route('environment.acting-organization.choose'), ['organization' => $acme->id]);

    // The create page offers the environment and the organization being acted on.
    $owners = collect((array) test()->get(route('environment.apis.create'))->assertOk()->inertiaProps('owners'));

    expect($owners->pluck('value')->all())->toBe(['environment', $acme->id]);

    // A crafted owner — another organization than the one chosen — is refused.
    registerApiInConsole(['owner' => $globex->id])
        ->assertSessionHasErrors(['owner' => 'Choose the environment, or the organization you are acting on.']);

    registerApiInConsole(['owner' => $acme->id, 'identifier' => 'https://books.acme.example'])->assertSessionHasNoErrors();

    expect(Api::query()->sole()->organization_id)->toBe($acme->id);

    // On the owning organization's trail, so its administrators see it.
    expect(AuditEntry::query()->where('action', ApiAudit::CREATED)->sole()->organization_id)->toBe($acme->id);
});

it('removes a scope and deletes an API, recording both', function (): void {
    crudSetup();
    $api = taxApiThroughConsole();
    $assess = $api->scopes->firstWhere('key', 'tax:assess');

    test()->from(route('environment.apis.show', $api->id))
        ->delete(route('environment.apis.scopes.destroy', [$api->id, $assess?->id]))
        ->assertSessionHasNoErrors();

    expect($api->refresh()->scopes->pluck('key')->all())->toBe(['tax:read']);

    test()->delete(route('environment.apis.destroy', $api->id))->assertRedirect(route('environment.apis'));

    expect(Api::query()->count())->toBe(0)
        ->and(apiAuditActions('https://tax.example.com'))->toContain(ApiAudit::SCOPE_REMOVED, ApiAudit::DELETED);
});

it('does not let one API\'s URL edit another API\'s scope', function (): void {
    crudSetup();
    $tax = taxApiThroughConsole();
    registerApiInConsole(['name' => 'Books', 'identifier' => 'https://books.example.com'])->assertSessionHasNoErrors();
    $books = Api::query()->where('identifier', 'https://books.example.com')->firstOrFail();
    $assess = $tax->scopes->firstWhere('key', 'tax:assess');

    test()->patch(route('environment.apis.scopes.update', [$books->id, $assess?->id]), [
        'description' => 'hijacked',
        'tenantRequestable' => true,
    ])->assertNotFound();

    test()->delete(route('environment.apis.scopes.destroy', [$books->id, $assess?->id]))->assertNotFound();

    expect($assess?->refresh()->tenant_requestable)->toBeFalse()
        ->and($assess?->description)->toBe('Assess returns');
})->group('security');

/*
| Only the environment registers APIs
*/

it('has no APIs page on the organization console', function (): void {
    actingAsRole(MembershipRole::Owner);

    expect(Route::has('apis'))->toBeFalse()
        ->and(Route::has('apis.store'))->toBeFalse();

    // The environment console's door does not open for an organization's administrator.
    test()->post('/admin/apis', [
        'name' => 'Squat',
        'identifier' => 'https://tax.example.com',
        'owner' => 'environment',
    ]);

    expect(Api::query()->count())->toBe(0);
})->group('security');

/**
 * THE CONTROLLER'S OWN GUARD, with the route's gate taken out of the picture.
 *
 * The routes sit behind the environment-admin gate, so an organization's administrator
 * never reaches the controller, and a test through that gate alone can never watch the
 * controller's check fail — it would pass with the check deleted. So the console is told
 * it is the ORGANIZATION console while an environment-admin session is live, which is
 * exactly the request the controller must still refuse: the APIs are the environment's,
 * whatever door a future route change opens onto them.
 */
it('refuses the APIs area to any request the console resolves as an organization\'s', function (): void {
    crudSetup();

    $scope = Mockery::mock(ConsoleScope::class, [
        app(CurrentUser::class),
        app(EnvironmentAdminAuth::class),
        app(Entitlements::class),
        app(PlatformOperators::class),
        app(PlaneResolver::class),
    ])->makePartial();
    $scope->shouldReceive('plane')->andReturn(ConsolePlane::Organization);
    app()->instance(ConsoleScope::class, $scope);

    test()->get(route('environment.apis'))->assertForbidden();
    registerApiInConsole()->assertForbidden();

    expect(Api::query()->count())->toBe(0);
})->group('security');

/*
| The squat, end to end
*/

it('keeps a scope the environment kept for itself off an organization\'s app, through every door', function (): void {
    crudSetup();
    $api = taxApiThroughConsole();

    // …and now an organization's administrator, on their own console, with their own app.
    [, $org] = actingAsRole(MembershipRole::Owner);
    confirmConsoleStepUp();

    $app = app(ClientRegistry::class)->register(new NewClient(
        name: 'Retail back office',
        type: ClientType::Confidential,
        redirectUris: ['https://retail.example/cb'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
        organizationId: $org->id,
    ))->client;

    // 1. THE PICKER offers the scope the environment lets every organization request, and
    //    not the one it kept.
    $groups = collect((array) test()->get(route('clients.scopes', $app->id))->assertOk()->inertiaProps('apiGroups'));

    expect($groups->pluck('identifier')->all())->toBe(['https://tax.example.com'])
        ->and(collect($groups->first()['scopes'] ?? [])->pluck('key')->all())->toBe(['tax:read']);

    $refusal = 'This app may not hold that scope. "tax:assess" belongs to the Tax API (https://tax.example.com), which keeps it for this environment\'s own apps. Remove it, or ask the environment\'s administrators to let organizations\' apps request it.';

    // 2. TYPED under Advanced — the free-text field that used to be the way in.
    test()->from(route('clients.scopes', $app->id))
        ->put(route('clients.scopes.update', $app->id), ['scopes' => ['openid'], 'customScopes' => 'tax:assess'])
        ->assertSessionHasErrors(['scopes' => $refusal]);

    // 3. TICKED — a box the page never drew, in a crafted request.
    test()->from(route('clients.scopes', $app->id))
        ->put(route('clients.scopes.update', $app->id), ['scopes' => ['openid', 'tax:assess'], 'customScopes' => ''])
        ->assertSessionHasErrors(['scopes' => $refusal]);

    expect($app->refresh()->scopes)->toBe(['openid']);

    // 4. A NEW APP registered with it.
    registerApp(['name' => 'Squatter', 'redirectUris' => 'https://retail.example/cb', 'customScopes' => 'tax:assess'])
        ->assertSessionHasErrors(['customScopes' => $refusal]);

    expect(Client::query()->where('name', 'Squatter')->exists())->toBeFalse();

    // …while the scope the environment offers is theirs to take, and the page says where
    // the app's tokens will now go.
    test()->from(route('clients.scopes', $app->id))
        ->put(route('clients.scopes.update', $app->id), ['scopes' => ['openid', 'tax:read'], 'customScopes' => ''])
        ->assertSessionHasNoErrors();

    $audience = (array) test()->get(route('clients.scopes', $app->id))->assertOk()->inertiaProps('audience');

    expect($app->refresh()->scopes)->toBe(['openid', 'tax:read'])
        ->and($audience['shape'] ?? null)->toBe('api')
        ->and($audience['identifiers'] ?? null)->toBe(['https://tax.example.com'])
        ->and($audience['withIssuer'] ?? null)->toBeTrue();

    expect($api->refresh()->scopes)->toHaveCount(2);
})->group('security');

it('never shows an organization another organization\'s API', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $neighbour = app(Organizations::class)->create(new NewOrganization('Globex', 'globex'));

    app(Apis::class)->register(new NewApi(
        identifier: 'https://books.globex.example',
        name: 'Globex books',
        organizationId: $neighbour->id,
        scopes: [new ApiScopeDefinition('books:read')],
    ));
    app(Apis::class)->register(new NewApi(
        identifier: 'https://books.acme.example',
        name: 'Acme books',
        organizationId: $org->id,
        scopes: [new ApiScopeDefinition('acme-books:read')],
    ));

    $app = app(ClientRegistry::class)->register(new NewClient(
        name: 'Portal',
        type: ClientType::Confidential,
        redirectUris: ['https://acme.example/cb'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
        organizationId: $org->id,
    ))->client;

    $response = test()->get(route('clients.scopes', $app->id))->assertOk();

    expect(collect((array) $response->inertiaProps('apiGroups'))->pluck('name')->all())->toBe(['Acme books'])
        ->and((string) $response->getContent())->not->toContain('Globex');

    // Typed, the neighbour's scope is refused without naming whose it is.
    test()->from(route('clients.scopes', $app->id))
        ->put(route('clients.scopes.update', $app->id), ['scopes' => ['openid'], 'customScopes' => 'books:read'])
        ->assertSessionHasErrors(['scopes' => 'This app may not hold that scope. "books:read" belongs to an API another organization owns, and only that organization\'s apps may hold it. Remove it, or ask the environment\'s administrators to let organizations\' apps request it.']);
})->group('security');

it('says when an app holds scopes of two APIs that it has to name one', function (): void {
    crudSetup();
    taxApiThroughConsole();
    registerApiInConsole(['name' => 'Books', 'identifier' => 'https://books.example.com'])->assertSessionHasNoErrors();
    defineApiScopeInConsole(Api::query()->where('identifier', 'https://books.example.com')->firstOrFail(), 'books:read', true)
        ->assertSessionHasNoErrors();

    $app = app(ClientRegistry::class)->register(new NewClient(
        name: 'Back office',
        type: ClientType::Confidential,
        redirectUris: [],
        grantTypes: ['client_credentials'],
        scopes: ['tax:assess', 'books:read', 'reports.export'],
    ))->client;

    $audience = (array) test()->get(route('environment.clients.scopes', $app->id))->assertOk()->inertiaProps('audience');

    expect($audience)->toMatchArray([
        'shape' => 'several',
        'identifiers' => ['https://books.example.com', 'https://tax.example.com'],
        'withIssuer' => false,
        'unowned' => ['reports.export'],
        'refused' => [],
    ]);

    // The environment's own app is offered every scope, the kept one included.
    $offered = collect((array) test()->get(route('environment.clients.scopes', $app->id))->inertiaProps('apiGroups'))
        ->flatMap(fn (array $group): array => array_column($group['scopes'], 'key'))
        ->sort()
        ->values()
        ->all();

    expect($offered)->toBe(['books:read', 'tax:assess', 'tax:read']);
});

it('is reached only through the session it was minted for', function (): void {
    crudSetup();
    taxApiThroughConsole();

    session()->forget(PlatformAuth::SESSION_KEY);

    expect(test()->get(route('environment.apis'))->status())->not->toBe(200);
})->group('security');
