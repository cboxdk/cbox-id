<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use Cbox\Id\FrontendApi\Contracts\PublishableKeys;
use Cbox\Id\FrontendApi\Enums\KeyMode;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\MagicLink;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\MfaRequirement;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Kernel\Crypto\TotpAuthenticator;
use Cbox\Id\OAuthServer\Contracts\AuthorizationCodes;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\AuthorizationCode;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;

beforeEach(fn () => installedDeployment());

function securityRegressionFixture(): array
{
    $subject = app(Subjects::class)->create('audit@example.test', 'Audit', 'long-audit-password-123');
    app(Subjects::class)->markEmailVerified($subject->id, 'audit@example.test');
    $org = app(Organizations::class)->create(new NewOrganization('Audit', 'security-audit'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
    $client = app(ClientRegistry::class)->register(new NewClient(
        'Audit', type: ClientType::Public, redirectUris: ['https://audit.example.test/cb'],
        grantTypes: ['authorization_code', 'refresh_token'], scopes: ['openid', 'offline_access'], firstParty: true, organizationId: $org->id,
    ))->client;

    return [$subject, $org, $client];
}

it('requires TOTP after a magic link and grants OAuth tokens only after verification', function (): void {
    [$subject, $org, $client] = securityRegressionFixture();
    $factor = app(Mfa::class)->enrollTotp($subject->id, 'audit@example.test');
    confirmTotpForTest($subject->id, $factor->secret);
    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(mfa: MfaRequirement::Required));
    $link = app(MagicLink::class)->request('audit@example.test');
    $this->post(route('magic.redeem.store', $link))->assertRedirect(route('mfa'));
    expect(session()->has(PlatformAuth::SESSION_KEY))->toBeFalse();
    $this->get(route('mfa'))->assertOk();
    $this->post(route('mfa.verify'), ['code' => 'invalid'])->assertSessionHasErrors();
    expect(session()->has(PlatformAuth::SESSION_KEY))->toBeFalse();

    $this->post(route('mfa.verify'), ['code' => (new TotpAuthenticator)->codeAt($factor->secret, time())])->assertRedirect();
    $session = app(SessionManager::class)->active(session(PlatformAuth::SESSION_KEY));
    expect($session->amr)->toBe(['magic_link', 'otp', 'mfa']);
    assertSecurityRegressionTokens($client->client_id);
});

it('refuses password-only OAuth login for an enrolled passkey under required MFA', function (): void {
    [$subject, $org, $client] = securityRegressionFixture();
    securityRegressionPasskey($subject->id);
    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(mfa: MfaRequirement::Required));
    $this->from(route('login'))->post(route('login.attempt'), ['email' => 'audit@example.test', 'password' => 'long-audit-password-123'])
        ->assertRedirect(route('login'))->assertSessionHasErrors('email');
    expect(session()->has(PlatformAuth::SESSION_KEY))->toBeFalse();
    authorizeRequest(['client_id' => $client->client_id, 'redirect_uri' => 'https://audit.example.test/cb', 'scope' => 'openid offline_access'])
        ->assertRedirect();
    expect(AuthorizationCode::query()->count())->toBe(0);

    // The passkey boundary is faked here; the engine's real WebAuthn vectors test cryptography.
    fakePasskeys($subject->id);
    $this->postJson('/passkeys/login/options')->assertOk();
    $this->postJson('/passkeys/login', ['id' => 'audit-existing-credential', 'response' => []])->assertOk();
    assertSecurityRegressionTokens($client->client_id);
});

it('holds an existing weak session before account access and factor replacement', function (): void {
    [$subject] = securityRegressionFixture();
    securityRegressionPasskey($subject->id);
    $session = app(SessionManager::class)->start($subject->id, null, ['pwd']);
    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(mfa: MfaRequirement::Required));
    $this->withSession([PlatformAuth::SESSION_KEY => $session->id])->get(route('account'))->assertRedirect(route('login'));
    expect(app(SessionManager::class)->active($session->id))->toBeNull();
});

it('refuses silent OAuth authorization from a weak session even though a factor is enrolled', function (): void {
    [$subject, $org, $client] = securityRegressionFixture();
    securityRegressionPasskey($subject->id);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(mfa: MfaRequirement::Required));
    $this->withSession([PlatformAuth::SESSION_KEY => $session->id]);
    $response = authorizeRequest(['client_id' => $client->client_id, 'redirect_uri' => 'https://audit.example.test/cb', 'scope' => 'openid offline_access', 'prompt' => 'none']);
    expect($response->headers->get('Location'))->toContain('error=interaction_required');
    expect(AuthorizationCode::query()->count())->toBe(0);
});

it('refuses embedded password sign-in without issuing a login ticket when a passkey is required', function (): void {
    [$subject] = securityRegressionFixture();
    securityRegressionPasskey($subject->id);
    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(mfa: MfaRequirement::Required));
    $key = app(PublishableKeys::class)->issue('Audit', KeyMode::Test, ['https://audit.example.test']);
    $this->postJson('/frontend/v1/sign-in', ['email' => 'audit@example.test', 'password' => 'long-audit-password-123'], [
        'X-Cbox-Publishable-Key' => $key->key, 'Origin' => 'https://audit.example.test',
    ])->assertOk()->assertJsonPath('status', 'passkey_required')->assertJsonMissingPath('login_ticket');
    expect(session()->has(PlatformAuth::SESSION_KEY))->toBeFalse();
});

it('refuses code redemption after deactivation through the application token endpoint', function (): void {
    [$subject, $org, $client] = securityRegressionFixture();
    $code = app(AuthorizationCodes::class)->issue($client->client_id, $subject->id, $org->id, 'https://audit.example.test/cb', ['openid', 'offline_access'], pkceChallenge());
    app(Subjects::class)->deactivate($subject->id);
    $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $client->client_id, 'code' => $code, 'redirect_uri' => 'https://audit.example.test/cb', 'code_verifier' => pkcePair()['verifier']])
        ->assertStatus(400)->assertJsonPath('error', 'invalid_grant')->assertJsonMissingPath('access_token')->assertJsonMissingPath('refresh_token');
});

it('keeps magic link sign-in working when no second factor is required or enrolled', function (): void {
    [$subject, $org, $client] = securityRegressionFixture();
    $link = app(MagicLink::class)->request('audit@example.test');
    $this->post(route('magic.redeem.store', $link))->assertRedirect(route('dashboard'));
    assertSecurityRegressionTokens($client->client_id);
});

function securityRegressionPasskey(string $subjectId): void
{
    WebAuthnCredential::query()->create([
        'user_id' => $subjectId, 'credential_id' => 'audit-existing-credential',
        'public_key' => 'unused-in-this-flow', 'sign_count' => 0,
    ]);
}

function assertSecurityRegressionTokens(string $clientId): void
{
    $authorization = authorizeRequest(['client_id' => $clientId, 'redirect_uri' => 'https://audit.example.test/cb', 'scope' => 'openid offline_access']);
    $authorization->assertRedirect();
    parse_str(parse_url($authorization->headers->get('Location'), PHP_URL_QUERY) ?? '', $query);
    expect($query)->toHaveKey('code');
    test()->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $query['code'], 'redirect_uri' => 'https://audit.example.test/cb', 'code_verifier' => pkcePair()['verifier']])
        ->assertOk()->assertJsonStructure(['access_token', 'refresh_token', 'id_token']);
}
