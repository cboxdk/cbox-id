<?php

declare(strict_types=1);

use App\Assistant\ServiceProvider;
use App\Platform\NativeProduct\NativeEnvironmentAccess;
use App\Platform\NativeProduct\NativeProductClient;
use App\Platform\NativeProduct\NativeProductManifest;
use Cbox\Id\AccessControl\Contracts\AppManifests;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    config(['ai-assistant-connector.support.enabled' => true, 'ai-assistant-connector.support.widget' => 'id-support',
        'ai-assistant-connector.support.identity_namespace' => 'cbox-id-platform-root',
        'ai-assistant-connector.platform.url' => 'https://assistant.example', 'ai-assistant-connector.handshake_secret' => str_repeat('s', 48),
        'ai-assistant-connector.audience' => 'localhost', 'ai-assistant-connector.jwks_url' => 'https://assistant.example/jwks']);
    new ServiceProvider(app())->boot();
    $this->root = platformRootEnvironment();
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    $this->workspace = app(TenantProvisioner::class)->provision(new TenantBlueprint(organizationName: 'Acme', ownerEmail: 'owner@acme.example', ownerName: 'Owner', ownerPassword: 'support-fixture-12345'));
    app(NativeProductClient::class)->synchronize(app(AppManifests::class), new NativeProductManifest);
    $pair = sodium_crypto_sign_keypair();
    $this->signingKey = base64_encode(sodium_crypto_sign_secretkey($pair));
    Http::fake(['https://assistant.example/jwks' => Http::response(['keys' => [[
        'kid' => 'support', 'kty' => 'OKP', 'crv' => 'Ed25519', 'alg' => 'EdDSA',
        'x' => rtrim(strtr(base64_encode(sodium_crypto_sign_publickey($pair)), '+/', '-_'), '='),
    ]]])]);
    $this->claims = ['sub' => $this->workspace->owner->id, 'account_id' => $this->workspace->organization->id, 'verified' => true,
        'context' => ['service' => 'cbox-id', 'tenant' => $this->workspace->organization->id, 'environment' => $this->root->id]];
});

function idSupportToken(mixed $test, array $principal, string $tool = 'id_environment_status'): string
{
    return JWT::encode(['aud' => 'localhost', 'iat' => time(), 'exp' => time() + 120, 'jti' => Str::uuid()->toString(),
        'cnt' => str_repeat('s', 48), 'tool' => $tool, 'principal' => $principal, 'arguments' => []], $test->signingKey, 'EdDSA', 'support');
}

