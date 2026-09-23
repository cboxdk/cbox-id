<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Invites somebody to JOIN an organization — to sign in to its apps as one of its people.
 *
 * Not {@see OrganizationInviteMail}, which asks somebody to help ADMINISTER a customer
 * account on this platform. The two used to go out under the same subject line, so an
 * invitee could not tell from their inbox whether they were being given an app login or
 * the keys to somebody's identity platform. They say different things now, from the
 * subject down.
 */
final class InvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $organization,
        public string $inviter,
        public string $url,
        /** The role they will hold, as a label — "Member", "Admin". */
        public ?string $role = null,
        /** The app that sent the invitation, when one did. */
        public ?string $app = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->inviter.' invited you to join '.$this->organization);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.invitation');
    }
}
