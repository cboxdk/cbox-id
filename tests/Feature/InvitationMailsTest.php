<?php

declare(strict_types=1);

use App\Mail\InvitationMail;
use App\Mail\OrganizationInviteMail;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Support\Facades\Mail;

/**
 * TWO INVITATIONS, TWO SUBJECT LINES.
 *
 * Joining an organization (an app login) and administering a customer account (the keys to
 * somebody's identity platform) went out under the same subject — "You've been invited to
 * Acme on Cbox ID" — so an inbox could not tell them apart. And the account one was signed
 * with the inviter's subject ULID where their name belonged.
 */
beforeEach(function (): void {
    installedDeployment();
    Mail::fake();
});

it('names the organization and the inviter when inviting somebody to join it', function (): void {
    actingAsRole(MembershipRole::Owner);

    inviteToDirectory()->assertSessionHasNoErrors();

    Mail::assertSent(InvitationMail::class, function (InvitationMail $mail): bool {
        $mail->assertHasSubject('Owner invited you to join Acme');
        $mail->assertSeeInHtml('as <b>Member</b>', false);
        // The mailed link opens a page; it does not accept anything by itself.
        $mail->assertSeeInHtml('nothing happens until you do');

        return true;
    });
});

it('says ADMINISTER, and signs with a name, when inviting a teammate to a customer account', function (): void {
    ['subjectId' => $ownerId] = provisionAccount('owner@acme.example');
    signInAsMember($ownerId);

    test()->from(route('members'))
        ->post(route('members.invite'), ['email' => 'new@acme.example', 'role' => 'developer'])
        ->assertSessionHasNoErrors();

    Mail::assertSent(OrganizationInviteMail::class, function (OrganizationInviteMail $mail) use ($ownerId): bool {
        $mail->assertHasSubject('Owner invited you to administer Acme on Cbox ID');

        // The subject id is exactly what used to sit where the name belongs.
        expect($mail->inviter)->toBe('Owner')->not->toBe($ownerId);

        return true;
    });

    Mail::assertNotSent(InvitationMail::class);
});
