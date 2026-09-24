<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use App\Platform\SelfServiceSignup;
use App\Platform\SupportAccess\Exceptions\SupportRequestRefused;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Crypto\Contracts\TokenSigner;
use Cbox\Id\Kernel\Crypto\Enums\SigningAlg;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\PushedAuthorizationRequests;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\AccessToken;
use Cbox\Id\OAuthServer\Models\SupportSession;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Enums\MembershipStatus;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Support access — "Sign in to <app> as <user>"
|--------------------------------------------------------------------------
| An environment administrator starts a support session from a user's page; their browser
| is handed to the app, which starts its own sign-in; the authorization endpoint answers
| it with a code minted for the session. The app completes an ordinary code exchange and
| gets tokens that carry `act`, and no refresh token.
*/

const SUPPORT_VERIFIER = 'support-session-verifier-of-sufficient-length-0123456789';

/** An app a support session can reach: first-party, owned by the environment, web sign-in. */
function supportApp(string $name = 'Parcels', array $changes = []): string
{
    return app(ClientRegistry::class)->register(new NewClient(...[
        'name' => $name,
        'type' => ClientType::Public,
        'redirectUris' => ['https://'.strtolower($name).'.test/callback'],
        'grantTypes' => ['authorization_code', 'refresh_token'],
        'scopes' => ['openid', 'email', 'offline_access'],
        'firstParty' => true,
        ...$changes,
    ]))->client->client_id;
}

/**
 * The console, an organization and a member of it to act as.
 *
 * @return array{admin: string, org: string, user: string}
 */
function supportFixture(): array
{
    ['subjectId' => $admin] = crudSetup();

    $org = app(Organizations::class)->create(new NewOrganization('Globex', 'globex-support'));
    $user = app(Subjects::class)->create('grace@globex.test', 'Grace Hopper', 'a-strong-unbreached-passphrase')->id;
    app(Memberships::class)->add($org->id, $user, MembershipRole::Member);

    return ['admin' => $admin, 'org' => $org->id, 'user' => $user];
}

function startSupport(string $userId, array $fields): TestResponse
{
    return test()->from(route('environment.users.show', $userId))
        ->post(route('environment.users.support-sessions.store', $userId), [
            'reason' => 'Ticket 4411: invoice totals look wrong',
            'minutes' => 30,
            ...$fields,
        ]);
}

/** The app's own sign-in, arriving at the authorization endpoint from the same browser. */
function appSignsIn(string $clientId, string $name = 'Parcels'): TestResponse
{
    return authorizeRequest([
        'client_id' => $clientId,
        'redirect_uri' => 'https://'.strtolower($name).'.test/callback',
        'scope' => 'openid email offline_access',
        'code_challenge' => pkcePair(SUPPORT_VERIFIER)['challenge'],
        'nonce' => 'n-0S6_WzA2Mj',
    ]);
}

/** @return array<string, string> the query the browser was sent back to the app with */
function returnedToApp(TestResponse $response): array
{
    parse_str((string) parse_url((string) leftFor($response), PHP_URL_QUERY), $query);

    /** @var array<string, string> $query */
    return $query;
}

