<?php

declare(strict_types=1);

use App\Platform\Api\ApiHost;
use App\Platform\Api\ManagementAudience;
use App\Platform\AppCatalog\AppCatalog;
use App\Platform\AppCatalog\CatalogEntryData;
use App\Support\CliClient;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\ProtectedResources;
use Cbox\Id\OAuthServer\Contracts\TokenIssuer;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;
use Illuminate\Support\Facades\Http;

/*
| THE TOKEN AUDIENCES OF THE MANAGEMENT PLANE (plan §2.5).
|
| A token for `https://api.cboxid.com` or `https://app.cboxid.com/mcp` works on every
| management door, and so does one issued before the change for an issuer's own `/mcp`. The
| audience never says which environment: the issuer does. When the contract step retires
| the legacy audience, such a token is refused with a 401 that says what to ask for instead.
*/

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

function audienceUrl(string $path): string
{
    return 'http://'.ApiHost::name().'/'.ltrim($path, '/');
}

/** The issuer's own `/mcp`: the audience every token before the change carries. */
function legacyAudience(): string
{
    return rtrim(app(IssuerResolver::class)->issuer(), '/').'/mcp';
}

/** @param  list<string>  $scopes */
function audienceClient(array $scopes): Client
{
    return app(ClientRegistry::class)->register(new NewClient(
        name: 'Agent',
        type: ClientType::Public,
        redirectUris: ['http://127.0.0.1:33418/callback'],
        grantTypes: ['authorization_code', 'refresh_token'],
        scopes: ['openid', 'offline_access', ...$scopes],
    ))->client;
}

/** @return array{0: string, 1: string} a person who owns an organization, and that organization */
function audiencePerson(string $email = 'ada@acme.test'): array
{
    $subject = app(Subjects::class)->create($email, 'Ada', 'a-strong-unbreached-passphrase');
    $organization = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-'.substr(md5($email), 0, 8)));
    app(Memberships::class)->add($organization->id, $subject->id, MembershipRole::Owner);

    return [$subject->id, $organization->id];
}

/** @param  list<string>  $scopes */
function audienceToken(string $subjectId, string $organizationId, array $scopes, string $resource, ?Client $client = null): string
{
    return app(TokenIssuer::class)->issueForUser($client ?? audienceClient($scopes), $subjectId, $organizationId, ['openid', ...$scopes], $resource)->token;
}

it('reads the catalog with an app-catalog grant issued before the change, on the API host and on the old path', function (): void {
    [$subject, $organization] = audiencePerson();
    app(AppCatalog::class)->save('billing', CatalogEntryData::fromArray([
        'version' => 0, 'name' => 'Billing', 'launch_url' => 'https://billing.example.com',
        'enabled' => true, 'position' => 0, 'audience' => 'organizations', 'organization_ids' => [$organization],
    ]));

    // The grant every app holds today: `account:apps:read` for the issuer's own `/mcp`.
    $grant = audienceToken($subject, $organization, ['account:apps:read'], legacyAudience());

    $this->withToken($grant)->getJson(audienceUrl('/v1/me/apps'))
        ->assertOk()
        ->assertJsonPath('object', 'list')
        ->assertJsonPath('data.0.key', 'billing');

    $this->withToken($grant)->getJson('/api/v1/me/apps')
        ->assertOk()
        ->assertJsonPath('data.0.key', 'billing')
        ->assertHeader('Deprecation');
});

it('issues and accepts a token for the API host, and keeps it to the issuer\'s environment', function (): void {
    [$subject, $organization] = audiencePerson();
    $own = app(WebhookRegistry::class)->register($organization, 'https://own.acme.example/in', ['user.created'])->endpoint;

    // Another environment's webhook, with an id this one could guess.
    $foreign = app(EnvironmentContext::class)->runAs(GenericEnvironment::of('env_other'), function () {
        $rival = app(Organizations::class)->create(new NewOrganization('Rival', 'rival'));

        return app(WebhookRegistry::class)->register($rival->id, 'https://rival.example/in', ['user.created'])->endpoint;
    });

    // The token endpoint knows the new resource by name.
    expect(app(ProtectedResources::class)->find((string) ManagementAudience::api()))->not->toBeNull();

    $token = audienceToken($subject, $organization, ['webhooks:read'], (string) ManagementAudience::api());

    $this->withToken($token)->getJson(audienceUrl("/v1/webhooks/{$own->id}"))
        ->assertOk()
        ->assertJsonPath('object', 'webhook')
        ->assertJsonPath('id', $own->id);

    $this->withToken($token)->getJson(audienceUrl("/v1/webhooks/{$foreign->id}"))->assertNotFound();

    // One token, both doors: the same token on the old path.
    $this->withToken($token)->getJson("/api/v1/webhooks/{$own->id}")->assertOk();
})->group('security');

