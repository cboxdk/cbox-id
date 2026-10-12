<?php

declare(strict_types=1);

use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Actions\Principal\EnvironmentMemberPrincipal;
use App\Platform\Actions\Principal\RootPersonPrincipal;
use App\Platform\OAuth\RootDelegatedAccess;
use App\Support\CliClient;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\ProtectedResources;
use Cbox\Id\OAuthServer\Contracts\TokenIssuer;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\Testing\InteractsWithOAuth;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\ValueObjects\KeyProvenance;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| EVERY ENVIRONMENT ACTION RUNS IN THE PLATFORM ROOT'S ENVIRONMENT, FOR ITS OPERATORS
|--------------------------------------------------------------------------
|
| The API half of the parity the console test holds: whatever a workspace's team may do
| to one of their environments with a token signed in at the root, a platform operator may
| do to the platform's own environment — over REST with `Cbox-Environment: <root>`, and
| over MCP with the `environment` argument — and a workspace owner who is not an operator
| may not. Enumerated from the action registry, so an action added tomorrow is held to it.
*/

uses(InteractsWithOAuth::class);

beforeEach(function (): void {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    Mail::fake();

    multiTenantDeployment((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    $this->account = provisionAccount('owner@acme.example');
    $this->operator = actAsOperator('ops@platform.test');
    $this->root = app(PlatformRoot::class)->model();
});

/** The root's CLI client — platform-owned and first-party, as the installer provisions it. */
function parityCliClient(): Client
{
    return app(PlatformRoot::class)->run(fn (): Client => CliClient::provision(app(ClientRegistry::class))[0])
        ?? throw new LogicException('no platform root');
}

/** A token the root issues $subjectId for its `/mcp`, with every scope that resource accepts. */
function parityToken(string $subjectId): string
{
    $client = parityCliClient();

    return app(PlatformRoot::class)->run(function () use ($client, $subjectId): string {
        $resource = app(ProtectedResources::class)->forMetadataPath('/.well-known/oauth-protected-resource/mcp');

        return app(TokenIssuer::class)->issueForUser($client, $subjectId, null, ['openid', ...($resource->scopes ?? [])], $resource?->identifier)->token;
    }) ?? throw new LogicException('no platform root');
}

/** The root principal a token stands for, as the REST and MCP doors read it. */
function parityPrincipal(string $token): RootPersonPrincipal
{
    $request = Request::create('/mcp', 'POST');
    $request->headers->set('Authorization', 'Bearer '.$token);

    return app(RootDelegatedAccess::class)->principal($request) ?? throw new LogicException('token not taken');
}

/** Whether $bound may run $action — the principal's own answer, the one the runner asks. */
function parityMayRun(EnvironmentMemberPrincipal $bound, ActionDefinition $action): bool
{
    try {
        $bound->authorize($action);

        return true;
    } catch (AuthorizationException) {
        return false;
    }
}

/** @return list<ActionDefinition> */
function environmentActions(): array
{
    return app(ActionRegistry::class)->forPlane(ActionPlane::Environment);
}

it('authorizes every environment action in the root environment for an operator exactly as in a tenant\'s for its owner', function (): void {
    $owner = parityPrincipal(parityToken($this->account['subjectId']))->inEnvironment((string) $this->account['environment']->id);
    $operator = parityPrincipal(parityToken((string) $this->operator->subject_id))->inEnvironment((string) $this->root?->slug);

    expect($operator->onPlatformRoot())->toBeTrue();

    $differ = [];
    $runnable = 0;

    foreach (environmentActions() as $action) {
        $onTenant = parityMayRun($owner, $action);
        $onRoot = parityMayRun($operator, $action);
        $runnable += $onRoot ? 1 : 0;

        if ($onTenant !== $onRoot) {
            $differ[] = "{$action->name}: tenant ".($onTenant ? 'yes' : 'no').', root '.($onRoot ? 'yes' : 'no');
        }
    }

    expect($differ)->toBe([], implode("\n", $differ))
        ->and($runnable)->toBeGreaterThan(150);
});

it('lists every environment tool the owner gets to an operator, taking the root as its environment', function (): void {
    $owner = array_keys(array_filter(mcpTools(parityToken($this->account['subjectId'])), static fn (array $tool): bool => in_array('environment', $tool['inputSchema']['required'] ?? [], true)));
    $operatorTools = mcpTools(parityToken((string) $this->operator->subject_id));
    $operator = array_keys(array_filter($operatorTools, static fn (array $tool): bool => in_array('environment', $tool['inputSchema']['required'] ?? [], true)));

    sort($owner);
    sort($operator);

    expect($operator)->toBe($owner)
        ->and($operator)->toContain('signin_social_set', 'signin_policy_update', 'branding_appearance_set', 'apis_create', 'webhooks_create', 'keys_create');

    // …and one of them run there, by slug: the platform's own environment's APIs.
    $listed = mcpCall(parityToken((string) $this->operator->subject_id), 'apis_list', ['environment' => (string) $this->root?->slug]);

    expect($listed['isError'] ?? false)->toBeFalse();
});

it('routes every environment action to the root environment over REST for an operator', function (): void {
    $token = parityToken((string) $this->operator->subject_id);
    $headers = ['Cbox-Environment' => (string) $this->root?->slug];
    $refused = [];

    foreach (environmentActions() as $action) {
        if (! EnvironmentMemberPrincipal::consoleRuns($action)) {
            continue;
        }

        $path = '/api/v1'.preg_replace('/\{[^}]+\}/', '01JPARITYPROBE000000000000', $action->path);
        $response = $this->withToken($token)->json($action->method, $path, [], $headers);
        $status = $response->status();

        // Routed and authorized: anything but the door's own refusals. A 404 is the probe
        // id not existing — unless it is the ENVIRONMENT that was not found.
        if (in_array($status, [400, 401, 403, 405], true)
            || ($status === 404 && $response->json('message') === 'Environment not found.')
            || $status >= 500) {
            $refused[] = "{$action->method} {$path} ({$action->name}) → {$status} ".(string) $response->json('message');
        }
    }

    expect($refused)->toBe([], implode("\n", $refused));
});

it('holds every write an operator\'s token makes on the root environment for their approval', function (): void {
    $token = parityToken((string) $this->operator->subject_id);

    // A Write in any tenant (`apis.create`), held here: the root is every customer's sign-in.
    $this->withToken($token)
        ->postJson('/api/v1/apis', ['identifier' => 'https://api.platform.example', 'name' => 'Platform API'], ['Cbox-Environment' => (string) $this->root?->slug])
        ->assertStatus(202)
        ->assertJsonPath('error', 'approval_required');

    // Reads are not held.
    $this->withToken($token)
        ->getJson('/api/v1/apis', ['Cbox-Environment' => (string) $this->root?->slug])
        ->assertOk();
});

it('refuses the root environment to a workspace owner who is not an operator', function (): void {
    $token = parityToken($this->account['subjectId']);

    $this->withToken($token)
        ->getJson('/api/v1/apis', ['Cbox-Environment' => (string) $this->root?->slug])
        ->assertNotFound();

    $this->withToken($token)
        ->getJson('/api/v1/apis', ['Cbox-Environment' => (string) $this->root?->id])
        ->assertNotFound();

    expect(mcpCall($token, 'apis_list', ['environment' => (string) $this->root?->slug])['structuredContent']['error'] ?? null)->toBe('not_found');
})->group('security');

it('names the root environment among the ones an operator may act in', function (): void {
    $me = mcpCall(parityToken((string) $this->operator->subject_id), 'whoami')['structuredContent'];

    expect(array_column($me['environments'], 'slug'))->toContain((string) $this->root?->slug)
        ->and($me['operator'])->toBeTrue();
});

it('lets a key of the root environment act on the root host, holding its writes for its maker', function (): void {
    // A management key for the platform's own environment, minted by an operator in its
    // console — the same KeyLifecycle a tenant's keys come from.
    $minted = app(EnvironmentApiKeys::class)->issue(
        (string) $this->root?->id,
        'Platform automation',
        ['apis:read', 'apis:write'],
        provenance: new KeyProvenance(createdByType: 'organization_member', createdById: (string) $this->operator->subject_id),
    );

    // On the root's own host — the environment it was minted for — and nowhere else.
    $this->withToken($minted->plaintext)->getJson('/api/v1/apis')->assertOk();
    $this->withToken($minted->plaintext)->postJson('/api/v1/apis', ['identifier' => 'https://k.platform.example', 'name' => 'K'])
        ->assertStatus(202)
        ->assertJsonPath('error', 'approval_required');
});
