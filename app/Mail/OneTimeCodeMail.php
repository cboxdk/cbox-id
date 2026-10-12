<?php

declare(strict_types=1);

namespace App\Mail;

use App\Platform\Otp\BrandedEmailOtpChannel;

/**
 * A ONE-TIME CODE, by email — for every flow that proves "you have this inbox" with a code
 * rather than a link: the step-up a risky sign-in or a challenged sign-up takes, approving
 * a TV or CLI sign-in, and linking a new sign-in method to an existing account. Sent by
 * {@see BrandedEmailOtpChannel}, which the framework's OTP service delivers through.
 *
 * WHAT IT SAYS, in this order: what the code is FOR (so a code nobody asked for reads as
 * the alarm it is), the code itself — large, monospaced, selectable in one tap, and the
 * only thing on its line — how long it lasts and that it works once, and what to do if
 * this was not you. The last is per flow, because the right advice differs: an unasked
 * sign-in code means somebody has the password; an unasked device code means somebody is
 * trying to get you to approve THEIR device, which is the whole of device-code phishing.
 *
 * THE CODE IS NOT IN THE SUBJECT, deliberately, and not in the preview line either.
 * Putting it there is common, and convenient: the code is readable from the notification
 * without opening anything. It is also readable from the notification by anybody holding
 * the phone — on a lock screen, in a shared inbox's list view, on a smartwatch, in a
 * mail-gateway log that keeps subjects and drops bodies. Every one of these codes is a
 * second factor or an ownership proof, and the person it protects against is precisely
 * one who has the password and a glance at the screen. The subject says what the code is
 * for and whose it is; the code is one tap away. A hidden preheader fixes what the inbox
 * previews to a sentence without the code, which it would otherwise lift from the body.
 */
final class OneTimeCodeMail extends BrandedMailable
{
    /** The purposes with their own wording; anything else is a plain "verification code". */
    public const array PURPOSES = ['login_step_up', 'device_step_up', 'link_identity'];

    public function __construct(
        public string $code,
        public string $purpose,
        public int $minutes,
    ) {}

    /** The catalogue section for this code's purpose — `mail.one_time_code.{kind}`. */
    public function kind(): string
    {
        return match ($this->purpose) {
            'login_step_up' => 'sign_in',
            'device_step_up' => 'device',
            'link_identity' => 'link',
            default => 'verify',
        };
    }

    protected function subjectLine(): string
    {
        $subject = __('mail.one_time_code.'.$this->kind().'.subject', ['brand' => $this->brand()->name]);

        return is_string($subject) ? $subject : '';
    }

    protected function views(): array
    {
        return ['mail.one-time-code', 'mail.text.one-time-code'];
    }

    protected function data(): array
    {
        return ['kind' => $this->kind()];
    }
}
