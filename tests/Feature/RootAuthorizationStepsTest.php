<?php

declare(strict_types=1);

use App\Platform\OAuth\PendingAuthorization;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| The steps after /oauth/authorize, on the platform root
|--------------------------------------------------------------------------
|
| On the SaaS shape the platform root serves the token endpoints for our OWN first-party
| app and nobody else's (`plane:first-party`). The gate read the client from `client_id` —
| and the consent screen, approve/deny and the hosted organization picker name only a
| PENDING authorization, so on the root they asked about client '' and 404'd for the one
| client the root exists to serve. They now ask about the pending authorization's client,
| and only that.
*/

beforeEach(function (): void {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);

    multiTenantDeployment();
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);

    Environment::query()->create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active', 'is_default' => false]);
    $root = Environment::query()->create(['name' => 'Platform', 'slug' => 'platform', 'status' => 'active']);
    $root->makeDefault();
});

/**
 * Somebody on the root in two workspaces, signed in, and a client registered on the root.
 *
 * @return array{subjectId: string, acme: string, globex: string, clientId: string}
 */
function rootAuthorizationWorld(bool $platformOwned = true): array
{
    return app(PlatformRoot::class)->run(function () use ($platformOwned): array {
        $subject = app(Subjects::class)->create('pat@acme.test', 'Pat Doe', 'a-strong-unbreached-passphrase');
        $acme = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-root-steps'));
        $globex = app(Organizations::class)->create(new NewOrganization('Globex', 'globex-root-steps'));

        app(Memberships::class)->add($acme->id, $subject->id, MembershipRole::Owner);
        app(Memberships::class)->add($globex->id, $subject->id, MembershipRole::Member);

        $client = app(ClientRegistry::class)->register(new NewClient(
            'Cbox Authenticator',
            ClientType::Public,
            redirectUris: ['https://app.test/cb'],
            grantTypes: ['authorization_code', 'refresh_token'],
            scopes: ['openid', 'profile'],
            firstParty: true,
            organizationId: $platformOwned ? null : $acme->id,
        ))->client;

        return ['subjectId' => $subject->id, 'acme' => $acme->id, 'globex' => $globex->id, 'clientId' => $client->client_id];
    }) ?? [];
}

/** @param  array<string, string>  $extra */
function rootAuthorize(string $clientId, array $extra = []): TestResponse
{
    return test()->get('http://cboxid.com/oauth/authorize?'.http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'response_type' => 'code',
        'scope' => 'openid profile',
        'state' => 'st',
        'code_challenge' => pkcePair()['challenge'],
        'code_challenge_method' => 'S256',
        ...$extra,
    ]));
}

it('serves the organization picker on the platform root for our own first-party app', function (): void {
    ['subjectId' => $subjectId, 'globex' => $globex, 'clientId' => $clientId] = rootAuthorizationWorld();
    signInAsMember($subjectId);

    $picker = (string) rootAuthorize($clientId, ['prompt' => 'select_organization'])
        ->assertRedirect()->headers->get('Location');

    expect($picker)->toContain('/oauth/authorize/')->toEndWith('/organization');

    $props = (array) test()->get($picker)->assertOk()->inertiaProps();

    expect(array_column($props['organizations'], 'name'))->toBe(['Acme', 'Globex']);

    $left = leftFor(inertiaRequest(fn () => test()->from($picker)->post((string) $props['chooseHref'], ['organization' => $globex])));

    expect($left)->toStartWith('https://app.test/cb?code=');
});

it('lets our own first-party app be approved on the platform root\'s consent screen', function (): void {
    ['subjectId' => $subjectId, 'clientId' => $clientId] = rootAuthorizationWorld();
    signInAsMember($subjectId);

    // prompt=consent draws the screen even for a first-party app. Its answer is a POST to
    // a route that names only the pending request — the one that 404'd on the root.
    $props = (array) rootAuthorize($clientId, ['prompt' => 'consent'])->assertOk()->inertiaProps();

    expect($props['client']['name'] ?? null)->toBe('Cbox Authenticator');

    $left = leftFor(inertiaRequest(fn () => test()->post((string) $props['approveHref'])));

    expect($left)->toStartWith('https://app.test/cb?code=');
});

it('refuses a step whose pending authorization is for a client the root does not serve', function (): void {
    ['subjectId' => $subjectId, 'acme' => $acme, 'clientId' => $ours] = rootAuthorizationWorld();
    signInAsMember($subjectId);

    // A pending authorization no /oauth/authorize on this host would have written — an
    // organization's own client — planted in the session the way a shared cookie domain
    // could carry one across. Named with OUR client_id beside it, too: the step answers
    // for the pending request's client and nothing the browser adds.
    $theirs = app(PlatformRoot::class)->run(fn () => app(ClientRegistry::class)->register(new NewClient(
        'Their app',
        ClientType::Public,
        redirectUris: ['https://evil.test/cb'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
        firstParty: true,
        organizationId: $acme,
    ))->client->client_id);

    $id = (string) Str::ulid();
    session(['oauth.pending' => [$id => (new PendingAuthorization(
        clientId: (string) $theirs,
        clientName: 'Their app',
        clientOwner: 'Acme',
        redirectUri: 'https://evil.test/cb',
        scopes: ['openid'],
        codeChallenge: pkcePair()['challenge'],
        codeChallengeMethod: 'S256',
    ))->toArray()]]);

    test()->get('http://cboxid.com/oauth/authorize/'.$id.'/organization?client_id='.$ours)->assertNotFound();
    test()->get('http://cboxid.com/oauth/authorize/'.$id)->assertNotFound();
    test()->post('http://cboxid.com/oauth/authorize/'.$id.'/approve', ['client_id' => $ours])->assertNotFound();

    // And an id this session does not hold names no client at all.
    test()->get('http://cboxid.com/oauth/authorize/'.Str::ulid().'/organization?client_id='.$ours)->assertNotFound();
})->group('security');
