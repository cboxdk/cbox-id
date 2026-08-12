<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\DeviceLabel;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\RefreshTokens;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/**
 * YOU CANNOT REVOKE WHAT YOU CANNOT SEE, and until this page a person could see their
 * current session, a COUNT of the others, and nothing at all about the applications
 * holding refresh tokens as them — including every device-flow grant, which is the case
 * self-service revocation exists for.
 */
function signedInPerson(string $email = 'ada@acme.test'): array
{
    $subject = app(Subjects::class)->create($email, 'Ada Lovelace', 'a-strong-unbreached-passphrase');
    app(Subjects::class)->markEmailVerified($subject->id, $email);

    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-activity-'.bin2hex(random_bytes(3))));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Member);

    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd'], '203.0.113.10', 'Mozilla/5.0 (Macintosh) Chrome/141 Safari/537');
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, null, MembershipRole::Member);

    return [$subject->id, $org->id, $session->id];
}

it('shows every session a person holds, not just a count of them', function (): void {
    [$subjectId, $orgId, $currentId] = signedInPerson();

    app(SessionManager::class)->start($subjectId, $orgId, ['pwd'], '198.51.100.7', 'Mozilla/5.0 (iPhone) Safari/604');

    $page = Volt::test('account.activity');

    $page->assertSee('Chrome on macOS')
        ->assertSee('Safari on iPhone')
        // The address is what somebody actually recognises, or does not.
        ->assertSee('203.0.113.10')
        ->assertSee('198.51.100.7')
        ->assertSee('This device');
});

/**
 * THE POINT OF A LIST is that you can act on ONE row. A single "sign out everywhere" is
 * the right answer to "my account is compromised" and the wrong one to "that is the
 * laptop I left at the office".
 */
it('signs out one session and leaves the rest alone', function (): void {
    [$subjectId, $orgId, $currentId] = signedInPerson();

    $other = app(SessionManager::class)->start($subjectId, $orgId, ['pwd'], '198.51.100.7', 'Firefox');

    Volt::test('account.activity')->call('revokeSession', $other->id);

    expect(app(SessionManager::class)->active($other->id))->toBeNull()
        ->and(app(SessionManager::class)->active($currentId))->not->toBeNull();
});

/**
 * A COMPONENT ACTION IS A POST ANYBODY SIGNED IN CAN MAKE, and an id rendered on a page is
 * an id from the client. Somebody else's session id must do nothing at all.
 */
it('refuses to sign out a session belonging to somebody else', function (): void {
    signedInPerson();

    [$strangerId, $strangerOrg] = signedInPerson('mallory@acme.test');
    $strangers = app(SessionManager::class)->start($strangerId, $strangerOrg, ['pwd']);

    // Back to the first person, holding their own session.
    [$subjectId, $orgId, $currentId] = signedInPerson('ada2@acme.test');

    Volt::test('account.activity')->call('revokeSession', $strangers->id);

    expect(app(SessionManager::class)->active($strangers->id))->not->toBeNull();
})->group('security');

it('signs out everywhere else without signing out the browser asking', function (): void {
    [$subjectId, $orgId, $currentId] = signedInPerson();

    $a = app(SessionManager::class)->start($subjectId, $orgId, ['pwd']);
    $b = app(SessionManager::class)->start($subjectId, $orgId, ['pwd']);

    Volt::test('account.activity')->call('signOutEverywhereElse');

    expect(app(SessionManager::class)->active($a->id))->toBeNull()
        ->and(app(SessionManager::class)->active($b->id))->toBeNull()
        ->and(app(SessionManager::class)->active($currentId))->not->toBeNull();
});

/**
 * THE DEVICE-FLOW CASE, which is the whole reason this page exists: you approve something
 * on a TV or a command line, and later you want it gone. Before this it was invisible.
 */
it('shows an application holding a grant, and withdraws exactly that one', function (): void {
    [$subjectId, $orgId] = signedInPerson();

    $cli = app(ClientRegistry::class)->register(new NewClient('Acme CLI', ClientType::Public, grantTypes: ['urn:ietf:params:oauth:grant-type:device_code'], scopes: ['openid', 'offline_access']))->client;
    $other = app(ClientRegistry::class)->register(new NewClient('Acme Web', ClientType::Public, grantTypes: ['authorization_code'], scopes: ['openid']))->client;

    $tokens = app(RefreshTokens::class);
    $tokens->issue($cli, $subjectId, $orgId, ['openid', 'offline_access']);
    $kept = $tokens->issue($other, $subjectId, $orgId, ['openid']);

    $page = Volt::test('account.activity');

    $page->assertSee('Acme CLI')
        ->assertSee('Acme Web')
        // Worth saying out loud: this one keeps working when nobody is there.
        ->assertSee('Works when you are away');

    $page->call('revokeApplication', $cli->client_id);

    $remaining = app(RefreshTokens::class)->connectedApplications($subjectId);

    expect($remaining)->toHaveCount(1)
        ->and($remaining[0]->name)->toBe('Acme Web');
})->group('security');

it('never shows one person another person\'s applications', function (): void {
    [$strangerId, $strangerOrg] = signedInPerson('mallory@acme.test');

    $client = app(ClientRegistry::class)->register(new NewClient('Mallory CLI', ClientType::Public, grantTypes: ['authorization_code'], scopes: ['openid']))->client;
    app(RefreshTokens::class)->issue($client, $strangerId, $strangerOrg, ['openid']);

    signedInPerson('ada3@acme.test');

    Volt::test('account.activity')->assertDontSee('Mallory CLI');
})->group('security');

/**
 * "Was that me?" is the question a person asks the moment they see a session they do not
 * recognise, which is why it is on the same page rather than one click away.
 */
it('shows the account events a person needs to recognise, in their own words', function (): void {
    signedInPerson();

    Volt::test('account.activity')
        // `user.session_started` is written by the session manager on every sign-in.
        ->assertSee('Signed in')
        // And not the machine name for it.
        ->assertDontSee('user.session_started');
});

it('shows nobody else\'s activity', function (): void {
    [$strangerId] = signedInPerson('mallory2@acme.test');

    signedInPerson('ada4@acme.test');

    $html = Volt::test('account.activity')->html();

    expect($html)->not->toContain($strangerId);
})->group('security');

/**
 * A user agent is not something a person can read. "Chrome on macOS" is what they compare
 * against the thing in their hand.
 */
it('names a device in words rather than in a user-agent string', function (string $agent, string $expected): void {
    expect(DeviceLabel::for($agent))->toBe($expected);
})->with([
    'chrome on mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'Chrome on macOS'],
    'safari on iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1', 'Safari on iPhone'],
    // Edge and Chrome both claim to be Safari, so the order of the checks is the test.
    'edge on windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', 'Edge on Windows'],
    'firefox on linux' => ['Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0', 'Firefox on Linux'],
]);

it('says a client sent no browser name rather than calling it unknown', function (): void {
    // Almost always a CLI, a script or an SDK — which is more useful to say than admitting
    // we did not look.
    expect(DeviceLabel::for(null))->toContain('no browser name');
});

/**
 * The audit chain carries `recorded_at`, and the model declares no Eloquent timestamps —
 * so reading `created_at` rendered a column of em dashes on a page whose entire job is to
 * tell somebody WHEN something happened.
 */
it('dates every activity row', function (): void {
    signedInPerson();

    $html = Volt::test('account.activity')->html();

    // A time element with a real datetime, not the placeholder.
    expect($html)->toMatch('/<time[^>]+datetime="\d{4}-\d{2}-\d{2}T/');
});
