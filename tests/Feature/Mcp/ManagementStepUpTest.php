<?php

declare(strict_types=1);

use App\Platform\OAuth\ManagementStepUp;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\AuthenticationAwareTokenIssuer;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\ProtectedResources;
use Cbox\Id\OAuthServer\Contracts\TokenIssuer;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\AuthenticationEvent;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;

/*
|--------------------------------------------------------------------------
| RFC 9470 step-up for a person's token on the management plane.
|--------------------------------------------------------------------------
|
| `api.mcp.step_up` names how recent and how strong the sign-in behind a token must be
| before a Critical action. Short of it, both doors answer `401` with
| `insufficient_user_authentication` and the requirement in `WWW-Authenticate` — `/mcp` at
| the HTTP layer, where an MCP client re-authorizes from — and a token that meets it goes on
| to the approval hold every Critical action from a token already waits for.
*/

const STEP_UP_SCOPES = ['webhooks:read', 'webhooks:write'];

function stepUpMcpResource(): string
{
    return (string) app(ProtectedResources::class)->forMetadataPath('/.well-known/oauth-protected-resource/mcp')?->identifier;
}

function stepUpMcpClient(): Client
{
    return app(ClientRegistry::class)->register(new NewClient(
        name: 'Claude Code',
        type: ClientType::Public,
        redirectUris: ['http://127.0.0.1:33418/callback'],
        grantTypes: ['authorization_code', 'refresh_token'],
        scopes: ['openid', 'offline_access', ...STEP_UP_SCOPES],
    ))->client;
}

/** @return array{0: string, 1: string} */
function stepUpMcpPerson(): array
{
    $subject = app(Subjects::class)->create('grace@acme.test', 'Grace', 'a-strong-unbreached-passphrase');
    $organization = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-step-up'));
    app(Memberships::class)->add($organization->id, $subject->id, MembershipRole::Owner);

    return [$subject->id, $organization->id];
}

/**
 * A token as the token endpoint mints it after a sign-in with these methods at that time —
 * or, with no sign-in, as a token minted before 1.23: no `acr`, no `auth_time`.
 *
 * @param  list<string>|null  $amr
 */
function stepUpMcpToken(string $subjectId, string $organizationId, ?array $amr = null, ?int $authTime = null): string
{
    $client = stepUpMcpClient();
    $scopes = ['openid', ...STEP_UP_SCOPES];

    if ($amr === null) {
        return app(TokenIssuer::class)->issueForUser($client, $subjectId, $organizationId, $scopes, stepUpMcpResource())->token;
    }

    $issuer = app(TokenIssuer::class);

    expect($issuer)->toBeInstanceOf(AuthenticationAwareTokenIssuer::class);

    /** @var AuthenticationAwareTokenIssuer $issuer */
    return $issuer->issueForAuthenticatedUser($client, $subjectId, $organizationId, $scopes, new AuthenticationEvent($authTime ?? time(), $amr), stepUpMcpResource())->token;
}

function stepUpRequire(?string $acr = 'aal2', ?int $maxAge = 900): void
{
    config(['api.mcp.step_up' => ['acr' => $acr, 'max_age' => $maxAge]]);
}

it('is off by default: a token without acr or auth_time goes straight to the approval hold', function (): void {
    [$subject, $organization] = stepUpMcpPerson();
    $endpoint = app(WebhookRegistry::class)->register($organization, 'https://own.acme.example/in', ['user.created'])->endpoint;

    expect(ManagementStepUp::requirement())->toBeNull();

    $this->withToken(stepUpMcpToken($subject, $organization))->postJson("/api/v1/webhooks/{$endpoint->id}/rotate")
        ->assertStatus(202)
        ->assertJsonPath('error', 'approval_required');
});

it('answers a Critical REST call from a weak token with the RFC 9470 challenge', function (): void {
    stepUpRequire();
    [$subject, $organization] = stepUpMcpPerson();
    $endpoint = app(WebhookRegistry::class)->register($organization, 'https://own.acme.example/in', ['user.created'])->endpoint;

    $refused = $this->withToken(stepUpMcpToken($subject, $organization, ['pwd']))->postJson("/api/v1/webhooks/{$endpoint->id}/rotate")
        ->assertUnauthorized()
        ->assertJsonPath('error', 'insufficient_user_authentication');

    $challenge = (string) $refused->headers->get('WWW-Authenticate');

    expect($challenge)->toContain('error="insufficient_user_authentication"')
        ->and($challenge)->toContain('acr_values="urn:cbox-id:aal2"')
        ->and($challenge)->toContain('max_age="900"')
        ->and($challenge)->toContain('resource_metadata=');

    // A write that is not Critical is not asked.
    $this->withToken(stepUpMcpToken($subject, $organization, ['pwd']))->postJson("/api/v1/webhooks/{$endpoint->id}/pause")->assertOk();
})->group('security');

