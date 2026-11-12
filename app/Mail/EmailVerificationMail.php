<?php

declare(strict_types=1);

namespace App\Mail;

use DateTimeInterface;
use Illuminate\Support\Carbon;

final class EmailVerificationMail extends BrandedMailable
{
    /**
     * A confirmation link works for 24 hours (the framework's `EmailVerificationService::TTL_MINUTES`, which is private;
     * a test holds the two together), so a Relay outage is retried no longer than that.
     */
    public const int LIFETIME_MINUTES = 1440;

    public function __construct(public string $url) {}

    protected function lifetime(): DateTimeInterface
    {
        return $this->usableUntil ?? Carbon::now()->addMinutes(self::LIFETIME_MINUTES);
    }

    protected function subjectLine(): string
    {
        $subject = __('mail.email_verification.subject', ['brand' => $this->brand()->name]);

        return is_string($subject) ? $subject : '';
    }

    protected function views(): array
    {
        return ['mail.email-verification', 'mail.text.email-verification'];
    }
}
