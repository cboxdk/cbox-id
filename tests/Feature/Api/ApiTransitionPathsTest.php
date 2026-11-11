<?php

declare(strict_types=1);

use App\Http\Middleware\InClusterOnly;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Api\ApiHost;
use App\Platform\Connect\ActionSnippets;
use App\Platform\Environments\ReservedEnvironmentSlugs;
use App\Support\CliClient;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentResolver;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Models\Environment;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

/*
| The paths that move with step 5a, each served next to the one it replaces: `/internal/v1/*`
| (inside the cluster only) next to `/health/*` and the old eligibility check, `/webhooks/*`
| next to the old inbound paths — and the code that wrote `/api/v1` into a URL by hand.
*/

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

it('serves /internal/v1/* only behind the in-cluster guard', function (): void {
    $internal = array_filter(Route::getRoutes()->getRoutes(), static fn (LaravelRoute $route): bool => str_starts_with($route->uri(), 'internal/'));

    expect(array_map(static fn (LaravelRoute $route): string => $route->uri(), $internal))
        ->toContain('internal/v1/health/ready', 'internal/v1/health/startup', 'internal/v1/health/status', 'internal/v1/metrics', 'internal/v1/onboarding/eligibility')
        ->and(array_filter($internal, static fn (LaravelRoute $route): bool => str_starts_with($route->uri(), 'internal/v1/queue-metrics')))->not->toBeEmpty();

    foreach ($internal as $route) {
        expect(in_array('internal', $route->middleware(), true))->toBeTrue("{$route->uri()} must sit behind the in-cluster guard");
    }
})->group('security');

it('refuses an internal path that came through the edge in production', function (): void {
    $this->app->detectEnvironment(static fn (): string => 'production');

    try {
        $this->postJson('/internal/v1/onboarding/eligibility', [], ['CF-Connecting-IP' => '203.0.113.9'])
            ->assertNotFound();
    } finally {
        $this->app->detectEnvironment(static fn (): string => 'testing');
    }

    expect(InClusterOnly::fromTheEdge(Request::create('/', server: ['HTTP_CF_RAY' => 'x'])))->toBeTrue()
        ->and(InClusterOnly::fromTheEdge(Request::create('/')))->toBeFalse();
})->group('security');

it('checks onboarding eligibility on the old and the new path, with either name for the organization', function (string $url, string $field): void {
    multiTenantDeployment();
    $tenant = provisionAccount();
    config(['onboarding.eligibility_key' => str_repeat('s', 48), 'ai-assistant-connector.platform.url' => 'https://assistant.example', 'ai-assistant-connector.api_key' => 'test-key', 'ai-assistant-connector.support.assistant' => 'id']);
    Http::fake(['https://assistant.example/*' => Http::response(['data' => ['paused' => false]])]);

    $payload = [$field => $tenant['organization']->id, 'subject_id' => $tenant['subjectId'],
        'project_id' => $tenant['project']->id, 'environment_id' => $tenant['environment']->id, 'check' => 'cbox_id_first_sign_in_pending'];

    $this->postJson($url, $payload)->assertUnauthorized();

    app(EnvironmentContext::class)->runAs($tenant['environment'], fn () => app(ClientRegistry::class)->register(new NewClient('App')));

    $this->withToken(str_repeat('s', 48))->postJson($url, $payload)->assertOk()->assertJsonPath('eligible', true);
    $this->withToken(str_repeat('s', 48))->postJson($url, [...$payload, $field => 'foreign'])->assertOk()->assertJsonPath('eligible', false);
})->with([
    'old path, workspace_id' => ['/api/v1/onboarding/eligibility', 'workspace_id'],
    'old path, organization_id' => ['/api/v1/onboarding/eligibility', 'organization_id'],
    'new path, workspace_id' => ['/internal/v1/onboarding/eligibility', 'workspace_id'],
    'new path, organization_id' => ['/internal/v1/onboarding/eligibility', 'organization_id'],
]);

it('takes inbound webhooks at /webhooks/* beside the old paths, with the same handler', function (): void {
    $pairs = [
        'webhooks/postal/{server?}' => 'postal/webhook/{server?}',
        'webhooks/postal/inbound/{server?}' => 'postal/inbound/{server?}',
        'webhooks/cbox-assistant' => 'ai-assistant/hook',
    ];

    $routes = Route::getRoutes();

    foreach ($pairs as $new => $old) {
        $newRoute = collect($routes->getRoutes())->first(fn (LaravelRoute $route): bool => $route->uri() === $new);
        $oldRoute = collect($routes->getRoutes())->first(fn (LaravelRoute $route): bool => $route->uri() === $old);

        expect($newRoute)->not->toBeNull("{$new} is served")
            ->and($oldRoute)->not->toBeNull("{$old} still answers")
            ->and(str_replace('@__invoke', '', (string) $newRoute?->getActionName()))->toBe(str_replace('@__invoke', '', (string) $oldRoute?->getActionName()))
            ->and($newRoute?->middleware())->toBe($oldRoute?->middleware());
    }

    // `/webhooks/postal/inbound` is the inbound endpoint, never a server called "inbound".
    expect($routes->match(Request::create('/webhooks/postal/inbound', 'POST'))->getName())->toBe('webhooks.postal.inbound');
});

it('advertises the API host to the cbox CLI and the Authenticator', function (): void {
    CliClient::provision(app(ClientRegistry::class));

    $this->getJson('/.well-known/cbox-cli')->assertOk()
        ->assertJsonPath('api_base', 'https://'.ApiHost::name().'/v1')
        ->assertJsonPath('resource', 'https://'.ApiHost::name());
});

it('writes API-host URLs into the console\'s API equivalents', function (): void {
    $snippets = app(ActionSnippets::class);
    $registry = app(ActionRegistry::class);

    $users = $snippets->describe($registry->named('users.get'))->toArray();
    expect($users['url'])->toBe('https://'.ApiHost::name().'/v1/users/{id}')
        ->and($users['method'])->toBe('GET');

    // A child is addressed directly; its parent travels in the query.
    $member = $snippets->describe($registry->named('members.update'), ['organization_id' => 'org_1', 'user_id' => 'usr_1'])->toArray();
    expect($member['url'])->toBe('https://'.ApiHost::name().'/v1/organization-memberships/{user_id}')
        ->and($member['base'])->toBe('/v1')
        ->and($member['path'])->toBe('/organization-memberships/usr_1')
        ->and(collect($member['fields'])->firstWhere('name', 'organization_id')['in'])->toBe('query');

    // A PUT that is an update on /v1 is shown as the PATCH it is there.
    $role = $snippets->describe($registry->named('team.environment_access'))->toArray();
    expect($role['method'])->toBe('PATCH')
        ->and($role['url'])->toBe('https://'.ApiHost::name().'/v1/members/{id}');
});

it('never gives an environment a slug the platform\'s own hosts use', function (): void {
    $environment = Environment::query()->create([
        'name' => 'API', 'slug' => 'api', 'type' => EnvironmentType::Production,
        'status' => EnvironmentStatus::Active, 'is_default' => false, 'settings' => [],
    ]);

    expect($environment->slug)->toBe('api-env')
        ->and(ReservedEnvironmentSlugs::reserved('API'))->toBeTrue()
        ->and(app(EnvironmentResolver::class)->resolveForHost('api.cboxid.com'))->toBeNull();

    // Renaming one onto a reserved slug is refused.
    expect(fn () => $environment->update(['slug' => 'app']))->toThrow(ValidationException::class);
    expect($environment->fresh()?->slug)->toBe('api-env');
})->group('security');
