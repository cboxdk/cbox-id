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

    Mail::assertQueued(InvitationMail::class, function (InvitationMail $mail): bool {
        $mail->assertHasSubject('Owner invited you to join Acme');
        $mail->assertSeeInHtml('as <b>Member</b>', false);
        // The mailed link opens a page; it does not accept anything by itself.
        $mail->assertSeeInHtml('nothing happens until you do');

        return true;
    });
});

it('names the native app and selected role when inviting a teammate', function (): void {
    ['organization' => $organization, 'subjectId' => $ownerId] = provisionAccount('owner@acme.example');
    signInAsMember($ownerId);
    confirmStepUp();

    test()->from(route('orgs.settings.members'))
        ->post(route('orgs.settings.members.invite', ['organization' => $organization->id]), ['email' => 'new@acme.example', 'roles' => ['developer', 'billing']])
        ->assertSessionHasNoErrors();

    Mail::assertQueued(InvitationMail::class, function (InvitationMail $mail) use ($ownerId): bool {
        $mail->assertHasSubject('Owner invited you to join Acme');
        expect($mail->app)->toBe('Cbox ID')->and($mail->role)->toBe('Billing, Editor');

        // The subject id is exactly what used to sit where the name belongs.
        expect($mail->inviter)->toBe('Owner')->not->toBe($ownerId);

        return true;
    });

    Mail::assertNotQueued(OrganizationInviteMail::class);
});
