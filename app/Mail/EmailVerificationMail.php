<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class EmailVerificationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public string $url) {}

    public function envelope(): Envelope
    {
        $brand = config('cbox-id.branding.name', 'Cbox ID');

        return new Envelope(subject: 'Confirm your '.(is_string($brand) ? $brand : 'Cbox ID').' email address');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.email-verification');
    }
}
