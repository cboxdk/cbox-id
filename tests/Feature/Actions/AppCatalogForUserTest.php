<?php

declare(strict_types=1);

use App\Platform\Api\ApiHost;
use App\Platform\AppCatalog\AppCatalog;
use App\Platform\AppCatalog\CatalogEntryData;
use Cbox\Id\FeatureFlags\Contracts\FeatureFlags;
use Cbox\Id\FeatureFlags\ValueObjects\NewFeatureFlag;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\TokenIssuer;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeDelegatedTokens;

/*
 * GET /api/v1/app-catalog/for-user — an app's backend reads the switcher of a person it
 * signed in, with its own client-credentials token and no grant from the person.
 */

function forUserEntry(array $overrides = []): CatalogEntryData
{
    return CatalogEntryData::fromArray([...['version' => 0, 'name' => 'App', 'launch_url' => 'https://app.example.com/start', 'enabled' => true, 'position' => 10, 'audience' => 'everyone'], ...$overrides]);
}

function forUserClient(array $scopes = ['app_catalog:resolve']): Client
{
    return app(ClientRegistry::class)->register(new NewClient(name: 'Brain', grantTypes: ['authorization_code', 'client_credentials'], scopes: ['openid', ...$scopes]))->client;
}

/** @param  list<string>  $scopes */
function forUserMachineToken(array $scopes = ['app_catalog:resolve']): string
{
    return app(TokenIssuer::class)->issueClientCredentials(forUserClient($scopes), $scopes)->token;
}

function forUserRequest(string $token, string $userId, ?string $organizationId): TestResponse
{
    return test()->withToken($token)->getJson('/api/v1/app-catalog/for-user?'.http_build_query(array_filter(['user_id' => $userId, 'organization_id' => $organizationId])));
}

beforeEach(function (): void {
    $this->ada = app(Subjects::class)->create('ada@for-user.example', 'Ada')->id;
    $this->acme = app(Organizations::class)->create(new NewOrganization('Acme', 'acme'))->id;
    $this->globex = app(Organizations::class)->create(new NewOrganization('Globex', 'globex'))->id;
    app(Memberships::class)->add($this->acme, $this->ada, MembershipRole::Member);

    $catalog = app(AppCatalog::class);
    // Saved out of display order, so the answer's order is the catalog's, not insertion's.
    $catalog->save('zeta', forUserEntry(['name' => 'Zeta', 'position' => 0]));
    $catalog->save('acme-only', forUserEntry(['name' => 'Acme only', 'position' => 20, 'audience' => 'organizations', 'organization_ids' => [$this->acme]]));
    $catalog->save('alpha', forUserEntry(['name' => 'Alpha', 'position' => 10]));
    $catalog->save('globex-only', forUserEntry(['name' => 'Globex only', 'position' => 5, 'audience' => 'organizations', 'organization_ids' => [$this->globex]]));
    $catalog->save('draft', forUserEntry(['enabled' => false, 'position' => 1]));
});

it('answers an app\'s own token with the person\'s catalog, in Cbox ID\'s order and the /me/apps envelope', function (): void {
    $token = forUserMachineToken();
    $audit = AuditEntry::query()->count();

    $response = forUserRequest($token, $this->ada, $this->acme)->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('meta.refresh_after', 60);

    expect(array_column($response->json('data'), 'key'))->toBe(['zeta', 'alpha', 'acme-only'])
        ->and($response->json('meta.revision'))->toBe(hash('sha256', json_encode(app(AppCatalog::class)->forUser($this->ada, $this->acme), JSON_THROW_ON_ERROR)))
        ->and($response->json('data.0'))->not->toHaveKeys(['user_ids', 'organization_ids', 'client_ids', 'required_feature', 'icon_data'])
        ->and($response->json('data.0.launch_url'))->toContain('organization='.$this->acme)
        // A hot read path: no audit row per read.
        ->and(AuditEntry::query()->count())->toBe($audit);

    // The same items as the person's own /me/apps, so the SDK parses both alike.
    FakeDelegatedTokens::install()->person('ada-own', $this->ada, ['account:apps:read']);
    $own = $this->withToken('ada-own')->getJson('/api/v1/me/apps?organization_id='.$this->acme)->assertOk();
    expect($response->json('data'))->toBe($own->json('data'))
        ->and($response->json('meta'))->toBe($own->json('meta'));
});

