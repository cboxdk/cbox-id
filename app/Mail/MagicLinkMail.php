<?php

declare(strict_types=1);

namespace App\Mail;

use DateTimeInterface;
use Illuminate\Support\Carbon;

final class MagicLinkMail extends BrandedMailable
{
    /**
     * A sign-in link works for 15 minutes (the framework's `MagicLinkService::TTL_MINUTES`, which is private;
     * a test holds the two together), so a Relay outage is retried no longer than that.
     */
    public const int LIFETIME_MINUTES = 15;

    public function __construct(public string $url) {}

    protected function lifetime(): DateTimeInterface
    {
        return $this->usableUntil ?? Carbon::now()->addMinutes(self::LIFETIME_MINUTES);
    }

    protected function subjectLine(): string
    {
        $subject = __('mail.magic_link.subject', ['brand' => $this->brand()->name]);

        return is_string($subject) ? $subject : '';
    }

    protected function views(): array
    {
        return ['mail.magic-link', 'mail.text.magic-link'];
    }
}
