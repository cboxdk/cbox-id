<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use App\Support\CliClient;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\ProtectedResources;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\Models\DeviceCode;
use Cbox\Id\OAuthServer\Testing\InteractsWithOAuth;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| Signing a person in to `/mcp`: the way an MCP client and the `cbox` CLI do it.
|--------------------------------------------------------------------------
|
| An MCP client knows nothing but the server's URL. It registers itself (RFC 7591, `mcp`
| profile) or presents a client ID metadata document, sends the person through this app's
| own /authorize and consent screen naming `/mcp` as its resource, and redeems the code for
| a token audienced there. The CLI does the device grant against its provisioned client.
| Every one of these ends in the same place: a token `/mcp` takes, as that person.
*/

uses(InteractsWithOAuth::class);

beforeEach(function (): void {
    installedDeployment();
});

const MCP_CALLBACK = 'http://127.0.0.1:33418/callback';

const AGENT_DOCUMENT = 'https://agent.example/oauth/client.json';

function mcpResourceId(): string
{
    return (string) app(ProtectedResources::class)->forMetadataPath('/.well-known/oauth-protected-resource/mcp')?->identifier;
}

/**
 * Sign a person of this environment in on this browser, as the Owner of an organization.
 *
 * @return array{0: string, 1: string} subject id, organization id
 */
function mcpSignedIn(MembershipRole $role = MembershipRole::Owner): array
{
    $subject = app(Subjects::class)->create('ada@acme.test', 'Ada', 'a-strong-unbreached-passphrase');
    $organization = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-mcp'));
    app(Memberships::class)->add($organization->id, $subject->id, $role);

    $session = app(SessionManager::class)->start($subject->id, $organization->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, $organization, $role);

    return [$subject->id, $organization->id];
}

/** Register an MCP client the way Claude Code does: public, loopback callback, no scope named. */
function registerMcpClient(array $changes = []): TestResponse
{
    return test()->postJson('/oauth/register', [
        'client_name' => 'Claude Code',
        'redirect_uris' => [MCP_CALLBACK],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'token_endpoint_auth_method' => 'none',
        ...$changes,
    ]);
}

/**
 * Approve an authorization on the consent screen and redeem its code at the token endpoint,
 * as the client would.
 *
 * @param  array<string, mixed>  $props  the consent screen's props
 */
function redeemConsent(array $props, string $clientId, string $redirectUri = MCP_CALLBACK): TestResponse
{
    $location = (string) leftFor(answerConsent($props));
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($query)->toHaveKey('code');

    return test()->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $query['code'],
        'redirect_uri' => $redirectUri,
        'client_id' => $clientId,
        'code_verifier' => pkcePair()['verifier'],
        'resource' => mcpResourceId(),
    ]);
}

// ── Dynamic client registration, `mcp` profile ──────────────────────────────────

it('lets an MCP client register itself for the management plane\'s scopes', function (): void {
    $registered = registerMcpClient()->assertCreated();

    $scopes = explode(' ', (string) $registered->json('scope'));

    expect($registered->json('token_endpoint_auth_method'))->toBe('none')
        ->and($registered->json('client_secret'))->toBeNull()
        ->and($scopes)->toContain('webhooks:read', 'webhooks:write', 'offline_access');
});

it('refuses a confidential registration and a non-loopback http callback', function (array $changes): void {
    $refused = registerMcpClient($changes)->assertStatus(400);

    expect($refused->json('error'))->toBeIn(['invalid_client_metadata', 'invalid_redirect_uri']);
})->with([
    'a client secret' => [['token_endpoint_auth_method' => 'client_secret_basic']],
    'plain http off loopback' => [['redirect_uris' => ['http://agent.example/callback']]],
    'the device grant' => [['grant_types' => ['urn:ietf:params:oauth:grant-type:device_code']]],
])->group('security');

