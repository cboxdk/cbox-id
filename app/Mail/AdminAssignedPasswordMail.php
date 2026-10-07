<?php

declare(strict_types=1);

namespace App\Mail;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers a password an administrator set for a user directly to that user.
 *
 * The alternative delivery is a one-time reveal in the console, which the admin passes
 * on out-of-band — the console offers both because the right channel depends on how the
 * admin can already reach the person.
 */
final class AdminAssignedPasswordMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $password,
        public bool $temporary,
        /**
         * A MOMENT, not a sentence: it is written out in the reader's language when the
         * mail is rendered. Formatted by the caller it was always English — "Wed, Oct 7,
         * 2026 3:00 PM" in the middle of a Danish paragraph.
         */
        public ?CarbonInterface $expiresAt = null,
    ) {}

    public function envelope(): Envelope
    {
        // Built inside the send's `withLocale()`, so `__()` here is already in the
        // recipient's language; the send site states it (see App\Platform\Locale\MailLocale).
        return new Envelope(subject: __('mail.admin_assigned_password.subject', ['brand' => MailText::brand()]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.admin-assigned-password', with: [
            'brand' => MailText::brand(),
            // `llll` is Carbon's localised "Wed, Oct 7, 2026 3:00 PM" — in English exactly
            // what `toDayDateTimeString()` produced before, in Danish "ons. 7. okt. 2026 15:00".
            'expiresOn' => $this->expiresAt?->copy()->locale(app()->getLocale())->isoFormat('llll'),
        ]);
    }
}
