<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Kernel\Crypto\Contracts\TokenSigner;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

it('signs out every other session but keeps the current one', function (): void {
    $subject = app(Subjects::class)->create('multi@acme.test', 'Multi Device', 'a-strong-unbreached-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-multi'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    $sessions = app(SessionManager::class);
    $current = $sessions->start($subject->id, $org->id, ['pwd']);
    $other1 = $sessions->start($subject->id, $org->id, ['pwd']);
    $other2 = $sessions->start($subject->id, $org->id, ['pwd']);

    app(CurrentUser::class)->set($subject, $current, $org, MembershipRole::Owner);
    app(Sudo::class)->confirm(); // sensitive action

    Volt::test('account')->call('signOutOtherSessions')->assertHasNoErrors();

    // Current stays active; the two others are revoked.
    expect(Session::query()->whereKey($current->id)->value('revoked_at'))->toBeNull()
        ->and(Session::query()->whereKey($other1->id)->value('revoked_at'))->not->toBeNull()
        ->and(Session::query()->whereKey($other2->id)->value('revoked_at'))->not->toBeNull();
});

it('requires step-up before signing out other sessions', function (): void {
    $subject = app(Subjects::class)->create('multi2@acme.test', 'Multi Device', 'a-strong-unbreached-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-multi2'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
    $current = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    $other = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $current, $org, MembershipRole::Owner);

    // No sudo confirmation -> redirected, nothing revoked.
    Volt::test('account')->call('signOutOtherSessions')->assertRedirect(route('sudo'));

    expect(Session::query()->whereKey($other->id)->value('revoked_at'))->toBeNull();
});

/**
 * RP-INITIATED LOGOUT REVOKED NOTHING.
 *
 * `/oauth/logout` is OIDC's global sign-out: a relying party sends the person here and
 * every session they hold is supposed to end. The package's endpoint resolved the subject
 * with `auth()->id()` — and this application never populates Laravel's guard. One person
 * is one session here, with account, environment and organization as selections on top of
 * it; three things a guard cannot model.
 *
 * So the endpoint cleared this browser's session, answered 200 and redirected. Logout
 * LOOKED correct. The revocation behind it never ran: other devices stayed signed in, and
 * the person's own session list went on listing sessions they believed they had just
 * ended.
 */
it('ends every session the person holds when a relying party asks for global sign-out', function (): void {
    $subject = app(Subjects::class)->create('rp-logout@acme.test', 'Global', 'a-strong-unbreached-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-rp-logout'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    $sessions = app(SessionManager::class);
    $thisBrowser = $sessions->start($subject->id, $org->id, ['pwd']);
    $phone = $sessions->start($subject->id, $org->id, ['pwd']);

    app(CurrentUser::class)->set($subject, $thisBrowser, $org, MembershipRole::Owner);

    // WITH A VERIFIED `id_token_hint`, because that is what tells this endpoint which
    // End-User the request concerns. It is unauthenticated by design and answers GET, so
    // without the hint "revoke everything this browser's owner holds" is something any
    // page on the internet could cause by sending somebody through a link.
    $hint = app(TokenSigner::class)->sign([
        'sub' => $subject->id,
        'aud' => 'some-client',
        'iat' => time(),
        'exp' => time() + 3600,
    ]);

    $this->get('/oauth/logout?'.http_build_query(['id_token_hint' => $hint]))->assertSuccessful();

    // EVERY session, including the one that made the request — "log me out everywhere"
    // means everywhere.
    expect(Session::query()->whereKey($phone->id)->value('revoked_at'))->not->toBeNull()
        ->and(Session::query()->whereKey($thisBrowser->id)->value('revoked_at'))->not->toBeNull();
});

/**
 * AND A REQUEST THAT CANNOT SAY WHO IT IS ABOUT SIGNS OUT ONLY THIS BROWSER.
 *
 * This is the shape a forged cross-site navigation takes: the victim follows a link, the
 * session cookie rides along, and the endpoint needs no credential. Revoking everything
 * there is a denial of service against every user of the deployment, delivered by an
 * anchor tag.
 */
it('leaves other devices alone when the logout request carries no proof', function (): void {
    $subject = app(Subjects::class)->create('rp-noproof@acme.test', 'No Proof', 'a-strong-unbreached-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-rp-noproof'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    $sessions = app(SessionManager::class);
    $thisBrowser = $sessions->start($subject->id, $org->id, ['pwd']);
    $phone = $sessions->start($subject->id, $org->id, ['pwd']);

    app(CurrentUser::class)->set($subject, $thisBrowser, $org, MembershipRole::Owner);

    $this->get('/oauth/logout')->assertSuccessful();

    expect(Session::query()->whereKey($phone->id)->value('revoked_at'))->toBeNull();
})->group('security');

/**
 * And nobody else's. The subject comes from what this browser has already proven, never
 * from anything the caller supplied — an endpoint that took a subject id from the request
 * would be a way to sign out any account on the platform.
 */
it('leaves another person\'s sessions alone', function (): void {
    $mine = app(Subjects::class)->create('mine@acme.test', 'Mine', 'a-strong-unbreached-passphrase');
    $theirs = app(Subjects::class)->create('theirs@acme.test', 'Theirs', 'a-strong-unbreached-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-rp-other'));
    app(Memberships::class)->add($org->id, $mine->id, MembershipRole::Owner);
    app(Memberships::class)->add($org->id, $theirs->id, MembershipRole::Member);

    $sessions = app(SessionManager::class);
    $myses = $sessions->start($mine->id, $org->id, ['pwd']);
    $their = $sessions->start($theirs->id, $org->id, ['pwd']);

    app(CurrentUser::class)->set($mine, $myses, $org, MembershipRole::Owner);

    $this->get('/oauth/logout?id_token_hint=whatever')->assertSuccessful();

    expect(Session::query()->whereKey($their->id)->value('revoked_at'))->toBeNull();
})->group('security');
