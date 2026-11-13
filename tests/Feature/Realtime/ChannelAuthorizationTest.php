<?php

declare(strict_types=1);

use App\Platform\Impersonation;
use App\Platform\NativeProduct\NativeEnvironmentAccess;
use App\Platform\Realtime\Channels;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Testing\TestResponse;

/*
 * WHO MAY LISTEN WHERE — the channel authorization matrix.
 *
 * A live channel says only "record X of kind Y changed", but which channels exist and when
 * things change in them is still information about a tenant. So each is signed behind the
 * same gates as the pages that listen on it, and every row below is a door that must stay
 * shut: another person's channel, another organization's, another environment's, an
 * impersonation, a signed-out browser.
 */

const LIVE_SOCKET = '1234.5678';

function liveAuth(string $path, string $channel): TestResponse
{
    return test()->getJson($path.'?'.http_build_query(['socket_id' => LIVE_SOCKET, 'channel_name' => Channels::private($channel)]));
}

beforeEach(function (): void {
    enableLiveUpdates();
});

it('signs a person into their own channel, and nobody else\'s', function (): void {
    $account = provisionAccount();
    [, $otherId] = addMember($account['organization']->id, MembershipRole::Member, 'other@acme.example');
    signInAsMember($account['subjectId']);

    $own = Channels::subject($account['subjectId']);

    liveAuth('/realtime/auth', $own)
        ->assertOk()
        ->assertExactJson(['auth' => liveSignature(LIVE_SOCKET, Channels::private($own))])
        ->assertHeader('Cache-Control', 'no-store, private');

    liveAuth('/realtime/auth', Channels::subject($otherId))->assertForbidden();
});

it('signs an organization admin into their organization\'s channel, and not another\'s', function (): void {
    $acme = provisionAccount('owner@acme.example');
    $globex = provisionAccount('owner@globex.example');
    signInAsMember($acme['subjectId']);

    liveAuth('/realtime/auth', Channels::organization($acme['organization']->id))->assertOk();
    liveAuth('/realtime/auth', Channels::organization($globex['organization']->id))->assertForbidden();

    // …nor through the other organization's own address: the membership gate answers first.
    liveAuth('/orgs/'.$globex['organization']->slug.'/realtime/auth', Channels::organization($globex['organization']->id))->assertNotFound();
});

it('binds the organization channel to the organization in the address', function (): void {
    $acme = provisionAccount('owner@acme.example');
    signInAsMember($acme['subjectId']);

    liveAuth('/orgs/'.$acme['organization']->slug.'/realtime/auth', Channels::organization($acme['organization']->id))->assertOk();
    liveAuth('/orgs/'.$acme['organization']->slug.'/realtime/auth', Channels::subject($acme['subjectId']))->assertOk();
});

it('keeps an ordinary member off the organization channel', function (): void {
    $acme = provisionAccount();
    [, $memberId] = addMember($acme['organization']->id, MembershipRole::Member, 'member@acme.example');
    signInAsMember($memberId);

    liveAuth('/realtime/auth', Channels::organization($acme['organization']->id))->assertForbidden();
    liveAuth('/realtime/auth', Channels::subject($memberId))->assertOk();
});

it('signs an environment administrator into that environment only', function (): void {
    multiTenantDeployment();
    $acme = provisionAccount('owner@acme.example');
    $globex = provisionAccount('owner@globex.example');
    actAsEnvironmentAdmin($acme['subjectId'], $acme['environment']->id);

    liveAuth(envConsole('/realtime/auth'), Channels::environment($acme['environment']->id))
        ->assertOk()
        ->assertExactJson(['auth' => liveSignature(LIVE_SOCKET, Channels::private(Channels::environment($acme['environment']->id)))]);

    // Another environment's channel on this console's path: refused.
    liveAuth(envConsole('/realtime/auth'), Channels::environment($globex['environment']->id))->assertForbidden();

    // An organization or person channel is not this endpoint's to sign.
    liveAuth(envConsole('/realtime/auth'), Channels::subject($acme['subjectId']))->assertForbidden();
    liveAuth(envConsole('/realtime/auth'), Channels::organization($acme['organization']->id))->assertForbidden();

    // …and the other environment's console path is not this administrator's at all.
    useEnvironmentConsole($globex['environment']->id);
    liveAuth(envConsole('/realtime/auth'), Channels::environment($globex['environment']->id))->assertNotFound();
});

it('lets a read-only console role listen to what it may look at', function (): void {
    multiTenantDeployment();
    $acme = provisionAccount('owner@acme.example');
    [$member, $viewerId] = addMember($acme['organization']->id, MembershipRole::Member, 'viewer@acme.example');
    grantNativeProductRoles($member, ['viewer']);
    $access = app(NativeEnvironmentAccess::class);
    $access->replace($member, true, [], $access->version($member));
    actAsEnvironmentAdmin($viewerId, $acme['environment']->id);

    // The page opens for a viewer…
    $this->get(envConsole('/users'))->assertOk();
    // …so its channel does too: a subscription is a read. (A POST here would be refused to
    // exactly this role, which is why the endpoint is a GET.)
    liveAuth(envConsole('/realtime/auth'), Channels::environment($acme['environment']->id))->assertOk();
});

