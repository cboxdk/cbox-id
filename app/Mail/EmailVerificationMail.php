<?php

declare(strict_types=1);

namespace App\Mail;

final class EmailVerificationMail extends BrandedMailable
{
    public function __construct(public string $url) {}

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
