<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class InvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $organization,
        public string $inviter,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You've been invited to {$this->organization} on Cbox ID");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.invitation');
    }
}
