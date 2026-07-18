<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Invites a teammate to an account's workspace console (the buyer plane). Distinct
 * from {@see InvitationMail}, which invites an end-user into an organization.
 */
final class AccountInviteMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $account,
        public string $inviter,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        $brand = config('cbox-id.branding.name', 'Cbox ID');

        return new Envelope(subject: "You've been invited to the {$this->account} workspace on ".(is_string($brand) ? $brand : 'Cbox ID'));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.account-invite');
    }
}
