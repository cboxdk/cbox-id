<?php

declare(strict_types=1);

namespace App\Mail;

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
        public ?string $expiresAt = null,
    ) {}

    public function envelope(): Envelope
    {
        $brand = config('cbox-id.branding.name', 'Cbox ID');

        return new Envelope(subject: 'Your '.(is_string($brand) ? $brand : 'Cbox ID').' password has been reset');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.admin-assigned-password');
    }
}