it('signs a person in through a self-registered client and serves /mcp as them', function (): void {
    [$subject] = mcpSignedIn();
    $clientId = (string) registerMcpClient()->assertCreated()->json('client_id');

    $props = consentScreen([
        'client_id' => $clientId,
        'redirect_uri' => MCP_CALLBACK,
        'scope' => 'webhooks:read webhooks:write offline_access',
        'resource' => mcpResourceId(),
    ]);

    // Never skipped for a stranger, and said to be one.
    expect($props['client']['selfRegistered'])->toBeTrue()
        ->and($props['client']['documentHost'])->toBeNull()
        // The management scopes, with the one a critical action needs flagged.
        ->and(collect($props['scopes'])->keyBy('scope')->only(['webhooks:read', 'webhooks:write'])->map(fn (array $row): array => [$row['management'], $row['critical']])->all())
        ->toBe(['webhooks:read' => [true, false], 'webhooks:write' => [true, true]]);

    $token = (string) redeemConsent($props, $clientId)->assertOk()->json('access_token');

    expect(mcpCall($token, 'whoami')['structuredContent'])->toMatchArray(['kind' => 'delegated', 'subject' => $subject])
        ->and(array_keys(mcpTools($token)))->toContain('webhooks_list', 'webhooks_secret_rotate');
});

it('refuses at /authorize a resource a self-registered client may not be audienced to', function (): void {
    mcpSignedIn();
    $clientId = (string) registerMcpClient()->assertCreated()->json('client_id');

    $location = (string) leftFor(authorizeRequest([
        'client_id' => $clientId,
        'redirect_uri' => MCP_CALLBACK,
        'scope' => 'webhooks:read',
        'resource' => 'https://somebody-else.example/api',
    ]));

    expect($location)->toStartWith(MCP_CALLBACK)->toContain('error=invalid_target');
})->group('security');

it('closes /mcp to self-registered clients when the operator says so', function (): void {
    mcpSignedIn();
    $clientId = (string) registerMcpClient()->assertCreated()->json('client_id');

    config(['api.mcp.dynamic_clients' => false]);

    $location = (string) leftFor(authorizeRequest([
        'client_id' => $clientId,
        'redirect_uri' => MCP_CALLBACK,
        'scope' => 'webhooks:read',
        'resource' => mcpResourceId(),
    ]));

    expect($location)->toContain('error=invalid_target');
})->group('security');

it('refuses a repeated resource at /authorize', function (): void {
    mcpSignedIn();
    $clientId = (string) registerMcpClient()->assertCreated()->json('client_id');

    $query = http_build_query([
        'response_type' => 'code',
        'client_id' => $clientId,
        'redirect_uri' => MCP_CALLBACK,
        'scope' => 'webhooks:read',
        'state' => 'xyz',
        'code_challenge' => pkceChallenge(),
        'code_challenge_method' => 'S256',
    ]).'&resource='.urlencode(mcpResourceId()).'&resource='.urlencode('https://other.example');

    expect((string) leftFor(test()->get('/oauth/authorize?'.$query)))->toContain('error=invalid_target');
})->group('security');

// ── Client ID metadata documents ────────────────────────────────────────────────

it('takes a client ID metadata document client through /authorize and consent to /mcp', function (): void {
    [$subject] = mcpSignedIn();

    $this->fakeClientMetadataDocuments()->serve(AGENT_DOCUMENT, [
        'client_id' => AGENT_DOCUMENT,
        'client_name' => 'Totally Cbox ID',
        'client_uri' => 'https://agent.example',
        'logo_uri' => 'https://agent.example/logo.png',
        'redirect_uris' => [MCP_CALLBACK],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'token_endpoint_auth_method' => 'none',
    ]);

    $props = consentScreen([
        'client_id' => AGENT_DOCUMENT,
        'redirect_uri' => MCP_CALLBACK,
        'scope' => 'audit:read',
        'resource' => mcpResourceId(),
    ]);

    // The name is the publisher's to choose; the HOST is what was verified, and leads.
    expect($props['client'])->toMatchArray([
        'name' => 'Totally Cbox ID',
        'selfRegistered' => true,
        'documentHost' => 'agent.example',
        'clientUri' => 'https://agent.example',
        'logoUri' => 'https://agent.example/logo.png',
    ]);

    $token = (string) redeemConsent($props, AGENT_DOCUMENT)->assertOk()->json('access_token');

    expect(mcpCall($token, 'whoami')['structuredContent'])->toMatchArray([
        'kind' => 'delegated',
        'subject' => $subject,
        'client' => ['id' => AGENT_DOCUMENT, 'name' => 'agent.example'],
    ]);

    // Nothing was stored for it.
    expect(Client::query()->where('client_id', AGENT_DOCUMENT)->exists())->toBeFalse();
});

