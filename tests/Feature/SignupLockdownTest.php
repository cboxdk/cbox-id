<?php

declare(strict_types=1);

use App\Mail\MagicLinkMail;
use Cbox\Id\Identity\Contracts\Subjects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // These render product pages, which presuppose an installed deployment.
    installedDeployment();
});

it('serves the signup page when signup is open', function (): void {
    config(['cbox-id.signup.mode' => 'open']);

    $this->get(route('signup'))->assertOk();
});

it('redirects signup to sign-in when invite-only', function (): void {
    config(['cbox-id.signup.mode' => 'invite_only']);

    $this->get(route('signup'))->assertRedirect(route('login'));
});

it('redirects signup to sign-in when closed', function (): void {
    config(['cbox-id.signup.mode' => 'closed']);

    $this->get(route('signup'))->assertRedirect(route('login'));
});

it('forbids the register action if signup closes after the form was reached', function (): void {
    config(['cbox-id.signup.mode' => 'open']);

    // The form is REACHED while signup is open — otherwise the refusal below is about the
    // page being unreachable rather than about the submit being refused.
    test()->get(route('signup'))->assertOk();

    // …and closes before it is submitted. The window between a page load and its submit is
    // exactly where a gate that only guards the GET does nothing at all.
    config(['cbox-id.signup.mode' => 'closed']);

    attemptSignup(['name' => 'Ada Lovelace', 'email' => 'ada@acme.test'])->assertForbidden();

    expect(app(Subjects::class)->findByEmail('ada@acme.test'))->toBeNull();
});

it('does not mint an account via a magic link for an unknown email when signup is closed', function (): void {
    config(['cbox-id.signup.mode' => 'closed']);
    Mail::fake();

    // Redeeming a magic link would create the account (findByEmail ?? create), so
    // an unqualified link is a signup bypass. Under closed signup, an unknown email
    // must get NO link and NO account — while still seeing the neutral confirmation.
    test()->from(route('login'))->post(route('login.magic-link'), ['email' => 'ghost@nowhere.test'])
        ->assertRedirect(route('login'))
        ->assertSessionHasNoErrors();

    /*
     * THE SAME NEUTRAL CONFIRMATION AS FOR A KNOWN ADDRESS, read on the page the redirect
     * lands on — the flash names the address it was "sent" to, and its presence is the
     * confirmation. This is the assertion that matters: no mail went, and the page said
     * nothing that distinguishes this from the case below, so the door is not an
     * account-existence oracle.
     */
    test()->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->hasFlash('magicSentTo', 'ghost@nowhere.test'));

    Mail::assertNothingSent();
    expect(app(Subjects::class)->findByEmail('ghost@nowhere.test'))->toBeNull();
});

it('still sends a magic link to an existing account when signup is closed', function (): void {
    config(['cbox-id.signup.mode' => 'closed']);
    Mail::fake();
    app(Subjects::class)->create('member@acme.test', 'Member', 'a-strong-unbreached-passphrase');

    test()->from(route('login'))->post(route('login.magic-link'), ['email' => 'member@acme.test'])
        ->assertRedirect(route('login'))
        ->assertSessionHasNoErrors();

    // Character for character what the unknown address above was told.
    test()->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->hasFlash('magicSentTo', 'member@acme.test'));

    Mail::assertSent(MagicLinkMail::class);
});

it('offers the sign-in page a way to create an account only when signup is open', function (): void {
    // The PROP, not the words on the link. Whether the door exists is the deployment's
    // decision and belongs in a test; what the link says is the page's, and changing it
    // is not a regression.
    config(['cbox-id.signup.mode' => 'open']);
    $this->get(route('login'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('signupOpen', true));

    config(['cbox-id.signup.mode' => 'invite_only']);
    $this->get(route('login'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('signupOpen', false));
});
