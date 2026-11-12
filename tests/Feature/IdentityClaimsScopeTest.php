<?php

declare(strict_types=1);

use App\Platform\Console\EnvironmentConsolePath;
use App\Platform\CurrentUser;
use App\Platform\Locale\ConsoleScopeCopy;
use App\Platform\Locale\HostedLocale;
use App\Platform\PlatformAuth;
use App\Platform\ScopeCatalog;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Crypto\Contracts\TokenSigner;
use Cbox\Id\Kernel\Crypto\Enums\SigningAlg;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Enums\EnvironmentApiScope;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;

/*
|--------------------------------------------------------------------------
| `identity_claims`: THE ID TOKEN CARRIES WHAT USERINFO ANSWERS, FOR A CLIENT AN OPERATOR CHOSE
|--------------------------------------------------------------------------
|
| laravel-id 2.7 mints the full identity claims on the ID token only for a client that has
| the `identity_claims` scope registered AND asks for it, so its backend can skip the
| UserInfo call after the code exchange. It is opt-in because the ID token comes back as
| `id_token_hint` at logout, and a token that names the person must not sit in a URL.
|
| What this file holds the APP to:
|
|  - an operator can give the scope to an app from the scope picker and over the management
|    API, in a tenant's environment and in the platform root's, the same way;
|  - nobody else can: discovery does not advertise it and a self-registering client is
|    not allowed it;
|  - an app that asks for it without holding it is told `invalid_scope`, like any other
|    scope it is not registered for. That is what an SDK must plan for;
|  - with it the ID token carries the person, without it the ID token is as small as before.
*/

const IDENTITY_CLAIMS_REDIRECT = 'https://app.identity-claims.test/cb';

/** A signed-in owner of one organization, as the authorize endpoint will see them. */
function identityClaimsPerson(): array
{
    $subject = app(Subjects::class)->create('ada@identity-claims.test', 'Ada Lovelace', 'a-long-enough-passphrase-1');
    app(Subjects::class)->markEmailVerified($subject->id, 'ada@identity-claims.test');
    $org = app(Organizations::class)->create(new NewOrganization('Analytical Engines', 'analytical-engines'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);

    return [$subject->id, $org->id];
}

/** A first-party public app (no consent screen), holding exactly $scopes. */
function identityClaimsApp(string $orgId, array $scopes): string
{
    return app(ClientRegistry::class)->register(new NewClient(
        'Reports',
        ClientType::Public,
        redirectUris: [IDENTITY_CLAIMS_REDIRECT],
        grantTypes: ['authorization_code'],
        scopes: $scopes,
        firstParty: true,
        organizationId: $orgId,
    ))->client->client_id;
}

/**
 * Sign in to $clientId asking for $scope and redeem the code: the verified ID token claims.
 *
 * @return array<string, mixed>
 */
function identityClaimsIdToken(string $clientId, string $scope): array
{
    $authorization = authorizeRequest(['client_id' => $clientId, 'redirect_uri' => IDENTITY_CLAIMS_REDIRECT, 'scope' => $scope]);
    $authorization->assertRedirect();
    parse_str((string) parse_url((string) $authorization->headers->get('Location'), PHP_URL_QUERY), $query);

    expect($query)->toHaveKey('code');

    $body = test()->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $clientId,
        'code' => $query['code'],
        'redirect_uri' => IDENTITY_CLAIMS_REDIRECT,
        'code_verifier' => pkcePair()['verifier'],
    ])->assertOk()->json();

    return app(TokenSigner::class)->verify((string) $body['id_token'], [SigningAlg::RS256])->all();
}

/*
| The catalogue and its words
*/

it('lists identity_claims among the sign-in scopes, not ticked by default', function (): void {
    $entry = collect(app(ScopeCatalog::class)->all())->firstWhere('key', 'identity_claims');

    expect($entry)->not->toBeNull()
        ->and($entry['category'] ?? null)->toBe(ScopeCatalog::SIGN_IN)
        ->and($entry['recommended'] ?? null)->toBeFalse()
        ->and(app(ScopeCatalog::class)->signInDefaults())->not->toContain('identity_claims')
        ->and(app(ScopeCatalog::class)->consentLabels())->toHaveKey('identity_claims');
});