it('binds the embedded support session to the signed-in workspace and root subject', function (): void {
    signInAsMember($this->workspace->owner->id);
    $response = $this->getJson('/support/session')->assertOk();
    $identity = JWT::decode($response->json('identity'), new Key(str_repeat('s', 48), 'HS256'));
    expect($identity->sub)->toBe($this->workspace->owner->id)
        ->and($identity->identity_namespace)->toBe('cbox-id-platform-root')
        ->and($identity->account_id)->toBe($this->workspace->organization->id)
        ->and($identity->service_context->environment)->toBe($this->root->id)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('executes a signed bridge tool and limits environments to the members current grants', function (): void {
    [$member, $subject] = addMember($this->workspace->organization->id, MembershipRole::Member, 'support-reader@example.test');
    grantNativeProductRoles($member, ['viewer']);
    $rows = app(NativeEnvironmentAccess::class);
    $rows->replace($member, true, [], $rows->version($member));
    $this->claims['sub'] = $subject;
    $token = idSupportToken($this, $this->claims);
    $this->withToken($token)->postJson('/ai-assistant/hook')->assertOk()->assertJsonPath('result.organization', 'Acme')->assertJsonCount(1, 'result.projects.0.environments');
    $this->withToken($token)->postJson('/ai-assistant/hook')->assertUnauthorized();
    $rows->replace($member, false, [], $rows->version($member));
    $this->withToken(idSupportToken($this, $this->claims))->postJson('/ai-assistant/hook')->assertOk()->assertJsonCount(0, 'result.projects');
});

it('rejects another account, a foreign environment and a revoked membership', function (): void {
    $this->withToken(idSupportToken($this, [...$this->claims, 'account_id' => 'other']))->postJson('/ai-assistant/hook')->assertForbidden();
    $bad = [...$this->claims, 'context' => [...$this->claims['context'], 'environment' => 'other-env']];
    $this->withToken(idSupportToken($this, $bad))->postJson('/ai-assistant/hook')->assertForbidden();
    app(PlatformRoot::class)->run(fn () => $this->workspace->membership->delete());
    $this->withToken(idSupportToken($this, $this->claims))->postJson('/ai-assistant/hook')->assertForbidden();
});

it('serves only the approved generated guides', function (): void {
    $this->get('/ai-assistant/guides/id-laravel')->assertOk()->assertSee('Create the app');
    $this->get('/ai-assistant/guides/private')->assertNotFound();
});

it('reads onboarding through the signed bridge without exposing other environments', function (): void {
    [$member, $subject] = addMember($this->workspace->organization->id, MembershipRole::Member, 'onboarding-reader@example.test');
    grantNativeProductRoles($member, ['viewer']);
    $rows = app(NativeEnvironmentAccess::class);
    $rows->replace($member, true, [], $rows->version($member));
    $this->claims['sub'] = $subject;
    $token = idSupportToken($this, $this->claims, 'id_onboarding_status');
    $this->withToken($token)->postJson('/ai-assistant/hook')->assertOk()
        ->assertJsonCount(1, 'result.environments')
        ->assertJsonPath('result.environments.0.workspace_id', $this->workspace->organization->id)
        ->assertJsonPath('result.environments.0.production_ready', null);
    $rows->replace($member, false, [], $rows->version($member));
    $this->withToken(idSupportToken($this, $this->claims, 'id_onboarding_status'))
        ->postJson('/ai-assistant/hook')->assertOk()->assertJsonCount(0, 'result.environments');
    $foreign = [...$this->claims, 'account_id' => 'other'];
    $this->withToken(idSupportToken($this, $foreign, 'id_onboarding_status'))
        ->postJson('/ai-assistant/hook')->assertForbidden();
});

it('hydrates the signed workspace card using current membership and rejects forged scope and replays', function (): void {
    $url = '/ai-assistant/inbox/id_workspace';
    $token = idSupportToken($this, $this->claims);
    $this->withToken($token)->getJson($url)->assertOk()
        ->assertJsonPath('data.organization', 'Acme')->assertJsonPath('data.role', 'owner');
    $this->withToken($token)->getJson($url)->assertUnauthorized();
    $this->withToken(idSupportToken($this, [...$this->claims, 'verified' => false]))->getJson($url)->assertForbidden();
    $this->withToken(idSupportToken($this, [...$this->claims, 'account_id' => 'other']))->getJson($url)->assertForbidden();
    $bad = [...$this->claims, 'context' => [...$this->claims['context'], 'service' => 'cbox-billing']];
    $this->withToken(idSupportToken($this, $bad))->getJson($url)->assertForbidden();
    app(PlatformRoot::class)->run(fn () => $this->workspace->membership->delete());
    $this->withToken(idSupportToken($this, $this->claims))->getJson($url)->assertForbidden();
});

it('does not expose operational support data through shared admin or billing-only membership', function (array $roles): void {
    [$member, $subject] = addMember($this->workspace->organization->id, MembershipRole::Admin, 'support-limited@example.test');
    grantNativeProductRoles($member, $roles);
    $this->claims['sub'] = $subject;
    $this->withToken(idSupportToken($this, $this->claims))->postJson('/ai-assistant/hook')->assertForbidden();
    $this->withToken(idSupportToken($this, $this->claims, 'id_workspace_status'))->postJson('/ai-assistant/hook')->assertStatus($roles === [] ? 403 : 200);
})->with(['shared admin without native role' => [[]], 'native billing only' => [['billing']]]);

it('rechecks native Reader revocation on the next signed support action', function (): void {
    [$member, $subject] = addMember($this->workspace->organization->id, MembershipRole::Member, 'support-revoked@example.test');
    grantNativeProductRoles($member, ['viewer']);
    $this->claims['sub'] = $subject;
    $this->withToken(idSupportToken($this, $this->claims))->postJson('/ai-assistant/hook')->assertOk();
    grantNativeProductRoles($member, []);
    $this->withToken(idSupportToken($this, $this->claims))->postJson('/ai-assistant/hook')->assertForbidden();
});
