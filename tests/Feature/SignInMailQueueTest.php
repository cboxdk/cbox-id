<?php

declare(strict_types=1);

use App\Mail\EmailVerificationMail;
use App\Mail\MagicLinkMail;
use App\Mail\OneTimeCodeMail;
use App\Mail\PasswordResetMail;
use App\Platform\Otp\BrandedEmailOtpChannel;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Otp\ValueObjects\OtpDelivery;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/*
 * A sign-in mail never makes the person wait on the mail provider: it is queued, and an
 * outage is retried by the queue rather than failing the request.
 */

beforeEach(function (): void {
    Mail::fake();
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
});

it('queues the magic link instead of sending it inline', function (): void {
    test()->from(route('login'))->post(route('login.magic-link'), ['email' => 'someone@acme.test'])->assertRedirect(route('login'));

    Mail::assertQueued(MagicLinkMail::class, fn (MagicLinkMail $mail): bool => $mail->hasTo('someone@acme.test'));
    Mail::assertNothingSent();
});

it('queues the password reset link instead of sending it inline', function (): void {
    app(Subjects::class)->create('dana@acme.test', 'Dana', 'old-password-1234');

    test()->from(route('password.request'))->post(route('password.email'), ['email' => 'dana@acme.test'])->assertRedirect(route('password.request'));

    Mail::assertQueued(PasswordResetMail::class, fn (PasswordResetMail $mail): bool => $mail->hasTo('dana@acme.test'));
    Mail::assertNothingSent();
});

it('queues the signup confirmation instead of sending it inline', function (): void {
    test()->from(route('signup'))->post(route('signup.register'), ['organization' => 'Acme Inc.', 'name' => 'Dana', 'email' => 'newbie@acme.test', 'password' => 'supersecret1234'])
        ->assertRedirect(route('dashboard'));

    Mail::assertQueued(EmailVerificationMail::class, fn (EmailVerificationMail $mail): bool => $mail->hasTo('newbie@acme.test'));
    Mail::assertNothingSent();
});

it('queues an emailed one-time code instead of sending it inline', function (): void {
    app(BrandedEmailOtpChannel::class)->deliver(new OtpDelivery('challenge-1', 'code@acme.test', '123456', 'login', 'email', new DateTimeImmutable('+10 minutes'), 600));

    Mail::assertQueued(OneTimeCodeMail::class, fn (OneTimeCodeMail $mail): bool => $mail->hasTo('code@acme.test'));
    Mail::assertNothingSent();
});

it('encrypts a queued sign-in mail and sends it later inside the environment it was asked in', function (): void {
    // A worker has no environment: without carrying it, a sandbox's mail would go out.
    $sandbox = Environment::query()->create(['name' => 'Sandbox', 'slug' => 'sbx-queue', 'type' => 'sandbox', 'status' => 'active']);
    $context = app(EnvironmentContext::class);
    $context->set($sandbox);
    Queue::fake();
    Event::fake([MessageSent::class]);
    app()->forgetInstance('mail.manager');
    app()->forgetInstance('mailer');
    Mail::swap(app('mail.manager'));

    Mail::to('someone@acme.test')->queue(new MagicLinkMail('https://id.example/magic/abc'));

    $job = Queue::pushed(SendQueuedMailable::class)->first();
    expect($job)->toBeInstanceOf(SendQueuedMailable::class)
        ->and($job->shouldBeEncrypted)->toBeTrue()
        ->and($job->afterCommit)->toBeTrue()
        ->and($job->tries)->toBe(5);

    $context->set(null);
    $job->handle(app(MailFactory::class));

    Event::assertNotDispatched(MessageSent::class);
    expect($context->current())->toBeNull();

    // The same mail asked in a production environment does go out from the worker.
    $production = Environment::query()->create(['name' => 'Prod', 'slug' => 'prod-queue', 'type' => 'production', 'status' => 'active']);
    $context->set($production);
    Mail::to('someone@acme.test')->queue(new MagicLinkMail('https://id.example/magic/def'));
    $context->set(null);
    Queue::pushed(SendQueuedMailable::class)->last()->handle(app(MailFactory::class));

    Event::assertDispatched(MessageSent::class);
});
