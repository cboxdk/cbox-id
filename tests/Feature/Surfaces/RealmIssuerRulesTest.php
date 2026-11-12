<?php

declare(strict_types=1);

use App\Actions\SignIn\SetSignInThrough;
use App\Actions\Sso\SsoFields;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\Principal\EnvironmentKeyPrincipal;
use App\Platform\OAuth\RootMcpOAuth;
use App\Platform\PlaneResolver;
use App\Platform\SignInThrough;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * IN LOGIN-HOST MODE THE DEFAULT ENVIRONMENT IS THE REALM: an issuer like any other, for
 * every one of the platform's own apps. The walls that held the root of a multi-tenant
 * deployment to "nobody's identity provider" stand down there, and it signs in through
 * nobody. Single-host mode keeps every one of them (tests/Feature/Mcp/RootMcpOAuthTest.php,
 * tests/Feature/RootEnvironment/RootSignsInThroughCboxTest.php).
 */
function realmRegister(array $changes = []): TestResponse
{
    return test()->postJson('https://'.LOGIN_HOST.'/oauth/register', [
        'client_name' => 'Claude Code',
        'redirect_uris' => ['http://127.0.0.1:41917/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'token_endpoint_auth_method' => 'none',
        ...$changes,
    ]);
}

beforeEach(function (): void {
    loginHostMode();
    config(['cbox-id.oauth.dynamic_registration.mode' => 'mcp', 'api.mcp.root_oauth' => true]);
});

it('holds the root to its own /mcp in single-host mode and not in login-host mode', function (): void {
    $root = app(PlatformRoot::class);
    $planes = app(PlaneResolver::class);

    expect($root->run(fn (): bool => $planes->rootIssuesForMcpOnly()))->toBeFalse();

    config(['cbox-id.hosts.login' => null]);

    expect($root->run(fn (): bool => $planes->rootIssuesForMcpOnly()))->toBeTrue();
})->group('security');

it('describes the login host as a whole authorization server, OpenID Connect included', function (): void {
    $metadata = test()->getJson('https://'.LOGIN_HOST.'/.well-known/oauth-authorization-server')->assertOk()->json();

    expect($metadata['issuer'])->toBe('https://'.LOGIN_HOST)
        ->and($metadata)->toHaveKey('userinfo_endpoint')
        ->and($metadata['scopes_supported'])->toContain('openid');
});

it('registers a client at the login host as a tenant host would, and holds it to no audience of the root', function (): void {
    $document = realmRegister()->assertCreated()->json();

    // The root's own slice strips the RFC 7592 management token; the framework's keeps it.
    expect($document)->toHaveKey('registration_access_token');

    $client = app(PlatformRoot::class)->run(
        fn (): ?Client => Client::query()->where('client_id', $document['client_id'])->first(),
    );

    expect($client)->not->toBeNull()
        ->and(app(PlatformRoot::class)->run(fn (): bool => app(RootMcpOAuth::class)->governs($client)))->toBeFalse();
});

it('ignores a connection the realm was told to sign in through, and refuses to name one', function (): void {
    Http::fake([
        'idp.example.test/.well-known/openid-configuration' => Http::response([
            'issuer' => 'https://idp.example.test',
            'authorization_endpoint' => 'https://idp.example.test/oauth/authorize',
            'token_endpoint' => 'https://idp.example.test/oauth/token',
            'jwks_uri' => 'https://idp.example.test/oauth/jwks',
        ]),
    ]);

    $root = app(PlatformRoot::class);
    $through = app(SignInThrough::class);

    $connection = $root->run(function () use ($root, $through) {
        $connections = app(Connections::class);
        $connection = $connections->create(null, ConnectionType::Oidc, 'Old', SsoFields::discovered([
            'issuer' => 'https://idp.example.test',
            'client_id' => 'cid_console',
            'client_secret' => 'the-client-secret',
        ]));
        $connections->activate(null, $connection->id);
        // The row as the split finds it: named before the login host existed.
        $through->set($root->model(), $connection->id);

        return $connection;
    });

    expect($root->run(fn () => $through->connection()))->toBeNull();

    // `/login` on the login host is the realm's own page, not a redirect to that provider.
    expect(test()->get('https://'.LOGIN_HOST.'/login')->getStatusCode())->toBe(200);

    $principal = new EnvironmentKeyPrincipal(
        $root->run(fn () => app(EnvironmentApiKeys::class)->issue($root->model()->id, 'Sign-in worker', ['signin:write'])->key),
    );
    $set = fn (?string $id) => $root->run(fn () => app(SetSignInThrough::class)->handle(new ActionContext($principal, ['connection_id' => $id])));

    expect(fn () => $set($connection->id))->toThrow(fn (ActionRefused $e) => expect($e->error)->toBe('not_available_in_the_realm'));
    expect(SignInThrough::configuredFor($root->model()->refresh()))->toBe($connection->id);

    // Clearing what was left is allowed.
    $set(null);

    expect(SignInThrough::configuredFor($root->model()->refresh()))->toBeNull();

    // Single-host mode: the same row works as before.
    config(['cbox-id.hosts.login' => null]);
    $root->run(fn () => $through->set($root->model(), $connection->id));

    expect($root->run(fn () => $through->connection())?->id)->toBe($connection->id);
})->group('security');