it('describes identity_claims to an administrator and to the person consenting, in every language', function (string $locale): void {
    app()->setLocale($locale);

    $label = ConsoleScopeCopy::label('identity_claims');
    $description = ConsoleScopeCopy::description('identity_claims');
    $consent = ConsoleScopeCopy::consent('identity_claims');

    // Its own words in each language file: not the bare key, and not English borrowed
    // through the translator's fallback.
    expect($label)->not->toBe('identity_claims')
        ->and($description)->toBeString()->not->toBe('')
        ->and($consent)->toBeString()->not->toBe('')
        ->and(Lang::hasForLocale('oauth.consent.scopes.identity_claims', $locale))->toBeTrue();

    // The picker draws the translated copy.
    $drawn = collect(app(ScopeCatalog::class)->grouped())->flatten(1)->firstWhere('key', 'identity_claims');

    expect($drawn['label'] ?? null)->toBe($label)
        ->and($drawn['description'] ?? null)->toBe($description)
        // The warning an operator needs before ticking it survives translation: the scope
        // is named for what the app must do at logout.
        ->and($description)->toContain('id_token_hint');
})->with(fn (): array => HostedLocale::codes());

/*
| Who can give it
*/

it('is not advertised in discovery and not allowed to a self-registering client', function (): void {
    $advertised = $this->getJson('/.well-known/openid-configuration')->assertOk()->json('scopes_supported');

    expect($advertised)->toBeArray()->not->toContain('identity_claims')
        ->and(config('cbox-id.oauth.dynamic_registration.allowed_scopes'))->not->toContain('identity_claims');
});

it('lets a management key give an app identity_claims over the API', function (): void {
    $key = app(EnvironmentApiKeys::class)->issue('env_test', 'Apps worker', [
        EnvironmentApiScope::AppsRead->value,
        EnvironmentApiScope::AppsWrite->value,
    ])->plaintext;

    $app = $this->withToken($key)->postJson('/api/v1/apps', [
        'name' => 'Reports',
        'type' => 'web',
        'redirect_uris' => [IDENTITY_CLAIMS_REDIRECT],
    ])->assertCreated()->json('data');

    $this->withToken($key)->putJson("/api/v1/apps/{$app['id']}/scopes", ['scopes' => ['openid', 'profile', 'email', 'identity_claims']])
        ->assertOk();

    expect(Client::query()->findOrFail($app['id'])->scopes)->toEqualCanonicalizing(['openid', 'profile', 'email', 'identity_claims']);
});

it('offers and saves identity_claims on the scope picker of a tenant environment', function (): void {
    crudSetup();

    $app = app(ClientRegistry::class)->register(new NewClient(
        name: 'Back office',
        type: ClientType::Confidential,
        redirectUris: [IDENTITY_CLAIMS_REDIRECT],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
    ))->client;

    $offered = collect((array) test()->get(route('environment.clients.scopes', $app->id))->assertOk()->inertiaProps('scopeGroups'))
        ->flatten(1)->pluck('key')->all();

    expect($offered)->toContain('identity_claims');

    confirmConsoleStepUp();
    test()->from(route('environment.clients.scopes', $app->id))
        ->put(route('environment.clients.scopes.update', $app->id), ['scopes' => ['openid', 'identity_claims'], 'customScopes' => ''])
        ->assertSessionHasNoErrors();

    expect($app->refresh()->scopes)->toEqualCanonicalizing(['openid', 'identity_claims']);
});

