<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class MagicLinkMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public string $url) {}

    public function envelope(): Envelope
    {
        // Built inside the send's `withLocale()`, so `__()` here is already in the
        // recipient's language; the send site states it (see App\Platform\Locale\MailLocale).
        return new Envelope(subject: __('mail.magic_link.subject', ['brand' => MailText::brand()]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.magic-link', with: ['brand' => MailText::brand()]);
    }
}
