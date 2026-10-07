<?php

declare(strict_types=1);

use App\Mail\AdminAssignedPasswordMail;
use App\Mail\EmailVerificationMail;
use App\Mail\InvitationMail;
use App\Mail\MagicLinkMail;
use App\Mail\OrganizationInviteMail;
use App\Mail\PasswordResetMail;
use App\Platform\Locale\HostedLocale;
use App\Platform\Locale\LocaleResolver;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * THE MAIL IS IN THE RECIPIENT'S LANGUAGE, UNDER THE DEPLOYMENT'S NAME.
 *
 * Every mailable, in every language: no raw catalogue key anywhere in the subject or the
 * body (a missing line renders as `mail.magic_link.body`, which is worse than English),
 * `<html lang>` says which language it is, and the product name is the configured brand —
 * the bodies used to hard-code "Cbox ID" under a subject that already used the brand.
 *
 * Then the send sites: a person asking for their own link gets it in the language of the
 * page they asked from; an administrator's invitation goes out in the environment's
 * default, because the console the administrator is in says nothing about the invitee.
 */
beforeEach(function (): void {
    installedDeployment();
    config(['cbox-id.branding.name' => 'Northwind Identity']);
});

/** @return array<string, Closure(): Mailable> */
function everyMailable(): array
{
    return [
        'magic link' => fn (): Mailable => new MagicLinkMail('https://id.example/magic/abc'),
        'password reset' => fn (): Mailable => new PasswordResetMail('https://id.example/reset/abc'),
        'email verification' => fn (): Mailable => new EmailVerificationMail('https://id.example/verify/abc'),
        'admin-assigned password' => fn (): Mailable => new AdminAssignedPasswordMail('correct-horse', true, Carbon::parse('2026-10-08 15:00:00')),
        'invitation' => fn (): Mailable => new InvitationMail('Acme', 'Ada Lovelace', 'https://id.example/i/abc', 'Member', 'Acme Dashboard'),
        'organization invite' => fn (): Mailable => new OrganizationInviteMail('Acme', 'Ada Lovelace', 'https://id.example/o/abc', 'Admin'),
    ];
}

it('renders in every language with no missing line and the configured brand', function (Closure $make, string $locale): void {
    /** @var Mailable $mail */
    $mail = $make();
    $mail->locale($locale);

    $html = $mail->render();
    $subject = (string) $mail->withLocale($locale, fn () => $mail->envelope()->subject);

    expect($html)->toContain('<html lang="'.$locale.'"')
        ->and($html)->not->toMatch('/\bmail\.[a-z_]+\.[a-z_]+/')
        ->and($subject)->not->toMatch('/\bmail\.[a-z_]+\.[a-z_]+/')
        ->and($html)->toContain('Northwind Identity')
        ->and($html)->not->toContain('Cbox ID');

    // A name drawn in bold stays escaped, and stays bold.
    if ($mail instanceof InvitationMail || $mail instanceof OrganizationInviteMail) {
        expect($html)->toContain('<b>Ada Lovelace</b>')->toContain('<b>Acme</b>');
    }
})->with(everyMailable())->with(HostedLocale::codes());

it('translates the body, not just the frame', function (Closure $make): void {
    /** @var Mailable $english */
    $english = $make();
    /** @var Mailable $danish */
    $danish = $make();

    expect($danish->locale('da')->render())->not->toBe($english->locale('en')->render());
})->with(everyMailable());

it('writes the admin-assigned expiry in the reader’s language', function (): void {
    $at = Carbon::parse('2026-10-08 15:00:00');

    // English is exactly what `toDayDateTimeString()` used to produce.
    expect((new AdminAssignedPasswordMail('pw', false, $at))->locale('en')->render())->toContain($at->toDayDateTimeString())
        ->and((new AdminAssignedPasswordMail('pw', false, $at))->locale('da')->render())->toContain('okt.');
});

it('escapes an inviter’s name rather than trusting it into the HTML', function (): void {
    $html = (new InvitationMail('Acme', '<script>x</script>', 'https://id.example/i/abc'))->locale('de')->render();

    expect($html)->not->toContain('<script>x</script>')->toContain('&lt;script&gt;');
});

it('sends a self-service link in the language of the page it was asked from', function (): void {
    Mail::fake();
    $subject = app(Subjects::class)->create('ada@acme.test', 'Ada', 'a-strong-unbreached-passphrase');
    $organization = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-mail-locale'));
    app(Memberships::class)->add($organization->id, $subject->id, MembershipRole::Member);

    test()->withCookie(LocaleResolver::COOKIE, 'sv')
        ->from('/forgot-password')
        ->post(route('password.email'), ['email' => 'ada@acme.test'])->assertSessionHasNoErrors();

    Mail::assertSent(PasswordResetMail::class, fn (PasswordResetMail $mail): bool => $mail->locale === 'sv');
});

it('sends an administrator’s invitation in the hosted pages’ default language', function (): void {
    Mail::fake();

    // The deployment's default, which is what an environment with no language settings of
    // its own answers with ({@see \App\Platform\Locale\HostedLocales}).
    config(['cbox-id.locales.default' => 'nb']);

    actingAsRole(MembershipRole::Owner);

    // The administrator's own browser prefers German; that says nothing about the invitee.
    test()->withHeader('Accept-Language', 'de');
    inviteToDirectory()->assertSessionHasNoErrors();

    Mail::assertSent(InvitationMail::class, fn (InvitationMail $mail): bool => $mail->locale === 'nb');
});
