<?php

declare(strict_types=1);

use App\Mail\InvitationMail;
use App\Mail\MagicLinkMail;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;

it('emails a single-use magic link', function () {
    Mail::fake();

    Volt::test('auth.login')
        ->set('email', 'someone@acme.test')
        ->call('sendMagicLink')
        ->assertSet('magicSent', true);

    Mail::assertSent(MagicLinkMail::class, fn (MagicLinkMail $mail) => $mail->hasTo('someone@acme.test'));
});

it('emails an invitation when an admin invites a member', function () {
    Mail::fake();
    actingAsRole('owner');

    Volt::test('members')
        ->set('inviteEmail', 'invitee@acme.test')
        ->set('inviteRole', 'member')
        ->call('invite')
        ->assertHasNoErrors();

    Mail::assertSent(InvitationMail::class, fn (InvitationMail $mail) => $mail->hasTo('invitee@acme.test'));
});