it('holds a metadata document client to the exact redirect URIs it published', function (): void {
    mcpSignedIn();

    $this->fakeClientMetadataDocuments()->serve(AGENT_DOCUMENT, [
        'client_id' => AGENT_DOCUMENT,
        'client_name' => 'Agent',
        'redirect_uris' => [MCP_CALLBACK],
    ]);

    // A registered client's loopback port may float; a document's may not.
    $refused = authorizeRequest([
        'client_id' => AGENT_DOCUMENT,
        'redirect_uri' => 'http://127.0.0.1:9999/callback',
        'resource' => mcpResourceId(),
    ])->assertOk();

    expect(consentRefusal($refused))->toBe(__('oauth.failure.redirect_mismatch'));
})->group('security');

it('renders, never redirects, a metadata document it cannot use', function (): void {
    mcpSignedIn();

    $this->fakeClientMetadataDocuments()->refuseAsUnsafe(AGENT_DOCUMENT);

    $refused = authorizeRequest([
        'client_id' => AGENT_DOCUMENT,
        'redirect_uri' => MCP_CALLBACK,
    ])->assertOk();

    expect(consentRefusal($refused))->toBe(__('oauth.failure.client_document'));
})->group('security');

// ── The `cbox` CLI: the device grant, named resource ────────────────────────────

it('signs the cbox CLI in with the device grant to a token /mcp and REST both take', function (): void {
    [$client] = CliClient::provision(app(ClientRegistry::class));

    $bootstrap = $this->getJson('/.well-known/cbox-cli')->assertOk();

    expect($bootstrap->json('resource'))->toBe(mcpResourceId())
        ->and($bootstrap->json('scopes'))->toContain('offline_access', 'webhooks:read', 'webhooks:write');

    $started = $this->post('/oauth/device_authorization', [
        'client_id' => $client->client_id,
        'scope' => implode(' ', (array) $bootstrap->json('scopes')),
    ])->assertOk();

    [$subject] = mcpSignedIn();

    lookUpDeviceCode((string) $started->json('user_code'))->assertRedirect(route('device'));

    // The device page shows what the CLI may do as the person, critical scopes flagged.
    $rows = collect(deviceScreen()['client']['scopes'])->keyBy('scope');

    expect($rows['webhooks:write']['critical'])->toBeTrue()
        ->and($rows['webhooks:read']['critical'])->toBeFalse();

    approveDevice()->assertRedirect(route('device'));

    DeviceCode::query()->update(['last_polled_at' => now()->subMinute()]);

    $token = (string) $this->post('/oauth/token', [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
        'device_code' => $started->json('device_code'),
        'client_id' => $client->client_id,
        'resource' => $bootstrap->json('resource'),
    ])->assertOk()->json('access_token');

    expect(mcpCall($token, 'whoami')['structuredContent'])->toMatchArray(['kind' => 'delegated', 'subject' => $subject]);

    $this->withToken($token)->getJson('/api/v1/webhooks')->assertOk();
});

it('brings an existing CLI client up to the management plane\'s scopes, adding only', function (): void {
    $old = app(ClientRegistry::class)->register(new NewClient(
        name: CliClient::NAME,
        type: ClientType::Public,
        grantTypes: CliClient::GRANTS,
        scopes: CliClient::SCOPES,
        firstParty: true,
    ))->client;

    [$client, $created] = CliClient::provision(app(ClientRegistry::class));

    expect($created)->toBeFalse()
        ->and($client->client_id)->toBe($old->client_id)
        ->and($client->scopes)->toContain(...CliClient::SCOPES)
        ->and($client->scopes)->toContain('webhooks:write');
});
