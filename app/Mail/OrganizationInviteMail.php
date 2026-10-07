<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Invites a teammate to help ADMINISTER a customer account — the organization that owns
 * identity providers on this platform.
 *
 * Not {@see InvitationMail}, which invites somebody to join an organization as one of its
 * people. The subject line says which, so an invitee can tell the two apart before opening
 * either.
 */
final class OrganizationInviteMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $organization,
        /** A NAME. It was once handed the inviter's subject id, which put a ULID in the mail. */
        public string $inviter,
        public string $url,
        public ?string $role = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.organization_invite.subject', [
                'inviter' => $this->inviter,
                'organization' => $this->organization,
                'brand' => MailText::brand(),
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.organization-invite', with: [
            'brand' => MailText::brand(),
            'roleName' => MailText::role($this->role),
        ]);
    }
}
