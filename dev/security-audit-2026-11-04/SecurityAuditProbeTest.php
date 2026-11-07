<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\MagicLink;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\MfaRequirement;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Kernel\Crypto\Contracts\TokenSigner;
use Cbox\Id\Kernel\Crypto\Enums\SigningAlg;
use Cbox\Id\Kernel\Crypto\TotpAuthenticator;
use Cbox\Id\OAuthServer\Contracts\AuthorizationCodes;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\TokenIntrospector;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;

beforeEach(fn () => installedDeployment());

function securityAuditFixture(): array
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

it('audit reproduces magic link bypassing enrolled required TOTP and issuing OAuth tokens', function () {
    [$subject, $org, $client] = securityAuditFixture();
    $enrollment = app(Mfa::class)->enrollTotp($subject->id, 'audit@example.test');
    app(Mfa::class)->confirmTotp($subject->id, (new TotpAuthenticator)->codeAt($enrollment->secret, time()));
    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(mfa: MfaRequirement::Required));
    $link = app(MagicLink::class)->request('audit@example.test');
    $this->post(route('magic.redeem.store', $link))->assertRedirect();
    $session = app(SessionManager::class)->active(session(PlatformAuth::SESSION_KEY));
    expect($session->amr)->toBe(['magic_link']);
    $authorization = authorizeRequest(['client_id' => $client->client_id, 'redirect_uri' => 'https://audit.example.test/cb', 'scope' => 'openid offline_access']);
    $authorization->assertRedirect();
    parse_str(parse_url($authorization->headers->get('Location'), PHP_URL_QUERY) ?? '', $query);
    expect($query)->toHaveKey('code');
    $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $client->client_id, 'code' => $query['code'], 'redirect_uri' => 'https://audit.example.test/cb', 'code_verifier' => pkcePair()['verifier']])->assertOk()->assertJsonStructure(['access_token', 'refresh_token', 'id_token']);
});

it('audit reproduces code redemption and refresh after account deactivation', function () {
    [$subject, $org, $client] = securityAuditFixture();
    $code = app(AuthorizationCodes::class)->issue($client->client_id, $subject->id, $org->id, 'https://audit.example.test/cb', ['openid', 'offline_access'], pkceChallenge());
    app(Subjects::class)->deactivate($subject->id);
    $response = $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $client->client_id, 'code' => $code, 'redirect_uri' => 'https://audit.example.test/cb', 'code_verifier' => pkcePair()['verifier']])->assertOk()->assertJsonStructure(['access_token', 'refresh_token', 'id_token']);
    $claims = app(TokenSigner::class)->verify($response->json('id_token'), [SigningAlg::RS256]);
    expect($claims->subject())->toBe($subject->id);
    expect(app(TokenIntrospector::class)->introspect($response->json('access_token'))->active)->toBeFalse();
    $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $client->client_id, 'refresh_token' => $response->json('refresh_token')])->assertOk()->assertJsonStructure(['access_token', 'refresh_token', 'id_token']);
});

it('audit reproduces password-only login despite required MFA when a passkey is enrolled', function () {
    [$subject, $org, $client] = securityAuditFixture();
    // The defect is the policy's credential-existence check; no WebAuthn assertion is submitted.
    WebAuthnCredential::query()->create([
        'user_id' => $subject->id, 'credential_id' => 'audit-existing-credential',
        'public_key' => 'unused-in-this-flow', 'sign_count' => 0,
    ]);
    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(mfa: MfaRequirement::Required));
    $this->post(route('login.attempt'), ['email' => 'audit@example.test', 'password' => 'long-audit-password-123'])->assertRedirect(route('dashboard'));
    $session = app(SessionManager::class)->active(session(PlatformAuth::SESSION_KEY));
    expect($session->amr)->toBe(['pwd']);
    $authorization = authorizeRequest(['client_id' => $client->client_id, 'redirect_uri' => 'https://audit.example.test/cb', 'scope' => 'openid offline_access']);
    $authorization->assertRedirect();
    parse_str(parse_url($authorization->headers->get('Location'), PHP_URL_QUERY) ?? '', $query);
    expect($query)->toHaveKey('code');
    $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $client->client_id, 'code' => $query['code'], 'redirect_uri' => 'https://audit.example.test/cb', 'code_verifier' => pkcePair()['verifier']])->assertOk()->assertJsonStructure(['access_token', 'refresh_token', 'id_token']);
});