it('signs the administrator in to the app as the person, with act on every token and no refresh token', function (): void {
    ['admin' => $admin, 'org' => $org, 'user' => $user] = supportFixture();
    $parcels = supportApp();
    confirmEnvironmentStepUp();

    // Starting it hands the browser to the app's own entry point, which starts its sign-in.
    $start = startSupport($user, ['app' => $parcels, 'organization' => $org])->assertSessionHasNoErrors();

    expect(leftFor($start))->toBe('https://parcels.test');

    // The app's authorization request is answered with a code for the session — no sign-in
    // page, although the administrator is nobody on this tenant.
    $callback = appSignsIn($parcels);
    $query = returnedToApp($callback);

    expect(parse_url((string) leftFor($callback), PHP_URL_HOST))->toBe('parcels.test')
        ->and($query['code'] ?? null)->toBeString()
        ->and($query['state'] ?? null)->toBe('xyz');

    $tokens = $this->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $parcels,
        'code' => $query['code'],
        'redirect_uri' => 'https://parcels.test/callback',
        'code_verifier' => SUPPORT_VERIFIER,
    ])->assertOk()->json();

    $access = app(TokenSigner::class)->verify($tokens['access_token'], [SigningAlg::RS256]);
    $id = app(TokenSigner::class)->verify($tokens['id_token'], [SigningAlg::RS256]);

    expect($access->get('sub'))->toBe($user)
        ->and((array) $access->get('act'))->toBe(['sub' => $admin])
        ->and($access->get('org'))->toBe($org)
        ->and((array) $id->get('act'))->toBe(['sub' => $admin])
        ->and($id->get('nonce'))->toBe('n-0S6_WzA2Mj')
        // Asked for offline_access, and still no way to stay signed in.
        ->and($tokens)->not->toHaveKey('refresh_token');

    // And the console is still the administrator's, to end the session from.
    $this->get(route('environment.users.show', $user))->assertOk();
})->group('security');

it('offers only the apps a support session can reach', function (): void {
    ['user' => $user, 'org' => $org] = supportFixture();

    supportApp('Parcels');
    supportApp('Partner', ['firstParty' => false]);
    supportApp('Theirs', ['organizationId' => $org]);
    supportApp('Native', ['redirectUris' => ['com.example.native:/callback']]);
    supportApp('Machine', ['grantTypes' => ['client_credentials'], 'type' => ClientType::Confidential]);

    $support = $this->get(route('environment.users.show', $user))->assertOk()->inertiaProps('support');

    expect(collect($support['apps'])->pluck('label')->all())->toBe(['Parcels'])
        ->and(collect($support['organizations'])->pluck('value')->all())->toBe([$org])
        ->and($support['maxMinutes'])->toBe(60)
        ->and($support['help']['topic'])->toBe('support-access');
})->group('security');

/**
 * THE CONSENT RULE, ASKED BY THE CONSOLE. The person being acted as never agrees to a
 * support session, so it may only reach an app that would not have asked them anyway. A
 * posted id is anything a client sends, so the refusal cannot depend on the picker.
 */
it('refuses an app a support session may not reach, by name', function (string $name, array $changes): void {
    ['user' => $user, 'org' => $org] = supportFixture();
    confirmEnvironmentStepUp();

    $changes = $name === 'Theirs' ? ['organizationId' => $org] : $changes;
    $clientId = supportApp($name, $changes);

    startSupport($user, ['app' => $clientId, 'organization' => $org])
        ->assertSessionHasErrors(['app' => 'Support sessions reach only first-party apps this environment owns.']);

    expect(SupportSession::query()->count())->toBe(0);
})->with([
    'a third-party app' => ['Partner', ['firstParty' => false]],
    'an app an organization owns' => ['Theirs', []],
    // The framework would accept this one; a browser cannot be sent to it.
    'an app with no web address' => ['Native', ['redirectUris' => ['com.example.native:/callback']]],
])->group('security');

it('refuses an organization the person is not an active member of', function (string $case): void {
    ['user' => $user, 'org' => $org] = supportFixture();
    $parcels = supportApp();
    confirmEnvironmentStepUp();

    $target = $org;

    if ($case === 'not theirs') {
        $target = app(Organizations::class)->create(new NewOrganization('Initech', 'initech-support'))->id;
    } else {
        DB::table('memberships')->where('organization_id', $org)->where('user_id', $user)
            ->update(['status' => MembershipStatus::Suspended->value]);
    }

    startSupport($user, ['app' => $parcels, 'organization' => $target])
        ->assertSessionHasErrors(['organization' => 'They are not an active member of that organization.']);

    expect(SupportSession::query()->count())->toBe(0);
})->with(['not theirs', 'suspended'])->group('security');

