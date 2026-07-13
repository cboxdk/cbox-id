<?php

declare(strict_types=1);

use App\Mail\EmailVerificationMail;
use App\Mail\PasswordResetMail;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;

beforeEach(function () {
    Mail::fake();
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
});

function resetAccount(string $email = 'dana@acme.test'): string
{
    $subject = app(Subjects::class)->create($email, 'Dana', 'old-password-1234');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-'.substr(md5($email), 0, 6)));
    app(Memberships::class)->add($org->id, $subject->id, 'owner');

    return $subject->id;
}

it('emails a reset link for a known account and resets the password end-to-end', function () {
    $subjectId = resetAccount();

    Volt::test('auth.forgot-password')
        ->set('email', 'dana@acme.test')
        ->call('sendResetLink')
        ->assertSet('sent', true);

    Mail::assertSent(PasswordResetMail::class);

    // The raw token isn't stored (hash only) — capture it from the sent mailable.
    $raw = null;
    Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) use (&$raw) {
        $raw = str_contains($mail->url, '/reset-password/') ? substr($mail->url, strpos($mail->url, '/reset-password/') + 16) : null;

        return true;
    });

    Volt::test('auth.reset-password', ['token' => $raw])
        ->set('password', 'brand-new-password-5678')
        ->set('password_confirmation', 'brand-new-password-5678')
        ->call('resetPassword')
        ->assertRedirect(route('login'));

    expect(app(Subjects::class)->verifyPassword($subjectId, 'brand-new-password-5678'))->toBeTrue();
});

it('shows the same confirmation and sends no mail for an unknown email (anti-enumeration)', function () {
    Volt::test('auth.forgot-password')
        ->set('email', 'nobody@acme.test')
        ->call('sendResetLink')
        ->assertSet('sent', true);

    Mail::assertNothingSent();
});

it('rejects an invalid reset token', function () {
    Volt::test('auth.reset-password', ['token' => 'pwr_bogus'])
        ->set('password', 'brand-new-password-5678')
        ->set('password_confirmation', 'brand-new-password-5678')
        ->call('resetPassword')
        ->assertHasErrors('password');
});

it('signup sends an email-verification link, and the link verifies the address', function () {
    Volt::test('auth.signup')
        ->set('organization', 'Acme Inc.')
        ->set('name', 'Dana')
        ->set('email', 'newbie@acme.test')
        ->set('password', 'supersecret1234')
        ->call('register')
        ->assertRedirect(route('dashboard'));

    $raw = null;
    Mail::assertSent(EmailVerificationMail::class, function (EmailVerificationMail $mail) use (&$raw) {
        $raw = substr($mail->url, strpos($mail->url, '/verify-email/') + 14);

        return true;
    });

    $subjectId = app(Subjects::class)->findByEmail('newbie@acme.test')->id;
    User::query()->whereKey($subjectId)->update(['email_verified_at' => null]);

    $this->get('/verify-email/'.$raw)->assertRedirect(route('login'));

    expect(User::query()->whereKey($subjectId)->value('email_verified_at'))->not->toBeNull();
});
