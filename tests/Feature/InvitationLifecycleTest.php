<?php

declare(strict_types=1);

use App\Mail\OrganizationInviteMail;
use App\Platform\OrganizationActivity;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\Projects;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/**
 * THE INVITE WAS SEND-AND-FORGET.
 *
 * The page could send one and could not show it, so an invitation nobody accepted was
 * invisible: no way to see who was still outstanding, no way to send it again to somebody
 * who never got it, and no way to withdraw one sent to the wrong address — while the link
 * stayed live for a week. `pending()` and `revoke()` existed on the contract with no UI.
 *
 * And a mail transport failure threw a 500 at the person clicking Send while the
 * invitation row was already committed — invisible for the same reason, and unrepeatable,
 * because inviting the same address twice is refused.
 */
/**
 * An owner of an organization that OWNS A PRODUCT — the Identity-platform pages this
 * roster lives on are refused to an organization that owns none, and without one the page
 * renders a redirect and every assertion here is about an empty document.
 */
function invitingOwner(): string
{
    // The environment the console's own pages run in. `PlatformRoot::run()` is a no-op
    // returning null without one, so every read and write below would silently land in the
    // ambient scope instead — which is a different environment from the one the page uses.
    platformRootEnvironment();

    [, $org] = actingAsRole(MembershipRole::Owner);

    // A PRODUCT, because the Identity-platform pages this roster lives on are refused to
    // an organization that owns none — without it the page renders a redirect and every
    // assertion here is about an empty document.
    app(Projects::class)->createForOrganization($org->id, 'Acme');

    return $org->id;
}

function inviteFrom(string $organizationId, string $email = 'invitee@acme.test', MembershipRole $role = MembershipRole::Member): string
{
    // IN THE PLATFORM-ROOT ENVIRONMENT, because that is where the page both writes and
    // reads them. An invitation created in the ambient scope is a row the console cannot
    // see, and every assertion below would be about the wrong environment.
    return app(PlatformRoot::class)->run(
        fn () => app(Invitations::class)
            ->invite($organizationId, $email, $role, null)
            ->invitation->id,
    );
}

/** @return Collection<int, object> */
function pendingIn(string $organizationId): Collection
{
    return app(PlatformRoot::class)->run(fn () => app(Invitations::class)->pending($organizationId));
}

it('shows the invitations nobody has accepted', function (): void {
    $org = invitingOwner();
    inviteFrom($org, 'waiting@acme.test');

    Volt::test('console.members')
        ->assertSee('Invited, not joined yet')
        ->assertSee('waiting@acme.test');
});

it('sends the invitation again with a fresh link', function (): void {
    Mail::fake();
    $org = invitingOwner();
    $id = inviteFrom($org, 'waiting2@acme.test');

    Volt::test('console.members')->call('resendInvite', $id);

    Mail::assertSent(OrganizationInviteMail::class);

    // A NEW invitation, not the old one: the mailed link is signed over a token this
    // server only stores hashed, so there is nothing to re-send — and re-issuing is the
    // honest behaviour anyway, since the usual reason to ask is that the first expired.
    $pending = pendingIn($org);

    expect($pending)->toHaveCount(1)
        ->and($pending->first()?->id)->not->toBe($id);
});

it('withdraws an invitation so its link stops working', function (): void {
    Mail::fake();
    $org = invitingOwner();
    $id = inviteFrom($org, 'mistake@acme.test');

    Volt::test('console.members')->call('revokeInvite', $id);

    expect(pendingIn($org))->toHaveCount(0);
});

/**
 * A COMPONENT ACTION IS A POST ANYBODY SIGNED IN CAN MAKE, so an invitation id from
 * another organization must do nothing at all.
 */
it('refuses to touch an invitation belonging to another organization', function (): void {
    $org = invitingOwner();

    // A second organization, and an invitation inside it.
    $other = app(Organizations::class)
        ->create(new NewOrganization('Other', 'other-invite'));

    $theirs = inviteFrom($other->id, 'theirs@acme.test');

    Volt::test('console.members')->call('revokeInvite', $theirs);

    expect(pendingIn($other->id))->toHaveCount(1);

    // AND NOTHING WAS WRITTEN DOWN. `InvitationService::revoke()` scopes its own update by
    // organization, so the row survives either way — what the page's ownership lookup adds
    // is that it stops BEFORE recording a withdrawal that never happened, which would put
    // another organization's invitation id and email address into this one's activity log.
    expect(app(OrganizationActivity::class)->recent($org)->pluck('action'))
        ->not->toContain('organization.invitation_revoked');
})->group('security');

/**
 * A TRANSPORT FAILURE IS NOT A SUCCESSFUL INVITE. The row is committed before the mailer
 * runs, so an SMTP outage left an invitation nobody could see, nobody received, and nobody
 * could replace — inviting the same address again is refused.
 */
it('creates nothing when the invitation could not be sent', function (): void {
    $org = invitingOwner();

    Mail::shouldReceive('to')->andThrow(new RuntimeException('the mail server refused it'));

    $component = Volt::test('console.members')
        ->set('inviteEmail', 'unreachable@acme.test')
        ->set('inviteRole', 'member')
        ->call('invite');

    $component->assertHasErrors('inviteEmail');

    expect(pendingIn($org))->toHaveCount(0);
});

/**
 * A COMPONENT ACTION IS A POST ANYBODY SIGNED IN CAN REPEAT, and this one sends mail
 * inline off a sending domain shared with every other tenant. A held-down button was an
 * outbound flood billed to our reputation.
 */
it('sends at most one invitation mail a minute to the same address', function (): void {
    Mail::fake();
    $org = invitingOwner();
    $id = inviteFrom($org, 'flooded@acme.test');

    $page = Volt::test('console.members');

    $page->call('resendInvite', $id);

    // The second attempt names the NEW invitation, because the first resend superseded
    // the one we started with — replaying against a stale id would be refused for the
    // wrong reason and the throttle would not be what this test measured.
    $page->call('resendInvite', (string) pendingIn($org)->first()?->id);

    Mail::assertSentCount(1);
});

/**
 * AND A TRANSPORT FAILURE ON A RESEND LEAVES THE INVITATION ALIVE.
 *
 * Unlike the create path — where rolling back leaves the screen saying nothing happened,
 * which is true — this person already had an invitation. Revoking the replacement when
 * the mail failed left them with none at all: the original had been superseded, the
 * replacement was destroyed, and the link already in their inbox was dead.
 */
it('keeps the invitation alive when a resend cannot be delivered', function (): void {
    $org = invitingOwner();
    $id = inviteFrom($org, 'undeliverable@acme.test');

    Mail::shouldReceive('to')->andThrow(new RuntimeException('the mail server refused it'));

    Volt::test('console.members')->call('resendInvite', $id);

    expect(pendingIn($org))->toHaveCount(1);
});
