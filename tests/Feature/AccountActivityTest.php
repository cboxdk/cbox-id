<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\DeviceLabel;
use App\Platform\PlatformAuth;
use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\RefreshTokens;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

    // "Sign out everywhere else" is behind a step-up — it is the account-wide lever, and
    // the one a borrowed unlocked laptop would pull. Held open here so these tests are
    // about the revocations rather than about the gate, which SudoTest owns.
    app(Sudo::class)->confirm();

    return [$subject->id, $org->id, $session->id];
}

it('shows every session a person holds, not just a count of them', function (): void {
    [$subjectId, $orgId, $currentId] = signedInPerson();

    app(SessionManager::class)->start($subjectId, $orgId, ['pwd'], '198.51.100.7', 'Mozilla/5.0 (iPhone) Safari/604');

    $sessions = collect(accountActivity()['sessions']);

    expect($sessions->pluck('label'))->toContain('Chrome on macOS')
        ->and($sessions->pluck('label'))->toContain('Safari on iPhone')
        // The address is what somebody actually recognises, or does not.
        ->and($sessions->pluck('ip'))->toContain('203.0.113.10')
        ->and($sessions->pluck('ip'))->toContain('198.51.100.7')
        // …and exactly one row is the browser doing the asking, which is the question
        // standing in the way of every other one on this page.
        ->and($sessions->where('isCurrent', true)->pluck('id')->all())->toBe([$currentId]);
});

/**
 * THE POINT OF A LIST is that you can act on ONE row. A single "sign out everywhere" is
 * the right answer to "my account is compromised" and the wrong one to "that is the
 * laptop I left at the office".
 */
it('signs out one session and leaves the rest alone', function (): void {
    [$subjectId, $orgId, $currentId] = signedInPerson();

    $other = app(SessionManager::class)->start($subjectId, $orgId, ['pwd'], '198.51.100.7', 'Firefox');

    revokeOwnSession($other->id)->assertSessionHasNoErrors();

    expect(app(SessionManager::class)->active($other->id))->toBeNull()
        ->and(app(SessionManager::class)->active($currentId))->not->toBeNull();
});

/**
 * AN ID ON A PAGE IS AN ID FROM THE CLIENT. Under Volt this was a component action — a
 * POST to the one shared endpoint that anybody signed in could make. It is its own route
 * now and the id is a route parameter, which changes nothing about the danger: somebody
 * else's session id must do nothing at all.
 *
 * 404, not 403: another person's session is not a control this reader is failing to press.
 */
it('refuses to sign out a session belonging to somebody else', function (): void {
    signedInPerson();

    [$strangerId, $strangerOrg] = signedInPerson('mallory@acme.test');
    $strangers = app(SessionManager::class)->start($strangerId, $strangerOrg, ['pwd']);

    // Back to the first person, holding their own session.
    [$subjectId, $orgId, $currentId] = signedInPerson('ada2@acme.test');

    revokeOwnSession($strangers->id)->assertNotFound();

    expect(app(SessionManager::class)->active($strangers->id))->not->toBeNull();
})->group('security');

it('signs out everywhere else without signing out the browser asking', function (): void {
    [$subjectId, $orgId, $currentId] = signedInPerson();

    $a = app(SessionManager::class)->start($subjectId, $orgId, ['pwd']);
    $b = app(SessionManager::class)->start($subjectId, $orgId, ['pwd']);

    revokeOtherOwnSessions()->assertSessionHasNoErrors();

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

    $applications = collect(accountActivity()['applications']);

    expect($applications->pluck('name'))->toContain('Acme CLI')
        ->and($applications->pluck('name'))->toContain('Acme Web')
        // Worth saying out loud, and asked of the row rather than of the page: this one
        // keeps working when nobody is there.
        ->and($applications->firstWhere('name', 'Acme CLI')['actsOffline'])->toBeTrue()
        ->and($applications->firstWhere('name', 'Acme Web')['actsOffline'])->toBeFalse();

    withdrawApplication($cli->client_id)->assertSessionHasNoErrors();

    $remaining = app(RefreshTokens::class)->connectedApplications($subjectId);

    expect($remaining)->toHaveCount(1)
        ->and($remaining[0]->name)->toBe('Acme Web');
})->group('security');

it('never shows one person another person\'s applications', function (): void {
    [$strangerId, $strangerOrg] = signedInPerson('mallory@acme.test');

    $client = app(ClientRegistry::class)->register(new NewClient('Mallory CLI', ClientType::Public, grantTypes: ['authorization_code'], scopes: ['openid']))->client;
    app(RefreshTokens::class)->issue($client, $strangerId, $strangerOrg, ['openid']);

    signedInPerson('ada3@acme.test');

    expect(collect(accountActivity()['applications'])->pluck('name'))->not->toContain('Mallory CLI');
})->group('security');

/**
 * "Was that me?" is the question a person asks the moment they see a session they do not
 * recognise, which is why it is on the same page rather than one click away.
 */
it('shows the account events a person needs to recognise, in their own words', function (): void {
    signedInPerson();

    $labels = collect(accountActivity()['activity'])->pluck('label');

    // `user.session_started` is written by the session manager on every sign-in, and the
    // page carries the WORDS rather than the machine name — the translation is the server's,
    // so this is where it is held.
    expect($labels)->toContain('Signed in')
        ->and($labels)->not->toContain('user.session_started');
});

it('shows nobody else\'s activity', function (): void {
    [$strangerId] = signedInPerson('mallory2@acme.test');

    signedInPerson('ada4@acme.test');

    $mine = collect(accountActivity()['activity'])->pluck('id');

    // THE ENTRY IDS. This asserted `not->toContain($strangerId)` over the rendered HTML and
    // so could not fail: delete the ownership predicate and every person sees every other
    // person's sign-ins, lockouts and passkey enrolments, while the assertion stays green
    // because the subject id it looked for was never in the document either way. The rows
    // carry their own entry id, so the stranger's OWN entries are the thing to look for.
    $strangerEntries = AuditEntry::query()
        ->where('actor_id', $strangerId)
        ->pluck('id')
        ->all();

    // The fixture has to have produced some, or this proves nothing.
    expect($strangerEntries)->not->toBeEmpty()
        // And the reader has to have some of their own, or an empty list would satisfy
        // every assertion below.
        ->and($mine)->not->toBeEmpty();

    // ONE NEEDLE, NO MESSAGE: `toContain` is variadic, so a message argument is read as a
    // second needle and the negated form then asks "contains neither" — which a list
    // containing the first satisfies happily.
    foreach ($strangerEntries as $entryId) {
        expect($mine)->not->toContain($entryId);
    }
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

    $rows = collect(accountActivity()['activity']);

    expect($rows)->not->toBeEmpty();

    // A REAL TIMESTAMP on every row, not the em dash `created_at` produced. The page draws
    // its `<time datetime=…>` from this and from nothing else.
    foreach ($rows as $row) {
        expect($row['atIso'])->toMatch('/^\d{4}-\d{2}-\d{2}T/')
            ->and($row['at'])->toBeString();
    }
});
