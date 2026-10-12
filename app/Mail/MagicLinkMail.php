<?php

declare(strict_types=1);

namespace App\Mail;

final class MagicLinkMail extends BrandedMailable
{
    public function __construct(public string $url) {}

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
