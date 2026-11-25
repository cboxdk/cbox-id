<?php

declare(strict_types=1);

use App\Assistant\ServiceProvider;
use App\Platform\NativeProduct\NativeEnvironmentAccess;
use App\Platform\NativeProduct\NativeProductClient;
use App\Platform\NativeProduct\NativeProductManifest;
use Cbox\Id\AccessControl\Contracts\AppManifests;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Cbox\Id\UserProperties\Models\PropertyDocument;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

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

it('binds the embedded support session to the signed-in organization and root subject', function (): void {
    signInAsMember($this->workspace->owner->id);
    $response = $this->getJson('/support/session')->assertOk();
    $identity = JWT::decode($response->json('identity'), new Key(str_repeat('s', 48), 'HS256'));
    expect($identity->sub)->toBe($this->workspace->owner->id)
        ->and($identity->identity_namespace)->toBe('cbox-id-platform-root')
        ->and($identity->account_id)->toBe($this->workspace->organization->id)
        ->and($identity->service_context->environment)->toBe($this->root->id)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

// Help asks Assistant for guides in the language the console is read in: the saved console
// language first, ahead of the browser's, as `locale.console` resolves it for the page.
it('asks Help for guides in the console language the person reads', function (?string $saved, string $browser, string $expected): void {
    signInAsMember($this->workspace->owner->id);
    app(EnvironmentContext::class)->set($this->root);
    if ($saved !== null) {
        PropertyDocument::query()->create(['user_id' => $this->workspace->owner->id, 'namespace' => 'preferences', 'data' => (object) ['language' => $saved], 'version' => 1]);
    }

    $response = $this->withHeader('Accept-Language', $browser)->getJson('/support/session')->assertOk();
    parse_str((string) parse_url((string) $response->json('search_url'), PHP_URL_QUERY), $search);
    parse_str((string) parse_url((string) $response->json('topics_url'), PHP_URL_QUERY), $topics);
    expect($search['locale'] ?? null)->toBe($expected)->and($topics['locale'] ?? null)->toBe($expected);
})->with([
    'Danish saved, English browser' => ['da', 'en', 'da'],
    'nothing saved, English browser' => [null, 'en', 'en'],
]);

// Help (cbox-ui 0.15) searches and reads articles from Assistant's API in the browser.
function idSupportConnectSrc(mixed $response): string
{
    return collect(explode(';', (string) $response->headers->get('Content-Security-Policy')))
        ->map(fn (string $part): string => trim($part))->first(fn (string $part): bool => str_starts_with($part, 'connect-src ')) ?? '';
}

it('admits the Assistant API origin in connect-src on a console page with Help', function (): void {
    config(['ai-assistant-connector.api_url' => 'https://api.assistant.example/api/v1']);
    signInAsMember($this->workspace->owner->id);

    expect(idSupportConnectSrc($this->get(route('dashboard'))->assertOk()))
        ->toContain(' https://api.assistant.example')->not->toContain('/api/v1');
});

it('keeps the Assistant API out of connect-src on the sign-in page', function (): void {
    config(['ai-assistant-connector.api_url' => 'https://api.assistant.example/api/v1']);

    expect(idSupportConnectSrc($this->get(route('login'))->assertOk()))->toBe("connect-src 'self'");
});

it('keeps the Assistant API out of connect-src on the consent page of a member who has Help', function (): void {
    // The shell prop is built for a signed-in member here, but the consent page does not draw it.
    config(['ai-assistant-connector.api_url' => 'https://api.assistant.example/api/v1']);
    signInAsMember($this->workspace->owner->id);
    $client = app(PlatformRoot::class)->run(fn () => app(ClientRegistry::class)->register(
        new NewClient('App', redirectUris: ['https://app.test/cb'], organizationId: $this->workspace->organization->id),
    ))->client->client_id;

    $consent = authorizeRequest(['client_id' => $client, 'redirect_uri' => 'https://app.test/cb', 'scope' => 'openid'])
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('oauth/consent'));

    expect(idSupportConnectSrc($consent))->toBe("connect-src 'self'");
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

it('shows a guide in Danish to a Danish reader and in English to anyone else', function (): void {
    $this->get('/ai-assistant/guides/id-onboarding', ['Accept-Language' => 'da-DK,da;q=0.9,en;q=0.5'])->assertOk()
        ->assertSee('<html lang="da"', false)->assertSee('Kom i gang med dine brugere, organisationer og sikkerhed');
    $this->get('/ai-assistant/guides/id-onboarding', ['Accept-Language' => 'sv'])->assertOk()
        ->assertSee('<html lang="en"', false)->assertSee('Getting started with your users, organizations and security');
});

it('publishes a Danish version of every English guide, each with its own file', function (): void {
    $manifest = require resource_path('assistant/manifest.php');
    foreach ($manifest as $key => $document) {
        expect(resource_path('assistant/'.$document['file']))->toBeFile("{$key}: {$document['file']} is missing");
    }
    $paths = fn (string $locale): array => collect($manifest)->where('locale', $locale)->pluck('path')->sort()->values()->all();
    expect($paths('da'))->toBe($paths('en'), 'Every English guide needs a Danish entry with the same path');
    expect(collect($manifest)->pluck('file')->duplicates()->all())->toBe([]);
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

it('hydrates the signed organization card using current membership and rejects forged scope and replays', function (): void {
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

it('does not expose operational support data through a member without a native role or with billing only', function (array $roles): void {
    [$member, $subject] = addMember($this->workspace->organization->id, MembershipRole::Member, 'support-limited@example.test');
    grantNativeProductRoles($member, $roles);
    $this->claims['sub'] = $subject;
    $this->withToken(idSupportToken($this, $this->claims))->postJson('/ai-assistant/hook')->assertForbidden();
    $this->withToken(idSupportToken($this, $this->claims, 'id_workspace_status'))->postJson('/ai-assistant/hook')->assertStatus($roles === [] ? 403 : 200);
})->with(['member without native role' => [[]], 'native billing only' => [['billing']]]);

it('rechecks native Reader revocation on the next signed support action', function (): void {
    [$member, $subject] = addMember($this->workspace->organization->id, MembershipRole::Member, 'support-revoked@example.test');
    grantNativeProductRoles($member, ['viewer']);
    $this->claims['sub'] = $subject;
    $this->withToken(idSupportToken($this, $this->claims))->postJson('/ai-assistant/hook')->assertOk();
    grantNativeProductRoles($member, []);
    $this->withToken(idSupportToken($this, $this->claims))->postJson('/ai-assistant/hook')->assertForbidden();
});