it('requires a reason, and never runs longer than the configured maximum', function (): void {
    config(['cbox-id.oauth.support_sessions.max_ttl' => 900]);

    ['user' => $user, 'org' => $org] = supportFixture();
    $parcels = supportApp();
    confirmEnvironmentStepUp();

    startSupport($user, ['app' => $parcels, 'organization' => $org, 'reason' => '   '])
        ->assertSessionHasErrors(['reason' => 'Say why — the organization sees this on its activity log.']);

    startSupport($user, ['app' => $parcels, 'organization' => $org, 'minutes' => 30])
        ->assertSessionHasErrors(['minutes' => 'A support session lasts at most 15 minutes.']);

    expect($this->get(route('environment.users.show', $user))->inertiaProps('support.maxMinutes'))->toBe(15);

    startSupport($user, ['app' => $parcels, 'organization' => $org, 'minutes' => 15])->assertSessionHasNoErrors();

    $session = SupportSession::query()->sole();
    expect((int) round($session->created_at?->diffInMinutes($session->expires_at)))->toBe(15);
});

it('asks for a fresh password before it starts one', function (): void {
    ['user' => $user, 'org' => $org] = supportFixture();
    $parcels = supportApp();

    startSupport($user, ['app' => $parcels, 'organization' => $org])->assertRedirect(route('environment.sudo'));

    expect(SupportSession::query()->count())->toBe(0);
})->group('security');

/**
 * THE HANDOFF IS A POINTER, NEVER THE AUTHORITY. The browser's note that it holds a
 * session for an app is honoured only for the administrator who started it, still signed
 * in to this environment's console — somebody else at the keyboard, or the same person
 * revoked, gets an ordinary sign-in.
 */
it('mints nothing for the session once the administrator is no longer signed in to the console', function (): void {
    ['user' => $user, 'org' => $org] = supportFixture();
    $parcels = supportApp();
    confirmEnvironmentStepUp();

    startSupport($user, ['app' => $parcels, 'organization' => $org])->assertSessionHasNoErrors();

    $consoleSession = (string) session(PlatformAuth::SESSION_KEY);
    app(PlatformRoot::class)->run(fn () => app(SessionManager::class)->revoke($consoleSession));

    nextRequest();

    appSignsIn($parcels)->assertRedirect(route('login'));

    // And the note is gone, so a later sign-in here cannot pick it back up.
    expect(session('cbox.support_handoff'))->toBe([]);
})->group('security');

it('answers another app\'s sign-in, and a browser that started nothing, the ordinary way', function (): void {
    ['user' => $user, 'org' => $org] = supportFixture();
    $parcels = supportApp();
    $ledger = supportApp('Ledger');
    confirmEnvironmentStepUp();

    startSupport($user, ['app' => $parcels, 'organization' => $org])->assertSessionHasNoErrors();

    // One app per session: Ledger's sign-in is not Parcels'.
    appSignsIn($ledger, 'Ledger')->assertRedirect(route('login'));

    // A fresh browser has no note at all.
    session()->forget('cbox.support_handoff');
    appSignsIn($parcels)->assertRedirect(route('login'));
})->group('security');

it('ends a session now — no more codes, every token it issued revoked', function (): void {
    ['user' => $user, 'org' => $org] = supportFixture();
    $parcels = supportApp();
    confirmEnvironmentStepUp();

    startSupport($user, ['app' => $parcels, 'organization' => $org]);

    $code = returnedToApp(appSignsIn($parcels))['code'];
    $this->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $parcels,
        'code' => $code,
        'redirect_uri' => 'https://parcels.test/callback',
        'code_verifier' => SUPPORT_VERIFIER,
    ])->assertOk();

    $sessions = $this->get(route('environment.users.show', $user))->inertiaProps('support.sessions');

    expect($sessions)->toHaveCount(1)
        ->and($sessions[0]['app'])->toBe('Parcels')
        ->and($sessions[0]['organization'])->toBe('Globex')
        ->and($sessions[0]['reason'])->toBe('Ticket 4411: invoice totals look wrong')
        ->and($sessions[0]['startedBy'])->toBe('Owner');

    // The organization's page lists the same session.
    expect($this->get(route('environment.organizations.show', $org))->inertiaProps('supportSessions.0.id'))
        ->toBe($sessions[0]['id']);

    $this->from(route('environment.users.show', $user))->delete($sessions[0]['endHref'])->assertSessionHasNoErrors();

    expect(AccessToken::query()->whereNotNull('support_session_id')->whereNull('revoked_at')->count())->toBe(0)
        ->and($this->get(route('environment.users.show', $user))->inertiaProps('support.sessions'))->toBe([]);

    // The app's next sign-in is an ordinary one.
    appSignsIn($parcels)->assertRedirect(route('login'));
})->group('security');

