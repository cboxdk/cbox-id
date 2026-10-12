<?php

declare(strict_types=1);

namespace App\Mail;

final class PasswordResetMail extends BrandedMailable
{
    public function __construct(public string $url) {}

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