it('refuses a token that is strong but too old, and one that carries no sign-in at all', function (): void {
    stepUpRequire();
    [$subject, $organization] = stepUpMcpPerson();
    $endpoint = app(WebhookRegistry::class)->register($organization, 'https://own.acme.example/in', ['user.created'])->endpoint;

    $this->withToken(stepUpMcpToken($subject, $organization, ['pwd', 'mfa'], time() - 3_600))->postJson("/api/v1/webhooks/{$endpoint->id}/rotate")
        ->assertUnauthorized()->assertJsonPath('error', 'insufficient_user_authentication');

    $this->withToken(stepUpMcpToken($subject, $organization))->postJson("/api/v1/webhooks/{$endpoint->id}/rotate")
        ->assertUnauthorized()->assertJsonPath('error', 'insufficient_user_authentication');
})->group('security');

it('lets a recent second-factor token through to the approval hold', function (): void {
    stepUpRequire();
    [$subject, $organization] = stepUpMcpPerson();
    $endpoint = app(WebhookRegistry::class)->register($organization, 'https://own.acme.example/in', ['user.created'])->endpoint;

    $this->withToken(stepUpMcpToken($subject, $organization, ['pwd', 'mfa']))->postJson("/api/v1/webhooks/{$endpoint->id}/rotate")
        ->assertStatus(202)
        ->assertJsonPath('error', 'approval_required');
});

it('answers a Critical tools/call on /mcp with an HTTP 401 challenge, not a tool error', function (): void {
    stepUpRequire(maxAge: null);
    [$subject, $organization] = stepUpMcpPerson();
    $endpoint = app(WebhookRegistry::class)->register($organization, 'https://own.acme.example/in', ['user.created'])->endpoint;
    $weak = stepUpMcpToken($subject, $organization, ['pwd']);

    $refused = mcpRpc($weak, 'tools/call', ['name' => 'webhooks_secret_rotate', 'arguments' => ['id' => $endpoint->id]])
        ->assertUnauthorized()
        ->assertJsonPath('error', 'insufficient_user_authentication');

    expect((string) $refused->headers->get('WWW-Authenticate'))->toContain('acr_values="urn:cbox-id:aal2"')
        ->not->toContain('max_age');

    // Listing, reading and a write that is not Critical are untouched.
    mcpRpc($weak, 'tools/list')->assertOk();
    expect(mcpCall($weak, 'webhooks_pause', ['id' => $endpoint->id])['isError'] ?? false)->toBeFalse();

    // A strong token reaches the tool, which holds it for the person as it always did.
    $held = mcpCall(stepUpMcpToken($subject, $organization, ['pwd', 'otp']), 'webhooks_secret_rotate', ['id' => $endpoint->id]);

    expect($held['structuredContent']['status'])->toBe('approval_pending');
})->group('security');

it('never asks a management key: a key is not a sign-in', function (): void {
    stepUpRequire();
    $key = app(EnvironmentApiKeys::class)->issue('env_test', 'Deployer', STEP_UP_SCOPES)->plaintext;

    $this->withToken($key)->postJson('/api/v1/webhooks', ['url' => 'https://a.acme.example/in', 'event_types' => ['user.created'], 'environment_wide' => true])
        ->assertCreated();
});

it('reads the configured class by its short names and refuses one it does not know', function (): void {
    config(['api.mcp.step_up' => ['acr' => 'mfa', 'max_age' => '300']]);

    expect(ManagementStepUp::requirement()?->acrValues)->toBe(['urn:cbox-id:aal2'])
        ->and(ManagementStepUp::requirement()?->maxAge)->toBe(300);

    config(['api.mcp.step_up' => ['acr' => 'loa3', 'max_age' => null]]);

    expect(fn () => ManagementStepUp::requirement())->toThrow(InvalidArgumentException::class);
});