it('ends only a session of this environment', function (): void {
    supportFixture();

    $this->delete(route('environment.support-sessions.end', '01KNOSUCHSESSION0000000000'))->assertNotFound();
});

/**
 * THE ORGANIZATION SEES IT. Its activity log carries the session with who started it, by
 * name, and the reason ahead of the ids; the person acted as sees it on their own
 * activity page, once.
 */
it('puts the session on the organization\'s activity log and on the person\'s own activity page', function (): void {
    ['user' => $user, 'org' => $org] = supportFixture();
    $parcels = supportApp();
    confirmEnvironmentStepUp();

    startSupport($user, ['app' => $parcels, 'organization' => $org])->assertSessionHasNoErrors();

    $this->post(route('environment.acting-organization.choose'), ['organization' => $org]);

    $entry = collect($this->get(route('environment.audit'))->assertOk()->inertiaProps('entries'))
        ->firstWhere('action', 'support_session.started');

    expect($entry)->not->toBeNull()
        ->and($entry['actorName'])->toBe('Owner')
        ->and($entry['targetName'])->toBe('Grace Hopper')
        ->and($entry['facts'][0])->toBe('reason: Ticket 4411: invoice totals look wrong');

    // Now as Grace, on her own activity page.
    session()->flush();
    app(PlatformAuth::class)->establish(request(), $user, ['pwd']);

    $rows = collect(accountActivity()['activity'])->where('label', 'Support signed in to an app as you');

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['detail'])->toBe('Parcels — “Ticket 4411: invoice totals look wrong”');
});

/*
| Support access and the organization an app asks for (C5 × C6)
*/

/**
 * The person in a SECOND organization, so a request can name one the session is not for.
 *
 * @return array{admin: string, org: string, user: string, other: string, parcels: string}
 */
function supportWithTwoOrganizations(): array
{
    $fixture = supportFixture();
    $other = app(Organizations::class)->create(new NewOrganization('Initech', 'initech-support'));
    app(Memberships::class)->add($other->id, $fixture['user'], MembershipRole::Member);

    $parcels = supportApp();
    confirmEnvironmentStepUp();
    startSupport($fixture['user'], ['app' => $parcels, 'organization' => $fixture['org']])->assertSessionHasNoErrors();

    return [...$fixture, 'other' => $other->id, 'parcels' => $parcels];
}

/**
 * Self-service sign-up switched on for the environment the console stands in — without it
 * `prompt=create` and `create_organization` are `invalid_request` before a support session
 * is asked anything, and the combination would go untested.
 */
function openSelfServiceSignupHere(): void
{
    $key = app(EnvironmentContext::class)->current()?->environmentKey();

    app(SelfServiceSignup::class)->set(Environment::query()->findOrFail($key), true);
}

/** The app's sign-in with extra parameters, as an SDK sends them. */
function appSignsInWith(string $clientId, array $extra): TestResponse
{
    return authorizeRequest([
        'client_id' => $clientId,
        'redirect_uri' => 'https://parcels.test/callback',
        'scope' => 'openid email',
        'code_challenge' => pkcePair(SUPPORT_VERIFIER)['challenge'],
        ...$extra,
    ]);
}