it('answers a member of another organization with that organization\'s apps only', function (): void {
    $grace = app(Subjects::class)->create('grace@for-user.example', 'Grace')->id;
    app(Memberships::class)->add($this->globex, $grace, MembershipRole::Member);
    $token = forUserMachineToken();

    $response = forUserRequest($token, $grace, $this->globex)->assertOk();
    expect(array_column($response->json('data'), 'key'))->toBe(['zeta', 'globex-only', 'alpha']);

    // And nothing for an organization she is not in.
    forUserRequest($token, $grace, $this->acme)->assertNotFound()->assertJsonPath('error', 'not_found');
});

it('answers a non-member, an unknown and a deactivated person with the same 404', function (): void {
    $token = forUserMachineToken();
    $outsider = app(Subjects::class)->create('outsider@for-user.example', 'Outsider')->id;

    $nonMember = forUserRequest($token, $outsider, $this->acme)->assertNotFound()->assertJsonPath('error', 'not_found');
    $unknown = forUserRequest($token, 'usr_does_not_exist', $this->acme)->assertNotFound();
    $noOrganization = forUserRequest($token, $this->ada, 'org_does_not_exist')->assertNotFound();
    $answer = static fn (TestResponse $response): array => [$response->json('error'), $response->json('message')];
    expect($answer($unknown))->toBe($answer($nonMember))->and($answer($noOrganization))->toBe($answer($nonMember));

    app(Memberships::class)->remove($this->acme, $this->ada);
    forUserRequest($token, $this->ada, $this->acme)->assertNotFound();
});

it('requires the organization', function (): void {
    forUserRequest(forUserMachineToken(), $this->ada, null)->assertUnprocessable();
});

it('refuses an app token without app_catalog:resolve with 403', function (): void {
    forUserRequest(forUserMachineToken(['apps.manifest']), $this->ada, $this->acme)
        ->assertForbidden()->assertJsonPath('error', 'forbidden');
});

it('refuses a person\'s tokens, even one the same app holds with the scope', function (): void {
    // The person's own account token, as the switcher used to hold it.
    $account = app(TokenIssuer::class)->issueForUser(forUserClient(['account:apps:read']), $this->ada, null, ['account:apps:read'])->token;
    expect(forUserRequest($account, $this->ada, $this->acme)->status())->toBeIn([401, 403]);
    FakeDelegatedTokens::install()->person('ada-account', $this->ada, ['account:apps:read']);
    expect(forUserRequest('ada-account', $this->ada, $this->acme)->status())->toBeIn([401, 403]);

    // A person's token from the very app that holds the scope is still the person's.
    $human = app(TokenIssuer::class)->issueForUser(forUserClient(), $this->ada, null, ['app_catalog:resolve'])->token;
    expect(forUserRequest($human, $this->ada, $this->acme)->status())->toBeIn([401, 403]);
});

it('refuses a management key without the scope, and an unauthenticated call', function (): void {
    $key = app(EnvironmentApiKeys::class)->issue('env_test', 'Catalog reader', ['app_catalog:read'])->plaintext;
    forUserRequest($key, $this->ada, $this->acme)->assertForbidden();
    $this->withoutToken()->getJson('/api/v1/app-catalog/for-user?user_id='.$this->ada.'&organization_id='.$this->acme)->assertUnauthorized();
});

it('is not shadowed by the catalog entry route', function (): void {
    // `/app-catalog/{key}` would otherwise read `for-user` as an entry's key.
    $key = app(EnvironmentApiKeys::class)->issue('env_test', 'Catalog reader', ['app_catalog:resolve'])->plaintext;
    forUserRequest($key, $this->ada, $this->acme)->assertOk()->assertJsonCount(3, 'data');
});