it('refuses a token audienced to somebody else\'s resource', function (): void {
    [$subject, $organization] = audiencePerson();
    $token = audienceToken($subject, $organization, ['webhooks:read'], 'https://studio.example.com/mcp');

    $this->withToken($token)->getJson(audienceUrl('/v1/webhooks'))->assertUnauthorized();
})->group('security');

it('retires the legacy audience with a 401 that names the resource to request', function (): void {
    [$subject, $organization] = audiencePerson();
    $legacy = audienceToken($subject, $organization, ['webhooks:read'], legacyAudience());
    $current = audienceToken($subject, $organization, ['webhooks:read'], (string) ManagementAudience::api());

    $this->withToken($legacy)->getJson(audienceUrl('/v1/webhooks'))->assertOk();

    // The contract step.
    config(['cbox-id.api_transition.legacy_mcp_audience' => false]);

    $refused = $this->withToken($legacy)->getJson(audienceUrl('/v1/webhooks'))->assertUnauthorized();

    expect($refused->headers->get('WWW-Authenticate'))
        ->toContain('error="invalid_token"')
        ->toContain('audience '.legacyAudience().' retired; request resource '.ManagementAudience::api())
        ->toContain('resource_metadata="'.ManagementAudience::api().'/.well-known/oauth-protected-resource"');

    $this->withToken($current)->getJson(audienceUrl('/v1/webhooks'))->assertOk();
})->group('security');

it('describes the API host as a protected resource of its own', function (): void {
    multiTenantDeployment((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    platformRootEnvironment();

    $this->getJson(audienceUrl('/.well-known/oauth-protected-resource'))
        ->assertOk()
        ->assertJsonPath('resource', ManagementAudience::api())
        ->assertJsonPath('resource_documentation', 'https://'.ApiHost::name().'/docs')
        ->assertJsonPath('bearer_methods_supported', ['header']);

    expect($this->getJson(audienceUrl('/.well-known/oauth-protected-resource'))->json('authorization_servers'))->toHaveCount(1);
});

/*
| THE ONE MCP SERVER AND THE PLATFORM ROOT'S PEOPLE: a token the root issued the `cbox` CLI
| before the change keeps working on the API host's organization plane and on the
| application host's `/mcp`; an environment key works on that `/mcp` too, in its own
| environment.
*/

/** A token the platform root issued its CLI client, for its own `/mcp`, as before the change. */
function rootCliToken(string $subjectId, ?string $resource = null): string
{
    $client = app(PlatformRoot::class)->run(fn (): Client => CliClient::provision(app(ClientRegistry::class))[0]);

    return app(PlatformRoot::class)->run(function () use ($client, $subjectId, $resource): string {
        $mcp = app(ProtectedResources::class)->forMetadataPath('/.well-known/oauth-protected-resource/mcp');

        return app(TokenIssuer::class)->issueForUser($client, $subjectId, null, ['openid', ...($mcp->scopes ?? [])], $resource ?? $mcp?->identifier)->token;
    });
}

it('keeps a CLI token from before the change working on /v1/organization and on the application host\'s /mcp', function (): void {
    multiTenantDeployment((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    $account = provisionAccount('owner@acme.example');

    $token = rootCliToken($account['subjectId']);

    $this->withToken($token)->getJson(audienceUrl('/v1/organization'))
        ->assertOk()
        ->assertJsonPath('object', 'organization')
        ->assertJsonPath('id', $account['organization']->id);

    expect(mcpTools($token))->not->toBeEmpty();

    // And one asked for the API host's resource after the change does the same.
    $current = rootCliToken($account['subjectId'], (string) ManagementAudience::api());

    $this->withToken($current)->getJson(audienceUrl('/v1/organization'))->assertOk();
    expect(mcpTools($current))->not->toBeEmpty();
});

it('serves an environment key on the application host\'s /mcp, in the key\'s own environment', function (): void {
    multiTenantDeployment((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    $account = provisionAccount('owner@acme.example');

    $key = app(EnvironmentApiKeys::class)->issue($account['environment']->id, 'agent', ['organizations:read'])->plaintext;

    $tools = mcpTools($key);

    expect($tools)->toHaveKey('organizations_list');

    $listed = mcpCall($key, 'organizations_list');
    expect($listed['isError'] ?? false)->toBeFalse();
});

it('refuses an environment key on the application host\'s /mcp once its environment or organization is suspended', function (string $what): void {
    multiTenantDeployment((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    $account = provisionAccount('owner@acme.example');

    $key = app(EnvironmentApiKeys::class)->issue($account['environment']->id, 'agent', ['organizations:read'])->plaintext;
    expect(mcpTools($key))->toHaveKey('organizations_list');

    if ($what === 'environment') {
        Environment::query()->whereKey($account['environment']->id)->update(['status' => EnvironmentStatus::Suspended]);
    } else {
        Organization::query()->withoutGlobalScopes()->whereKey($account['organization']->id)->update(['status' => 'suspended']);
    }

    expect(mcpRpc($key, 'tools/list')->status())->toBeIn([401, 404]);
})->with(['environment', 'organization'])->group('security');
