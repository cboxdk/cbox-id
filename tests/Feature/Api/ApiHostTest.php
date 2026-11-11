<?php

declare(strict_types=1);

use App\Platform\Api\ApiHost;
use App\Platform\Api\LegacyApiUsage;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Platform\Enums\EnvironmentApiScope;
use Cbox\Telemetry\Facades\Telemetry;
use Illuminate\Support\Facades\Http;

/*
| Step 5a, expand: `https://api.cboxid.com/v1` next to `/api/v1`. The credential names the
| environment, the shapes are the standard's, and every old path still answers — marked
| deprecated and counted per caller.
*/

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/** A URL on the API host. */
function onApi(string $path): string
{
    return 'http://'.ApiHost::name().'/'.ltrim($path, '/');
}

/**
 * @param  list<EnvironmentApiScope>  $scopes
 */
function apiEnvKey(array $scopes, string $environmentId = 'env_test'): string
{
    return app(EnvironmentApiKeys::class)->issue(
        $environmentId,
        'api host key',
        array_map(static fn (EnvironmentApiScope $scope): string => $scope->value, $scopes),
    )->plaintext;
}

it('answers on the API host with the environment the key was minted in', function (): void {
    $key = apiEnvKey([EnvironmentApiScope::UsersRead, EnvironmentApiScope::UsersWrite]);

    $id = $this->withToken($key)->postJson(onApi('/v1/users'), ['email' => 'ada@acme.example', 'name' => 'Ada'])
        ->assertCreated()
        ->assertJsonPath('object', 'user')
        ->assertJsonPath('email', 'ada@acme.example')
        ->json('id');

    $this->withToken($key)->getJson(onApi("/v1/users/{$id}"))
        ->assertOk()
        ->assertJsonPath('object', 'user')
        ->assertJsonPath('id', $id);

    $this->withToken($key)->getJson(onApi('/v1/users'))
        ->assertOk()
        ->assertJsonPath('object', 'list')
        ->assertJsonPath('has_more', false)
        ->assertJsonPath('next_cursor', null)
        ->assertJsonPath('data.0.id', $id);
});

it('never shows one environment\'s records to another environment\'s key', function (): void {
    $ours = apiEnvKey([EnvironmentApiScope::UsersRead]);
    $theirs = apiEnvKey([EnvironmentApiScope::UsersRead], 'env_other');
    $ada = app(Subjects::class)->create('ada@acme.example', 'Ada');

    $this->withToken($ours)->getJson(onApi("/v1/users/{$ada->id}"))->assertOk();

    $this->withToken($theirs)->getJson(onApi("/v1/users/{$ada->id}"))
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('error', 'not_found');
})->group('security');

it('answers every failure with an RFC 9457 problem', function (): void {
    $readOnly = apiEnvKey([EnvironmentApiScope::OrganizationsRead]);
    $write = apiEnvKey([EnvironmentApiScope::OrganizationsRead, EnvironmentApiScope::OrganizationsWrite]);

    // 401: no credential.
    $this->getJson(onApi('/v1/organizations'))
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('status', 401)
        ->assertJsonPath('type', 'https://'.ApiHost::name().'/problems/unauthorized');

    // 403: a key without the scope.
    $this->withToken($readOnly)->postJson(onApi('/v1/organizations'), ['name' => 'Acme', 'slug' => 'acme'])
        ->assertForbidden()
        ->assertJsonPath('error', 'forbidden')
        ->assertJsonPath('title', 'Forbidden');

    // 404: an id that is not there.
    $this->withToken($readOnly)->getJson(onApi('/v1/organizations/01JNOTTHERE0000000000000000'))
        ->assertNotFound()
        ->assertJsonPath('error', 'not_found');

    // 422: a field that is wrong, keyed in `errors`.
    $this->withToken($write)->postJson(onApi('/v1/organizations'), [])
        ->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('status', 422)
        ->assertJsonStructure(['type', 'title', 'status', 'detail', 'error', 'errors']);

    // And a path the API has no route for.
    $this->withToken($readOnly)->getJson(onApi('/v1/nothing-here'))->assertNotFound()->assertHeader('Content-Type', 'application/problem+json');
});

it('pages every list by cursor', function (): void {
    foreach (range(1, 3) as $n) {
        app(Organizations::class)->create(new NewOrganization("Org {$n}", "org-{$n}"));
    }

    $key = apiEnvKey([EnvironmentApiScope::OrganizationsRead]);

    $first = $this->withToken($key)->getJson(onApi('/v1/organizations?limit=2'))
        ->assertOk()
        ->assertJsonPath('object', 'list')
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('has_more', true);

    $cursor = $first->json('next_cursor');
    expect($cursor)->toBeString();

    $this->withToken($key)->getJson(onApi('/v1/organizations?limit=2&after='.$cursor))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('has_more', false)
        ->assertJsonPath('next_cursor', null);
});

it('pages the organization plane\'s numbered lists by cursor too', function (): void {
    $account = provisionAccount();
    addMember($account['organization']->id, MembershipRole::Developer, 'dev@acme.example');
    $key = app(OrganizationApiKeys::class)->issue($account['organization']->id, 'CI', MembershipRole::Admin)->plaintext;

    $first = $this->withToken($key)->getJson(onApi('/v1/members?limit=1'))
        ->assertOk()
        ->assertJsonPath('object', 'list')
        ->assertJsonPath('has_more', true)
        ->assertJsonPath('next_cursor', 'page:2');

    $this->withToken($key)->getJson(onApi('/v1/members?limit=1&after='.$first->json('next_cursor')))
        ->assertOk()
        ->assertJsonPath('has_more', false)
        ->assertJsonPath('next_cursor', null);
});

