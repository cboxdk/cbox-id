<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Sign a subject in on the org plane and return their id. */
function signInToAccount(): string
{
    $subject = app(Subjects::class)->create('me@acme.test', 'Original Name', 'a-strong-unbreached-passphrase');
    app(Subjects::class)->markEmailVerified($subject->id, 'me@acme.test');

    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-account'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    /*
     * The session the way a real sign-in leaves it: a live session row AND the key in the
     * request session. `CurrentUser::set()` alone — which is what this used to do — is
     * enough for a component driven in-process and nothing at all to an HTTP request,
     * which then arrives with no session and is redirected to the door. Every assertion
     * after such a redirect passes: `assertSessionHasNoErrors` is perfectly true of a 302.
     */
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);

    // The page's writes are behind `sudo`; the gate itself is exercised in SudoTest, and
    // holding it open here keeps these tests about what they are named for.
    app(Sudo::class)->confirm();

    return $subject->id;
}

/**
 * A person can change their own name.
 *
 * The Profile panel was a definition list — so the name someone is addressed by on every
 * screen, and the label stamped on their passkeys, could only be changed by an
 * administrator, or not at all if they had none. That is the most ordinary self-service
 * edit there is, and it was simply missing.
 */
it('lets a signed-in person rename themselves', function (): void {
    $id = signInToAccount();

    saveOwnProfile(['displayName' => 'Sylvester Damgaard'])->assertSessionHasNoErrors();

    expect(app(Subjects::class)->find($id)?->name)->toBe('Sylvester Damgaard');
});

it('refuses to blank out a name', function (): void {
    $id = signInToAccount();

    saveOwnProfile(['displayName' => '   '])->assertSessionHasErrors('displayName');

    expect(app(Subjects::class)->find($id)?->name)->toBe('Original Name');
});

/**
 * The email is deliberately NOT editable here. It is the sign-in identifier, and letting
 * it change without re-proving control of the new address is how an account is taken
 * over — so it belongs in a verification flow, not a text field on this page. Asserted
 * so that adding one later is a decision someone makes on purpose.
 */
it('keeps the email read-only on the account page', function (): void {
    signInToAccount();

    /*
     * ASKED OF THE PAGE, not of a file. This read the blade and grepped it for a
     * `wire:model` bound to an email — a claim about markup that no longer exists, and one
     * a controller could contradict freely.
     *
     * What the page OFFERS is the set of URLs it carries and the fields those accept. The
     * address is present to READ, and no write on this page takes one: the profile route is
     * the only editor here, and its request rejects anything but a display name.
     */
    $props = accountSecurity();

    expect($props['profile']['email'])->toBe('me@acme.test');

    saveOwnProfile(['displayName' => 'Still Me', 'email' => 'attacker@evil.test'])
        ->assertSessionHasNoErrors();

    expect(app(Subjects::class)->findByEmail('attacker@evil.test'))->toBeNull()
        ->and(app(Subjects::class)->findByEmail('me@acme.test'))->not->toBeNull();
})->group('security');

/**
 * A name made only of spaces is not a name.
 *
 * The rule ran on the raw value and the write trimmed it, so `"   "` satisfied
 * `required|min:1` and stored an empty string — blanking the avatar initial and the
 * label stamped on the person's passkeys. Validate what actually gets written.
 */
it('refuses a name that is only whitespace', function (): void {
    $id = signInToAccount();

    saveOwnProfile(['displayName' => '     '])->assertSessionHasErrors('displayName');

    expect(app(Subjects::class)->find($id)?->name)->toBe('Original Name');
});
