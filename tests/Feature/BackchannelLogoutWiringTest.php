<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Kernel\Events\Contracts\EventBus;
use Cbox\Id\OAuthServer\Contracts\AuthorizationCodes;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\RefreshTokens;
use Cbox\Id\OAuthServer\Exceptions\InvalidGrant;
use Cbox\Id\OAuthServer\Jobs\DeliverBackchannelLogout;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Back-channel logout, wired into this app
|--------------------------------------------------------------------------
| The framework sends OIDC Back-Channel Logout tokens; this app has to name the session a
| sign-in came from, say which session a browser holds, and withdraw access — not merely
| revoke sessions — where a person's access is actually over.
*/

beforeEach(function (): void {
    installedDeployment();
});

/** A confidential app that signs people in and wants to hear when they leave. */
function logoutAwareApp(?string $organizationId = null): Client
{
    return app(ClientRegistry::class)->register(new NewClient(
        name: 'Listening app',
        redirectUris: ['https://app.test/cb'],
        grantTypes: ['authorization_code', 'refresh_token'],
        scopes: ['openid'],
        organizationId: $organizationId,
        backchannelLogoutUri: 'https://app.test/backchannel-logout',
    ))->client;
}

/** Drain the outbox the way the scheduled relay does, so the listeners run. */
function relayOutbox(): void
{
    app(EventBus::class)->flushPending(1000);
}

/** Whether a refresh token still refreshes — the property that matters, not a column. */
function stillRefreshes(Client $client, string $raw): bool
{
    try {
        app(RefreshTokens::class)->rotate($client->client_id, $raw);

        return true;
    } catch (InvalidGrant) {
        return false;
    }
}

it('names the session a person approved from on the code, so the ID Token carries sid', function (): void {
    $subject = app(Subjects::class)->create('sid@acme.test', 'Sid', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-sid'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);

    $client = logoutAwareApp($org->id);
    $pkce = pkcePair();

    $props = consentScreen([
        'client_id' => $client->client_id,
        'redirect_uri' => 'https://app.test/cb',
        'scope' => 'openid',
        'code_challenge' => $pkce['challenge'],
    ]);

    parse_str((string) parse_url((string) leftFor(answerConsent($props)), PHP_URL_QUERY), $query);

    $grant = app(AuthorizationCodes::class)->exchange(
        $client->client_id,
        (string) ($query['code'] ?? ''),
        'https://app.test/cb',
        $pkce['verifier'],
    );

    expect($grant->sessionId)->toBe($session->id);
})->group('security');

it('ends this browser\'s own session on a logout that carries no proof of who it is about', function (): void {
    $subject = app(Subjects::class)->create('nohint@acme.test', 'No Hint', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-nohint'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    $sessions = app(SessionManager::class);
    $thisBrowser = $sessions->start($subject->id, $org->id, ['pwd']);
    $phone = $sessions->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $thisBrowser->id]);
    app(CurrentUser::class)->set($subject, $thisBrowser, $org, MembershipRole::Owner);

    $this->get('/oauth/logout')->assertSuccessful();

    // This browser's session is over — so the apps it signed the person in to are told —
    // and only this one: a request that cannot say who it is about reaches no further.
    expect(Session::query()->whereKey($thisBrowser->id)->value('revoked_at'))->not->toBeNull()
        ->and(Session::query()->whereKey($phone->id)->value('revoked_at'))->toBeNull();
})->group('security');

it('withdraws the grants, not only the sessions, when an administrator signs someone out everywhere', function (): void {
    crudSetup();
    Queue::fake([DeliverBackchannelLogout::class]);

    $user = app(Subjects::class)->create('out@acme.example', 'Out');
    $org = app(Organizations::class)->create(new NewOrganization('Customer', 'customer-out'));
    app(Memberships::class)->add($org->id, $user->id, MembershipRole::Member);

    $client = logoutAwareApp();
    $raw = app(RefreshTokens::class)->issue($client, $user->id, $org->id, ['openid']);

    test()->from(route('environment.users.show', $user->id))
        ->delete(route('environment.users.sessions.revoke-all', $user->id))
        ->assertSessionHasNoErrors();

    // A refresh token outlived the sign-out, so the app kept minting access as the person.
    expect(stillRefreshes($client, $raw))->toBeFalse();

    Queue::assertPushed(DeliverBackchannelLogout::class);
})->group('security');

it('withdraws a removed member\'s grants in that organization and leaves the rest', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $dana = app(Subjects::class)->create('dana@acme.test', 'Dana');
    app(Memberships::class)->add($org->id, $dana->id, MembershipRole::Member);
    $elsewhere = app(Organizations::class)->create(new NewOrganization('Globex', 'globex-removal'));
    app(Memberships::class)->add($elsewhere->id, $dana->id, MembershipRole::Member);

    $client = logoutAwareApp();
    $here = app(RefreshTokens::class)->issue($client, $dana->id, $org->id, ['openid']);
    $there = app(RefreshTokens::class)->issue($client, $dana->id, $elsewhere->id, ['openid']);

    test()->from(route('directory.members'))
        ->delete(route('directory.members.remove', $dana->id))
        ->assertSessionHasNoErrors();
    relayOutbox();

    expect(stillRefreshes($client, $here))->toBeFalse()
        ->and(stillRefreshes($client, $there))->toBeTrue();
})->group('security');

it('withdraws every member\'s grants in an organization its owner closes', function (): void {
    [$ownerId, $org] = actingAsRole(MembershipRole::Owner);
    $dana = app(Subjects::class)->create('dana@acme.test', 'Dana');
    app(Memberships::class)->add($org->id, $dana->id, MembershipRole::Member);
    $elsewhere = app(Organizations::class)->create(new NewOrganization('Globex', 'globex-closure'));
    app(Memberships::class)->add($elsewhere->id, $dana->id, MembershipRole::Member);

    $client = logoutAwareApp();
    $owners = app(RefreshTokens::class)->issue($client, $ownerId, $org->id, ['openid']);
    $danas = app(RefreshTokens::class)->issue($client, $dana->id, $org->id, ['openid']);
    $danasElsewhere = app(RefreshTokens::class)->issue($client, $dana->id, $elsewhere->id, ['openid']);

    app(Sudo::class)->confirm();

    test()->from(route('settings'))
        ->delete(route('settings.organization.destroy'), ['name' => 'Acme'])
        ->assertSessionHasNoErrors();
    relayOutbox();

    // The request pipeline refuses the members from now on; the token endpoint never
    // asked, so their grants went on refreshing for an organization that no longer exists.
    expect(Organization::query()->whereKey($org->id)->value('status')?->value)->toBe('deleted')
        ->and(stillRefreshes($client, $owners))->toBeFalse()
        ->and(stillRefreshes($client, $danas))->toBeFalse()
        ->and(stillRefreshes($client, $danasElsewhere))->toBeTrue();
})->group('security');
