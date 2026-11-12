<?php

declare(strict_types=1);

namespace App\Mail;

use DateTimeInterface;
use Illuminate\Support\Carbon;

final class PasswordResetMail extends BrandedMailable
{
    /**
     * A password reset link works for an hour (the framework's `PasswordResetService::TTL_MINUTES`, which is private;
     * a test holds the two together), so a Relay outage is retried no longer than that.
     */
    public const int LIFETIME_MINUTES = 60;

    public function __construct(public string $url) {}

    protected function lifetime(): DateTimeInterface
    {
        return $this->usableUntil ?? Carbon::now()->addMinutes(self::LIFETIME_MINUTES);
    }

    protected function subjectLine(): string
    {
        $subject = __('mail.password_reset.subject', ['brand' => $this->brand()->name]);

        return is_string($subject) ? $subject : '';
    }

    protected function views(): array
    {
        return ['mail.password-reset', 'mail.text.password-reset'];
    }
}