it('serves the organization plane at /v1/organization with an organization key, and the old path beside it', function (): void {
    $account = provisionAccount();
    $key = app(OrganizationApiKeys::class)->issue($account['organization']->id, 'CI', MembershipRole::Admin)->plaintext;

    $this->withToken($key)->getJson(onApi('/v1/organization'))
        ->assertOk()
        ->assertJsonPath('object', 'organization')
        ->assertJsonPath('id', $account['organization']->id)
        ->assertHeaderMissing('Deprecation');

    $this->withToken($key)->getJson('/api/v1/workspace')
        ->assertOk()
        ->assertJsonPath('data.id', $account['organization']->id)
        ->assertHeader('Deprecation')
        ->assertHeader('Sunset');
});

it('keeps an old path working on the new base for a client that was handed the new api_base', function (): void {
    $account = provisionAccount();
    $key = app(OrganizationApiKeys::class)->issue($account['organization']->id, 'Old CLI', MembershipRole::Admin)->plaintext;

    $this->withToken($key)->getJson(onApi('/v1/workspace'))
        ->assertOk()
        ->assertJsonPath('data.id', $account['organization']->id)
        ->assertHeader('Deprecation');

    // And an old SDK pointed at the API host's origin.
    $this->withToken($key)->getJson(onApi('/api/v1/workspace'))
        ->assertOk()
        ->assertJsonPath('data.id', $account['organization']->id)
        ->assertHeader('Deprecation');
});

it('marks every old /api/v1 response deprecated and counts it per caller', function (): void {
    $telemetry = Telemetry::fake();
    $key = apiEnvKey([EnvironmentApiScope::OrganizationsRead]);
    $keyId = app(EnvironmentApiKeys::class)->forEnvironment('env_test')->first()->id;

    $response = $this->withToken($key)->getJson('/api/v1/organizations')->assertOk();

    expect($response->headers->get('Deprecation'))->toMatch('/\A@\d+\z/')
        ->and($response->headers->get('Sunset'))->toEndWith('GMT')
        ->and($response->headers->get('Link'))->toContain('rel="successor-version"');

    $telemetry->assertCounterIncremented(LegacyApiUsage::METRIC, [
        'caller_kind' => 'environment_key',
        'caller' => $keyId,
        'route' => 'GET /api/v1/organizations',
    ]);

    // A refusal is counted too: it is still somebody calling the old path.
    $this->flushHeaders()->getJson('/api/v1/organizations')->assertUnauthorized()->assertHeader('Deprecation');
    $telemetry->assertCounterIncremented(LegacyApiUsage::METRIC, ['caller_kind' => 'anonymous', 'caller' => 'none', 'route' => 'GET /api/v1/organizations']);
});

it('keeps the Authenticator\'s /api/v1/devices and /api/v1/approvals answering as they did', function (): void {
    // Unauthenticated, the app's own paths answer exactly what they answered before —
    // the old envelope, not a problem — and only gain the deprecation headers.
    foreach (['/api/v1/devices', '/api/v1/approvals'] as $path) {
        $this->getJson($path)
            ->assertUnauthorized()
            ->assertHeader('Deprecation')
            ->assertJsonMissingPath('type')
            ->assertJsonStructure(['error']);
    }
});

it('serves nothing but the API on the API host: no page, no session, no cookie', function (): void {
    foreach (['/login', '/', '/orgs', '/settings', '/mcp', '/internal/v1/health/ready', '/oauth/token'] as $path) {
        $response = $this->get(onApi($path));

        $response->assertNotFound()->assertHeader('Content-Type', 'application/problem+json');
        expect($response->headers->getCookies())->toBe([]);
    }

    $response = $this->withToken(apiEnvKey([EnvironmentApiScope::OrganizationsRead]))->getJson(onApi('/v1/organizations'))->assertOk();
    expect($response->headers->getCookies())->toBe([])
        ->and($response->headers->has('Set-Cookie'))->toBeFalse();

    // A write needs no CSRF token: there is no session to forge one for.
    $this->withToken(apiEnvKey([EnvironmentApiScope::OrganizationsWrite]))
        ->postJson(onApi('/v1/organizations'), ['name' => 'Acme', 'slug' => 'acme'])
        ->assertCreated();
})->group('security');

it('publishes one OpenAPI document and its reference on the API host', function (): void {
    $this->get(onApi('/openapi.json'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('openapi', '3.1.0')
        ->assertJsonPath('servers.0.url', 'https://api.cboxid.com');

    $docs = $this->get(onApi('/docs'))->assertOk();
    expect($docs->headers->get('Content-Type'))->toStartWith('text/html')
        ->and($docs->getContent())->toContain('/v1/users')->toContain('id="problem-not_found"')
        ->and($docs->headers->getCookies())->toBe([]);

    // The base a client saves answers for itself, and points at both.
    $this->getJson(onApi('/v1'))
        ->assertOk()
        ->assertJsonPath('object', 'api')
        ->assertJsonPath('openapi_url', 'https://'.ApiHost::name().'/openapi.json')
        ->assertJsonPath('documentation_url', 'https://'.ApiHost::name().'/docs');

    $this->get(onApi('/problems/not_found'))->assertRedirect('https://'.ApiHost::name().'/docs#problem-not_found');
});
