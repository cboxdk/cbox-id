<?php

declare(strict_types=1);

use App\Mail\EmailVerificationMail;
use App\Platform\MemberEmailVerification;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Models\AccountMember;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;

/**
 * Deferring the environment until the address is proven (see SignupDeferredEnvironmentTest)
 * put a real owner's whole account behind one email. Lose it, filter it, or let the
 * 24-hour token lapse, and there is no environment and no way to ask for another link —
 * the account is a dead end until the token expires and the address is free to sign up
 * again. These tests pin the way out, and the four things that keep the way out from
 * becoming its own hole: it mails only the caller's own address, it is throttled, it says
 * nothing about whether the address is already confirmed, and it leaves exactly one live
 * link behind.
 */
beforeEach(function (): void {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    Mail::fake();
});

/** The platform root, served on this test's host — how cboxid.com resolves (Tier 2). */
function rootForResend(): Environment
{
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);

    $root = Environment::query()->create([
        'name' => 'Production',
        'slug' => 'platform-root',
        'status' => 'active',
        'is_default' => true,
    ]);

    app(EnvironmentContext::class)->set($root);

    return $root;
}

/** Sign up a workspace and stay signed in as its owner (signup establishes the session). */
function signUpForResend(string $email = 'dana@acme.example', string $organization = 'Acme'): AccountMember
{
    Volt::test('auth.signup')
        ->set('organization', $organization)
        ->set('name', 'Dana Reeves')
        ->set('email', $email)
        ->set('password', 'a-strong-unbreached-passphrase')
        ->call('register')
        ->assertHasNoErrors();

    $member = app(AccountMembers::class)->findByEmail($email);
    expect($member)->not->toBeNull();

    /** @var AccountMember $member */
    return $member;
}

/** Every verification link mailed so far, oldest first. */
function verificationLinks(): array
{
    return Mail::sent(EmailVerificationMail::class)
        ->map(static fn (EmailVerificationMail $mail): string => $mail->url)
        ->all();
}

it('sends a fresh link to the signed-in member and to nobody else', function (): void {
    rootForResend();

    // A second workspace, so "the signed-in member's address" is a real distinction and
    // not the only address in the database.
    signUpForResend('eve@other.example', 'Other');
    signUpForResend('dana@acme.example');

    $before = count(verificationLinks());

    Volt::test('workspace.home')
        ->call('resendVerification')
        ->assertRenderedNotRedirected()
        ->assertSee('on its way to dana@acme.example');

    $sent = Mail::sent(EmailVerificationMail::class);

    expect($sent)->toHaveCount($before + 1);

    // The new mail went to the signed-in owner. Eve, whose signup mail is also in this
    // mailbox, received nothing further.
    $resent = $sent->last();
    expect($resent->hasTo('dana@acme.example'))->toBeTrue();

    $toEve = $sent->filter(static fn (Mailable $m): bool => $m->hasTo('eve@other.example'));
    expect($toEve)->toHaveCount(1); // her own signup mail, and only that
});

it('takes no address argument, so a crafted call cannot steer where the mail goes', function (): void {
    // The functional test above proves the mail lands on the member's own address; this
    // pins WHY it cannot do otherwise — there is no address input to point elsewhere.
    $action = new ReflectionMethod(MemberEmailVerification::class, 'resend');

    expect($action->getNumberOfParameters())->toBe(1)
        ->and((string) $action->getParameters()[0]->getType())->toBe(AccountMember::class);

    // The Livewire control likewise takes only container-resolved services — no scalar a
    // request payload could supply.
    $component = Volt::test('workspace.home')->instance();

    foreach ((new ReflectionMethod($component, 'resendVerification'))->getParameters() as $parameter) {
        $type = $parameter->getType();
        expect($type)->toBeInstanceOf(ReflectionNamedType::class)
            ->and($type->isBuiltin())->toBeFalse();
    }
});

it('offers the resend control on the launchpad while the environment is held back', function (): void {
    rootForResend();
    signUpForResend();

    // The banner's own control, asserted on copy that exists nowhere else on the page.
    $this->get(route('workspace.home'))
        ->assertOk()
        ->assertSee('Send the link again')
        ->assertSee('The link stays valid for 24 hours.');
});

it('refuses the fourth resend inside the window', function (): void {
    rootForResend();
    signUpForResend();

    $component = Volt::test('workspace.home');

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $component->call('resendVerification')->assertSee('on its way to dana@acme.example');
    }

    $component->call('resendVerification')
        ->assertDontSee('on its way to dana@acme.example')
        ->assertSee('That is a lot of emails.');

    // Three links, not four: the throttle refuses before anything is mailed.
    expect(Mail::sent(EmailVerificationMail::class))->toHaveCount(4); // 1 signup + 3 resends
});

it('says exactly the same thing whether or not the address is already confirmed', function (): void {
    rootForResend();
    $member = signUpForResend();

    $unverifiedNotice = Volt::test('workspace.home')
        ->call('resendVerification')
        ->get('resendNotice');

    // Confirm the address WITHOUT releasing an environment — the state a suspended or
    // at-limit account sits in, and the only place a resend could leak verification.
    $subjectId = $member->subject_id;
    expect($subjectId)->toBeString();
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified((string) $subjectId, $member->email));

    $mailedSoFar = Mail::sent(EmailVerificationMail::class)->count();

    $verifiedNotice = Volt::test('workspace.home')
        ->call('resendVerification')
        ->get('resendNotice');

    expect($verifiedNotice)->toBe($unverifiedNotice)
        // Nothing was actually mailed to an address that needs no confirming — the
        // silence is what makes the identical message honest rather than merely quiet.
        ->and(Mail::sent(EmailVerificationMail::class))->toHaveCount($mailedSoFar);
});

it('leaves exactly one live link: the resent one works and the earlier one does not', function (): void {
    rootForResend();
    $member = signUpForResend();

    Volt::test('workspace.home')->call('resendVerification');

    $links = verificationLinks();
    expect($links)->toHaveCount(2);

    [$original, $resent] = $links;
    expect($original)->not->toBe($resent);

    // The superseded link is dead — it provisions nothing.
    $this->get($original)->assertRedirect(route('login'));
    expect(Environment::query()->where('account_id', $member->account_id)->exists())->toBeFalse();

    // The newest one is the one that works.
    $this->get($resent)->assertRedirect();
    expect(Environment::query()->where('account_id', $member->account_id)->count())->toBe(1);
});

it('is a harmless no-op once the environment has been released', function (): void {
    rootForResend();
    $member = signUpForResend();

    [$link] = verificationLinks();
    $this->get($link)->assertRedirect();
    expect(Environment::query()->where('account_id', $member->account_id)->count())->toBe(1);

    $mailedSoFar = Mail::sent(EmailVerificationMail::class)->count();

    Volt::test('workspace.home')
        ->call('resendVerification')
        ->assertRenderedNotRedirected()
        ->assertHasNoErrors()
        ->assertSee('your environment is already up and running');

    expect(Mail::sent(EmailVerificationMail::class))->toHaveCount($mailedSoFar)
        ->and(Environment::query()->where('account_id', $member->account_id)->count())->toBe(1);
});
