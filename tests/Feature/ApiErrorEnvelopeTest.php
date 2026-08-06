<?php

declare(strict_types=1);

use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Platform\Enums\EnvironmentApiScope;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\ApiContract;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

if (! function_exists('envelopeEnvKey')) {
    /** @param  list<EnvironmentApiScope>  $scopes */
    function envelopeEnvKey(array $scopes): string
    {
        return app(EnvironmentApiKeys::class)->issue(
            'env_test',
            'envelope test key',
            array_map(fn (EnvironmentApiScope $s): string => $s->value, $scopes),
        )->plaintext;
    }
}

if (! function_exists('envelopeAccountKey')) {
    function envelopeAccountKey(): string
    {
        platformRootEnvironment();

        $account = app(TenantProvisioner::class)->provision(new TenantBlueprint(
            organizationName: 'Envelope',
            ownerEmail: 'owner@envelope.example',
            ownerName: 'Owner',
            ownerPassword: 'a-strong-unbreached-passphrase',
        ))->organization;

        return app(OrganizationApiKeys::class)->issue($account->id, 'envelope key', MembershipRole::Admin)->plaintext;
    }
}

/**
 * ONE error shape on the REST API.
 *
 * Both specs promise `{error, message}` on every failure and account.yaml marks the pair
 * REQUIRED — but `bootstrap/app.php` had an empty `withExceptions()`, so the single most
 * common failure of all, `$request->validate()`, rendered Laravel's default
 * `{"message": …, "errors": {…}}` with NO `error` key. A generated client that switches
 * on `error` broke on the first bad payload it sent.
 *
 * The blanket invariant lives in {@see ApiContract} and runs on every
 * `/api/*` response the whole suite produces — a single hand-written case would not keep
 * this true. These are the named cases for the paths that were actually broken.
 */
it('renders a validation failure in the documented envelope, on the environment plane', function (): void {
    $key = envelopeEnvKey([EnvironmentApiScope::UsersWrite]);

    $response = $this->withToken($key)->postJson('/api/v1/users', ['email' => 'not-an-email'])
        ->assertStatus(422)
        // `error` is the part that was missing entirely.
        ->assertJsonPath('error', 'validation_failed');

    expect($response->json('message'))->toBeString()->not->toBe('')
        // The useful half of Laravel's default is kept, not thrown away.
        ->and($response->json('errors.email'))->toBeArray();
});

it('renders a validation failure in the documented envelope, on the account plane', function (): void {
    $token = envelopeAccountKey();

    $this->withToken($token)->postJson('/api/v1/organization/projects', [])
        ->assertStatus(422)
        ->assertJsonPath('error', 'validation_failed')
        ->assertJsonStructure(['error', 'message', 'errors' => ['name']]);
});

it('renders an unrouted API path in the envelope, not as a bare framework 404', function (): void {
    $this->getJson('/api/v1/no-such-endpoint')
        ->assertStatus(404)
        ->assertJsonPath('error', 'not_found');
});

it('renders a wrong verb in the envelope', function (): void {
    // POST is routed for the collection, not for a single organization.
    $this->postJson('/api/v1/organizations/01JSOMETHING')
        ->assertStatus(405)
        ->assertJsonPath('error', 'method_not_allowed')
        ->assertJsonStructure(['error', 'message']);
});

it('leaves the RFC 6750 bearer challenge alone', function (): void {
    // The vault + app-manifest planes authenticate an OAuth access token. RFC 6750 §3
    // specifies `{error, error_description}` with a WWW-Authenticate header; that is
    // protocol conformance, not an inconsistency to "fix".
    $response = $this->postJson('/api/v1/vault/secrets', [])
        ->assertStatus(401)
        ->assertHeader('WWW-Authenticate')
        ->assertJsonPath('error', 'invalid_request');

    expect($response->json('error_description'))->toBeString()->not->toBe('');
});

it('keeps the web console on HTML error pages', function (): void {
    // The envelope is scoped to `/api/*` — turning every console 404 into JSON would
    // break the browser experience.
    $this->get('/no-such-page')->assertNotFound()
        ->assertHeader('content-type', 'text/html; charset=UTF-8');
});