it('reads in a fixed number of queries however many organizations the person is in', function (): void {
    $token = forUserMachineToken();
    forUserRequest($token, $this->ada, $this->acme)->assertOk();
    $counts = [];
    foreach (range(1, 6) as $number) {
        $organization = app(Organizations::class)->create(new NewOrganization('Org '.$number, 'for-user-org-'.$number))->id;
        app(Memberships::class)->add($organization, $this->ada, MembershipRole::Member);
        if (! in_array($number, [1, 6], true)) {
            continue;
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            forUserRequest($token, $this->ada, $this->acme)->assertOk();
            $counts[] = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }
    expect($counts[1])->toBe($counts[0]);
});

it('lists the apps a person has and, beside them, the ones they can ask for', function (): void {
    $catalog = app(AppCatalog::class);
    $catalog->save('globex-only', forUserEntry(['name' => 'Globex only', 'position' => 5, 'audience' => 'organizations', 'organization_ids' => [$this->globex],
        'version' => 1, 'purpose' => 'Signals for Globex', 'hue' => 'violet', 'admission_url' => 'https://globex.example.com/signup']));
    $catalog->save('alpha', forUserEntry(['name' => 'Alpha', 'position' => 10, 'version' => 1, 'purpose' => 'Time and invoices', 'hue' => 'amber']));
    // Not eligible but without an admission page, or not promoted: never "not yet".
    $catalog->save('no-page', forUserEntry(['position' => 30, 'audience' => 'organizations', 'organization_ids' => [$this->globex]]));
    $catalog->save('quiet', forUserEntry(['position' => 31, 'audience' => 'organizations', 'organization_ids' => [$this->globex],
        'admission_url' => 'https://quiet.example.com/join', 'promote_when_unavailable' => false]));
    // An unpublished app is never shown, even with an admission page.
    $catalog->save('draft-promoted', forUserEntry(['enabled' => false, 'admission_url' => 'https://draft.example.com/join']));

    $response = forUserRequest(forUserMachineToken(), $this->ada, $this->acme)->assertOk();

    expect(array_column($response->json('data'), 'key'))->toBe(['zeta', 'alpha', 'acme-only'])
        ->and($response->json('data.1'))->toMatchArray(['purpose' => 'Time and invoices', 'hue' => 'amber', 'available' => true])
        ->and($response->json('data.1'))->not->toHaveKey('admission_url')
        ->and($response->json('meta.unavailable'))->toBe([[
            'key' => 'globex-only', 'name' => 'Globex only', 'description' => null, 'purpose' => 'Signals for Globex', 'hue' => 'violet',
            'icon_url' => null, 'icon_dark_url' => null, 'position' => 5, 'version' => 2, 'available' => false,
            'admission_url' => 'https://globex.example.com/signup?organization='.$this->acme,
        ]])
        ->and($response->json('meta.revision'))->toBe(AppCatalog::revision($catalog->catalogFor($this->ada, $this->acme)));
});

it('never lists a users-audience app as "not yet" to someone it does not name', function (): void {
    $other = app(Subjects::class)->create('other@for-user.example', 'Other')->id;
    app(AppCatalog::class)->save('private', forUserEntry(['name' => 'Private', 'audience' => 'users', 'user_ids' => [$other],
        'admission_url' => 'https://private.example.com/join']));

    $response = forUserRequest(forUserMachineToken(), $this->ada, $this->acme)->assertOk();

    expect(array_column($response->json('data'), 'key'))->not->toContain('private')
        ->and($response->json('meta.unavailable'))->toBe([])
        ->and(json_encode($response->json(), JSON_THROW_ON_ERROR))->not->toContain('private.example.com');
});

it('lists an app hidden by its feature flag as "not yet" when it has an admission page', function (): void {
    $flag = app(FeatureFlags::class)->create(new NewFeatureFlag(key: 'signals-beta', defaultValue: false));
    app(AppCatalog::class)->save('signals', forUserEntry(['name' => 'Signals', 'required_feature' => $flag->key,
        'admission_url' => 'https://signals.example.com/waitlist']));

    $response = forUserRequest(forUserMachineToken(), $this->ada, $this->acme)->assertOk();

    expect(array_column($response->json('data'), 'key'))->not->toContain('signals')
        ->and(array_column($response->json('meta.unavailable'), 'key'))->toBe(['signals']);
});

it('answers the person and the app with the same two lists', function (): void {
    app(AppCatalog::class)->save('globex-only', forUserEntry(['name' => 'Globex only', 'position' => 5, 'audience' => 'organizations',
        'organization_ids' => [$this->globex], 'version' => 1, 'admission_url' => 'https://globex.example.com/signup']));
    $machine = forUserRequest(forUserMachineToken(), $this->ada, $this->acme)->assertOk();

    FakeDelegatedTokens::install()->person('ada-own', $this->ada, ['account:apps:read']);
    $own = $this->withToken('ada-own')->getJson('/api/v1/me/apps?organization_id='.$this->acme)->assertOk();

    expect($machine->json('meta.unavailable'))->toHaveCount(1)
        ->and($machine->json())->toBe($own->json());
});

it('refuses an admission URL a browser should not be sent to', function (string $url): void {
    expect(fn () => forUserEntry(['admission_url' => $url]))->toThrow(ValidationException::class);
})->with([
    'plain http' => 'http://signals.example.com/join',
    'loopback' => 'https://127.0.0.1/join',
    'private network' => 'https://10.0.0.8/join',
    'link-local metadata' => 'https://169.254.169.254/latest',
    'ipv6 loopback' => 'https://[::1]/join',
    'localhost' => 'https://localhost/join',
    'internal name' => 'https://intranet/join',
    'internal suffix' => 'https://apps.corp.internal/join',
    'credentials' => 'https://user:secret@signals.example.com/join',
    'fragment' => 'https://signals.example.com/join#x',
]);

it('keeps the switcher fields when a client that predates them saves, and clears them on null', function (): void {
    $catalog = app(AppCatalog::class);
    $catalog->save('alpha', forUserEntry(['name' => 'Alpha', 'position' => 10, 'version' => 1, 'purpose' => 'Time', 'hue' => 'amber',
        'admission_url' => 'https://alpha.example.com/join', 'promote_when_unavailable' => false]));

    $kept = $catalog->save('alpha', forUserEntry(['name' => 'Alpha renamed', 'position' => 10, 'version' => 2]));
    expect([$kept->purpose, $kept->hue, $kept->admission_url, $kept->promote_when_unavailable])->toBe(['Time', 'amber', 'https://alpha.example.com/join', false]);

    $cleared = $catalog->save('alpha', forUserEntry(['name' => 'Alpha', 'position' => 10, 'version' => 3, 'purpose' => null, 'hue' => null, 'admission_url' => null]));
    expect([$cleared->purpose, $cleared->hue, $cleared->admission_url])->toBe([null, null, null]);

    expect(fn () => forUserEntry(['hue' => 'chartreuse']))->toThrow(ValidationException::class)
        ->and(fn () => forUserEntry(['purpose' => str_repeat('x', 61)]))->toThrow(ValidationException::class);
});

it('keeps the catalog revision and refresh_after on the /v1 list, where laravel-id-client reads them', function (): void {
    $token = forUserMachineToken();
    $query = http_build_query(['user_id' => $this->ada, 'organization_id' => $this->acme]);

    $old = forUserRequest($token, $this->ada, $this->acme)->assertOk();
    $v1 = $this->withToken($token)->getJson('http://'.ApiHost::name().'/v1/app-catalog/for-user?'.$query)->assertOk();

    expect($v1->json('object'))->toBe('list')
        ->and(array_column($v1->json('data'), 'key'))->toBe(['zeta', 'alpha', 'acme-only'])
        ->and($v1->json('revision'))->toBe($old->json('meta.revision'))
        ->and($v1->json('refresh_after'))->toBe(60)
        ->and($v1->json('has_more'))->toBeFalse()
        ->and($v1->json())->not->toHaveKey('limit');
});

