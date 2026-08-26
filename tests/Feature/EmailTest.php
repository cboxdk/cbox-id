<?php

declare(strict_types=1);

use App\Mail\InvitationMail;
use App\Mail\MagicLinkMail;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Support\Facades\Mail;

it('emails a single-use magic link', function () {
    Mail::fake();

    test()->from(route('login'))->post(route('login.magic-link'), ['email' => 'someone@acme.test'])
        // THE SAME NEUTRAL CONFIRMATION either way — the flash names the address it was
        // sent to, and its presence is the confirmation. A refusal here would report
        // whether the address is registered to anybody who asked.
        ->assertRedirect(route('login'))
        ->assertSessionHasNoErrors();

    Mail::assertSent(MagicLinkMail::class, fn (MagicLinkMail $mail) => $mail->hasTo('someone@acme.test'));
});

it('emails an invitation when an admin invites a member', function () {
    Mail::fake();
    actingAsRole(MembershipRole::Owner);

    inviteToDirectory(['email' => 'invitee@acme.test'])->assertSessionHasNoErrors();

    Mail::assertSent(InvitationMail::class, fn (InvitationMail $mail) => $mail->hasTo('invitee@acme.test'));
});