it('offers and saves identity_claims on the scope picker of the platform root environment, the same way', function (): void {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    multiTenantDeployment('cboxid.com');
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);
    provisionAccount('owner@acme.example');
    $operator = actAsOperator('ops@platform.test');
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified((string) $operator->subject_id, 'ops@platform.test'));

    $app = app(PlatformRoot::class)->run(fn (): Client => app(ClientRegistry::class)->register(new NewClient(
        name: 'Cbox Billing',
        type: ClientType::Confidential,
        redirectUris: [IDENTITY_CLAIMS_REDIRECT],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
        firstParty: true,
    ))->client);

    $root = [EnvironmentConsolePath::PARAMETER => EnvironmentConsolePath::ROOT, 'client' => $app->id];
    $page = 'https://cboxid.com'.route('environment.clients.scopes', $root, false);

    $offered = collect((array) $this->get($page)->assertOk()->inertiaProps('scopeGroups'))->flatten(1)->pluck('key')->all();

    expect($offered)->toContain('identity_claims');

    useEnvironmentConsole(app(PlatformRoot::class)->model()->id);
    confirmConsoleStepUp();
    $this->from($page)
        ->put('https://cboxid.com'.route('environment.clients.scopes.update', $root, false), ['scopes' => ['openid', 'identity_claims'], 'customScopes' => ''])
        ->assertSessionHasNoErrors();

    expect(app(PlatformRoot::class)->run(fn (): array => array_values($app->refresh()->scopes)))->toEqualCanonicalizing(['openid', 'identity_claims']);
});

/*
| What an app that asks for it is answered
*/

it('answers invalid_scope to an app that asks for identity_claims without holding it', function (): void {
    [, $orgId] = identityClaimsPerson();
    $clientId = identityClaimsApp($orgId, ['openid', 'profile', 'email']);

    // The same refusal as any scope the app is not registered for. An SDK that asked for
    // it by default would break sign-in for every app an operator has not opted in.
    authorizeRequest(['client_id' => $clientId, 'redirect_uri' => IDENTITY_CLAIMS_REDIRECT, 'scope' => 'openid profile email identity_claims', 'state' => 'st'])
        ->assertRedirect(
            IDENTITY_CLAIMS_REDIRECT.'?error=invalid_scope'
            .'&error_description='.urlencode('This application is not registered for the requested scope(s): identity_claims.')
            .'&state=st&iss='.urlencode(app(IssuerResolver::class)->issuer())
        );
});

it('puts what UserInfo answers on the ID token of an app that holds identity_claims and asks for it', function (): void {
    [$subjectId, $orgId] = identityClaimsPerson();
    $clientId = identityClaimsApp($orgId, ['openid', 'profile', 'email', 'organizations', 'identity_claims']);

    $claims = identityClaimsIdToken($clientId, 'openid profile email organizations identity_claims');

    expect($claims['sub'] ?? null)->toBe($subjectId)
        ->and($claims['email'] ?? null)->toBe('ada@identity-claims.test')
        ->and($claims['email_verified'] ?? null)->toBeTrue()
        ->and($claims['name'] ?? null)->toBe('Ada Lovelace')
        ->and($claims['org'] ?? null)->toBe($orgId)
        ->and($claims['org_slug'] ?? null)->toBe('analytical-engines')
        ->and($claims['org_role'] ?? null)->toBe('owner')
        ->and(json_decode((string) json_encode($claims['organizations'] ?? null), true))->toBe([
            ['id' => $orgId, 'name' => 'Analytical Engines', 'role' => 'owner', 'slug' => 'analytical-engines'],
        ]);
});

it('leaves the person off the ID token when the app holds identity_claims and does not ask for it', function (): void {
    [, $orgId] = identityClaimsPerson();
    $clientId = identityClaimsApp($orgId, ['openid', 'profile', 'email', 'organizations', 'identity_claims']);

    $claims = identityClaimsIdToken($clientId, 'openid profile email organizations');

    expect($claims)->toHaveKeys(['sub', 'org', 'org_name', 'org_role'])
        ->not->toHaveKeys(['email', 'email_verified', 'name', 'org_slug', 'organizations', 'roles', 'permissions']);
});

it('leaves the person off the ID token of an app that registered no scopes, whatever it asks for', function (): void {
    [, $orgId] = identityClaimsPerson();
    // An app with no registered scopes is not held to a list at /authorize (there is none
    // to hold it to). The opt-in is the client's registration, so asking is not enough.
    $clientId = identityClaimsApp($orgId, []);

    $claims = identityClaimsIdToken($clientId, 'openid profile email identity_claims');

    expect($claims)->toHaveKey('sub')
        ->not->toHaveKeys(['email', 'name', 'organizations', 'roles', 'permissions']);
})->group('security');