function supportTokenOrg(string $clientId, string $code): ?string
{
    $access = test()->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $clientId,
        'code' => $code,
        'redirect_uri' => 'https://parcels.test/callback',
        'code_verifier' => SUPPORT_VERIFIER,
    ])->assertOk()->json('access_token');

    $claims = app(TokenSigner::class)->verify((string) $access, [SigningAlg::RS256]);

    return is_string($claims->get('org')) ? $claims->get('org') : null;
}

it('answers the session\'s organization when the app names it, a hint, or asks for the picker', function (array $extra): void {
    ['org' => $org, 'parcels' => $parcels, 'user' => $user] = supportWithTwoOrganizations();
    openSelfServiceSignupHere();

    $extra = array_map(fn (string $value): string => str_replace('{org}', $org, $value), $extra);
    $query = returnedToApp(appSignsInWith($parcels, $extra));

    expect($query['code'] ?? null)->toBeString()
        ->and(supportTokenOrg($parcels, (string) $query['code']))->toBe($org);

    // And the administrator's console session survived the round trip.
    $this->get(route('environment.users.show', $user))->assertOk();
})->with([
    'organization = the session\'s' => [['organization' => '{org}']],
    'a hint and the picker' => [['organization_hint' => 'org_anything', 'prompt' => 'select_organization']],
    'silently' => [['prompt' => 'none']],
    'sign-up' => [['prompt' => 'create']],
])->group('security');

it('refuses an app that names another organization, and keeps the session for one it can answer', function (): void {
    ['other' => $other, 'parcels' => $parcels, 'user' => $user] = supportWithTwoOrganizations();

    $query = returnedToApp(appSignsInWith($parcels, ['organization' => $other]));

    // NOT a code for the session's organization (the app would receive a team it did not
    // ask for), and NOT a sign-in page for an administrator who is nobody here.
    expect($query['error'] ?? null)->toBe('access_denied')
        ->and($query['error_description'] ?? null)->toBe(SupportRequestRefused::otherOrganization()->getMessage())
        ->and($query)->not->toHaveKey('code');

    expect(returnedToApp(appSignsIn($parcels))['code'] ?? null)->toBeString();
    $this->get(route('environment.users.show', $user))->assertOk();
})->group('security');

it('refuses prompt=create_organization during a support session', function (): void {
    ['parcels' => $parcels] = supportWithTwoOrganizations();
    openSelfServiceSignupHere();
    $organizations = DB::table('organizations')->count();

    $query = returnedToApp(appSignsInWith($parcels, ['prompt' => 'create_organization']));

    expect($query['error'] ?? null)->toBe('access_denied')
        ->and($query['error_description'] ?? null)->toBe(SupportRequestRefused::cannotCreateOrganization()->getMessage())
        ->and(DB::table('organizations')->count())->toBe($organizations);
})->group('security');

it('reads the organization from the pushed request alone during a support session', function (): void {
    ['org' => $org, 'other' => $other, 'parcels' => $parcels] = supportWithTwoOrganizations();
    $client = app(ClientRegistry::class)->byClientId($parcels);
    $params = [
        'client_id' => $parcels,
        'redirect_uri' => 'https://parcels.test/callback',
        'response_type' => 'code',
        'scope' => 'openid email',
        'state' => 'xyz',
        'code_challenge' => pkcePair(SUPPORT_VERIFIER)['challenge'],
        'code_challenge_method' => 'S256',
    ];

    // Pushed naming another organization: refused.
    $pushed = app(PushedAuthorizationRequests::class)->push($client, [...$params, 'organization' => $other]);
    expect(returnedToApp(authorizeRequest(['client_id' => $parcels, 'request_uri' => $pushed['request_uri']]))['error'] ?? null)
        ->toBe('access_denied');

    // Pushed without one, and another smuggled onto the query: ignored — the session's.
    $pushed = app(PushedAuthorizationRequests::class)->push($client, $params);
    $query = returnedToApp(authorizeRequest(['client_id' => $parcels, 'request_uri' => $pushed['request_uri'], 'organization' => $other]));

    expect(supportTokenOrg($parcels, (string) ($query['code'] ?? '')))->toBe($org);
})->group('security');