it('treats the platform root environment like any other', function (): void {
    multiTenantDeployment();
    $acme = provisionAccount('owner@acme.example');
    $root = app(PlatformRoot::class)->environment();
    actAsOperator('live-op@platform.test');
    useEnvironmentConsole($root->environmentKey());

    liveAuth(envConsole('/realtime/auth'), Channels::environment($root->environmentKey()))->assertOk();
    liveAuth(envConsole('/realtime/auth'), Channels::environment($acme['environment']->id))->assertForbidden();
});

it('signs nobody who is signed out', function (): void {
    provisionAccount();
    $response = liveAuth('/realtime/auth', Channels::subject('01ARZ3NDEKTSV4RRFFQ69G5FAV'));

    expect($response->status())->toBeIn([302, 401, 403, 404]);
    expect($response->json('auth'))->toBeNull();
});

it('refuses a malformed socket or channel', function (): void {
    $account = provisionAccount();
    signInAsMember($account['subjectId']);

    test()->getJson('/realtime/auth?'.http_build_query(['socket_id' => 'x', 'channel_name' => Channels::private(Channels::subject($account['subjectId']))]))
        ->assertStatus(422);

    foreach (['private-id.subject.'.$account['subjectId'].'.extra', 'presence-id.subject.'.$account['subjectId'], 'id.subject.'.$account['subjectId'], 'private-anything'] as $channel) {
        test()->getJson('/realtime/auth?'.http_build_query(['socket_id' => LIVE_SOCKET, 'channel_name' => $channel]))->assertForbidden();
    }
});

it('signs nothing while impersonating', function (): void {
    $operator = impersonationOperator();
    [$org, $member] = impersonationMember();

    $this->post(route('platform.impersonate', $member->id), ['organization' => $org->id, 'reason' => IMPERSONATION_REASON])
        ->assertRedirect(route('dashboard'));
    expect(session(Impersonation::SESSION_KEY))->not->toBeNull();
    nextRequest();

    // The person's pages still open — the impersonation may look — but nothing may listen,
    // and the page is not offered a channel to try.
    expect($this->get(route('dashboard'))->assertOk()->inertiaProps('realtime'))->toBeNull();

    liveAuth('/realtime/auth', Channels::subject($member->id))->assertForbidden();
    liveAuth('/realtime/auth', Channels::subject($operator->subject_id))->assertForbidden();
});

it('is not there at all while live updates are off', function (): void {
    config(['broadcasting.default' => 'null']);
    $account = provisionAccount();
    signInAsMember($account['subjectId']);

    liveAuth('/realtime/auth', Channels::subject($account['subjectId']))->assertNotFound();
});

it('offers a page exactly the channels its endpoint will sign', function (): void {
    $acme = provisionAccount('owner@acme.example');
    signInAsMember($acme['subjectId']);

    $props = $this->get(route('dashboard'))->assertOk()->inertiaProps('realtime');

    expect($props)->toBe([
        'key' => 'live-test-key',
        'host' => null,
        'port' => 443,
        'scheme' => 'https',
        'authUrl' => '/realtime/auth',
        'channels' => [Channels::subject($acme['subjectId']), Channels::organization($acme['organization']->id)],
    ]);

    foreach ($props['channels'] as $channel) {
        liveAuth($props['authUrl'], $channel)->assertOk();
    }
});

it('offers the environment console its environment channel and endpoint', function (): void {
    multiTenantDeployment();
    $acme = provisionAccount('owner@acme.example');
    actAsEnvironmentAdmin($acme['subjectId'], $acme['environment']->id);

    $response = $this->get(envConsole('/users'))->assertOk();
    $props = $response->inertiaProps('realtime');

    expect($props['channels'])->toBe([Channels::environment($acme['environment']->id)])
        ->and($props['authUrl'])->toBe(envConsolePath('/realtime/auth'))
        // The socket is same-origin, and named in the policy for browsers that read 'self' narrowly.
        ->and($response->headers->get('Content-Security-Policy'))->toContain('wss://cboxid.com');

    liveAuth(envConsole('/realtime/auth'), $props['channels'][0])->assertOk();
});

it('offers nothing, and names no socket, while live updates are off', function (): void {
    config(['broadcasting.default' => 'null']);
    $acme = provisionAccount();
    signInAsMember($acme['subjectId']);

    $response = $this->get(route('dashboard'))->assertOk();

    expect($response->inertiaProps('realtime'))->toBeNull()
        ->and($response->headers->get('Content-Security-Policy'))->not->toContain('wss://');
});

it('serves a person\'s channel on the login host in login-host mode', function (): void {
    loginHostMode();
    $account = provisionAccount();
    signInAsMember($account['subjectId']);
    session()->put('cbox.surface', 'login');

    $props = $this->get('https://'.LOGIN_HOST.'/settings')->assertOk()->inertiaProps('realtime');

    expect($props['authUrl'])->toBe('/realtime/auth')
        ->and($props['channels'])->toContain(Channels::subject($account['subjectId']));

    liveAuth('https://'.LOGIN_HOST.'/realtime/auth', Channels::subject($account['subjectId']))->assertOk();
});
