<?php

declare(strict_types=1);

use App\Mail\AdminAssignedPasswordMail;
use App\Mail\ApprovalRequestMail;
use App\Mail\BrandedMailable;
use App\Mail\CertificateExpiringMail;
use App\Mail\EmailVerificationMail;
use App\Mail\InvitationMail;
use App\Mail\MagicLinkMail;
use App\Mail\OneTimeCodeMail;
use App\Mail\OrganizationInviteMail;
use App\Mail\PasswordResetMail;
use App\Mail\PortalLinkMail;
use App\Platform\Otp\BrandedEmailOtpChannel;
use Cbox\Id\Identity\EmailVerificationService;
use Cbox\Id\Identity\MagicLinkService;
use Cbox\Id\Identity\PasswordResetService;
use Cbox\Id\Otp\ValueObjects\OtpDelivery;
use Cbox\Relay\Client\Mail\RelayMailTransport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/*
 * System mail goes through Cbox Relay and nothing else (Brain mem_MeQkGzrwgYRrXMWJJtSECE):
 * no Resend, no failover to a second provider. What carries a mail through a Relay outage
 * is the queue: every mail is queued and retried with a growing pause for up to six hours,
 * or until the link or code inside it stops working, whichever is sooner.
 */

it('sends through the relay transport when MAIL_MAILER is relay', function (): void {
    config(['mail.default' => 'relay', 'relay.url' => 'https://relay.example.test', 'relay.key' => 'fixture-key']);
    app()->forgetInstance('mail.manager');
    app()->forgetInstance('mailer');
    Mail::clearResolvedInstances();

    expect(Mail::mailer()->getSymfonyTransport())->toBeInstanceOf(RelayMailTransport::class);
});

it('has no Resend client and no failover to Resend', function (): void {
    // Laravel merges its stock `resend` mailer entry back under config/mail.php; without
    // the client it names, it cannot send a thing.
    expect(class_exists('Resend'))->toBeFalse()
        ->and(config('mail.mailers.failover.mailers'))->toBe(['smtp', 'log']);
});

/** @return array<string, BrandedMailable> */
function everyMail(): array
{
    return [
        'magic link' => new MagicLinkMail('https://id.example/magic/abc'),
        'password reset' => new PasswordResetMail('https://id.example/reset/abc'),
        'email verification' => new EmailVerificationMail('https://id.example/verify/abc'),
        'one-time code' => new OneTimeCodeMail('123456', 'login_step_up', 10),
        'approval request' => new ApprovalRequestMail('App', 'https://id.example/approvals', Carbon::now()->addMinutes(5)),
        'portal link' => new PortalLinkMail('Acme', 'https://id.example/portal/abc', ['sso'], Carbon::now()->addDays(7)),
        'admin-assigned password' => new AdminAssignedPasswordMail('secret-password-1', true, Carbon::now()->addHours(2)),
        'invitation' => new InvitationMail('Acme', 'Dana', 'https://id.example/invite/abc'),
        'organization invite' => new OrganizationInviteMail('Acme', 'Dana', 'https://id.example/invite/def'),
        'certificate expiring' => new CertificateExpiringMail('Acme', 'Okta', Carbon::now()->addDays(14), 14),
    ];
}

it('queues every mail and retries it for at most six hours with a growing pause', function (): void {
    foreach (everyMail() as $name => $mail) {
        $until = Carbon::instance($mail->retryUntil());

        expect($mail)->toBeInstanceOf(ShouldQueue::class, $name)
            ->and($until->isFuture())->toBeTrue($name)
            ->and($until->lessThanOrEqualTo(Carbon::now()->addHours(BrandedMailable::RETRY_HOURS)))->toBeTrue($name)
            ->and($mail->backoff())->toBe([10, 30, 60, 120, 300, 600, 900]);
    }
});

it('stops retrying a short-lived link or code when it stops working', function (): void {
    Carbon::setTestNow('2026-11-13 12:00:00');

    $minutes = static fn (BrandedMailable $mail): int => (int) Carbon::now()->diffInMinutes($mail->retryUntil());

    expect($minutes(new MagicLinkMail('https://id.example/magic/abc')))->toBe(15)
        ->and($minutes(new PasswordResetMail('https://id.example/reset/abc')))->toBe(60)
        ->and($minutes(new OneTimeCodeMail('123456', 'login_step_up', 10)))->toBe(10)
        ->and($minutes(new ApprovalRequestMail('App', 'https://id.example/a', Carbon::now()->addMinutes(5))))->toBe(5)
        ->and($minutes(new PortalLinkMail('Acme', 'https://id.example/p', ['sso'], Carbon::now()->addMinutes(90))))->toBe(90)
        ->and($minutes(new AdminAssignedPasswordMail('secret-password-1', true, Carbon::now()->addHours(2))))->toBe(120)
        ->and($minutes((new InvitationMail('Acme', 'Dana', 'https://id.example/i'))->usableUntil(Carbon::now()->addMinutes(30))))->toBe(30)
        // Longer-lived than the window: the window wins.
        ->and($minutes(new EmailVerificationMail('https://id.example/verify/abc')))->toBe(360)
        ->and($minutes((new OrganizationInviteMail('Acme', 'Dana', 'https://id.example/o'))->usableUntil(Carbon::now()->addDays(7))))->toBe(360)
        ->and($minutes(new AdminAssignedPasswordMail('secret-password-1', false)))->toBe(360)
        ->and($minutes(new CertificateExpiringMail('Acme', 'Okta', Carbon::now()->subDay(), -1)))->toBe(360);

    Carbon::setTestNow();
});

it('keeps each mailed token lifetime equal to the framework\'s', function (string $service, int $mailMinutes): void {
    expect((new ReflectionClassConstant($service, 'TTL_MINUTES'))->getValue())->toBe($mailMinutes);
})->with([
    'magic link' => [MagicLinkService::class, MagicLinkMail::LIFETIME_MINUTES],
    'password reset' => [PasswordResetService::class, PasswordResetMail::LIFETIME_MINUTES],
    'email verification' => [EmailVerificationService::class, EmailVerificationMail::LIFETIME_MINUTES],
]);

it('retries an emailed one-time code only until the code expires', function (): void {
    Carbon::setTestNow('2026-11-13 12:00:00');
    Queue::fake();

    $expires = new DateTimeImmutable('2026-11-13 12:07:30');
    app(BrandedEmailOtpChannel::class)->deliver(new OtpDelivery('challenge-1', 'code@acme.test', '123456', 'login_step_up', 'email', $expires, 450));

    $job = Queue::pushed(SendQueuedMailable::class)->first();

    expect($job)->toBeInstanceOf(SendQueuedMailable::class)
        ->and(Carbon::instance($job->retryUntil())->equalTo($expires))->toBeTrue()
        // Laravel ignores the worker's --tries once a job has a retryUntil.
        ->and($job->tries)->toBeNull();

    Carbon::setTestNow();
});

it('queues a mail the send site sends, so an outage delays it instead of failing the request', function (): void {
    Mail::fake();

    Mail::to('someone@acme.test')->send(new CertificateExpiringMail('Acme', 'Okta', Carbon::now()->addDays(14), 14));

    Mail::assertQueued(CertificateExpiringMail::class);
    Mail::assertNothingSent();
});
