<?php

declare(strict_types=1);

use App\Mail\EmailVerificationMail;
use App\Mail\PasswordResetMail;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
});

function resetAccount(string $email = 'dana@acme.test'): string
{
    $subject = app(Subjects::class)->create($email, 'Dana', 'old-password-1234');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-'.substr(md5($email), 0, 6)));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    return $subject->id;
}

it('emails a reset link for a known account and resets the password end-to-end', function () {
    $subjectId = resetAccount();

    // The SAME confirmation either way, and it rides the flash channel because it names
    // the address it was sent to — a value that must not be written into the browser's
    // history entry alongside the page props.
    test()->from(route('password.request'))->post(route('password.email'), ['email' => 'dana@acme.test'])
        ->assertRedirect(route('password.request'))
        ->assertSessionHasNoErrors();

    Mail::assertSent(PasswordResetMail::class);

    // The raw token isn't stored (hash only) — capture it from the sent mailable.
    $raw = null;
    Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) use (&$raw) {
        $raw = str_contains($mail->url, '/reset-password/') ? substr($mail->url, strpos($mail->url, '/reset-password/') + 16) : null;

        return true;
    });

    expect($raw)->toBeString();

    // THE TOKEN IS THE FORM'S, not the session's: it arrives in the URL of the page the
    // mail points at and is posted back with the new password. Nothing here resolves the
    // subject from it before the reset — doing so would make this page an
    // account-existence oracle.
    test()->from(route('password.reset', $raw))
        ->post(route('password.update'), [
            'token' => $raw,
            'password' => 'brand-new-password-5678',
            'password_confirmation' => 'brand-new-password-5678',
        ])
        ->assertRedirect(route('login'));

    expect(app(Subjects::class)->verifyPassword($subjectId, 'brand-new-password-5678'))->toBeTrue();
});

it('shows the same confirmation and sends no mail for an unknown email (anti-enumeration)', function () {
    test()->from(route('password.request'))->post(route('password.email'), ['email' => 'nobody@acme.test'])
        ->assertRedirect(route('password.request'))
        // THE SAME ANSWER as for a known address, which is the whole point: a refusal
        // here would report that the address is unknown to anybody who asked.
        ->assertSessionHasNoErrors();

    Mail::assertNothingSent();
});

it('rejects an invalid reset token', function () {
    test()->from(route('password.reset', 'pwr_bogus'))
        ->post(route('password.update'), [
            'token' => 'pwr_bogus',
            'password' => 'brand-new-password-5678',
            'password_confirmation' => 'brand-new-password-5678',
        ])
        // THE REASON, not merely "an error on the password field" — a policy refusal and a
        // rejected token both land there, and only one of them is what this is about.
        ->assertSessionHasErrors([
            'password' => 'This reset link is invalid or has expired. Request a new one.',
        ]);
});

it('signup sends an email-verification link, and the link verifies the address', function () {
    test()->from(route('signup'))->post(route('signup.register'), ['organization' => 'Acme Inc.', 'name' => 'Dana', 'email' => 'newbie@acme.test', 'password' => 'supersecret1234'])
        ->assertRedirect(route('dashboard'));

    $raw = null;
    Mail::assertSent(EmailVerificationMail::class, function (EmailVerificationMail $mail) use (&$raw) {
        $raw = substr($mail->url, strpos($mail->url, '/verify-email/') + 14);

        return true;
    });

    $subjectId = app(Subjects::class)->findByEmail('newbie@acme.test')->id;
    User::query()->whereKey($subjectId)->update(['email_verified_at' => null]);

    // …to PROJECTS, not to the sign-in page. Signup establishes the session before the mail
    // goes out, so by the time the link is clicked there is one — and the controller lands a
    // signed-in person where they were going. It used to land on `login` because the session
    // it looked for was the subject's while signup wrote an account-plane one; there is one
    // session now, so the two halves agree.
    $this->get('/verify-email/'.$raw)->assertRedirect(route('projects'));

    expect(User::query()->whereKey($subjectId)->value('email_verified_at'))->not->toBeNull();
});
