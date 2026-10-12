<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Invites somebody to JOIN an organization — to sign in to its apps as one of its people.
 *
 * Not {@see OrganizationInviteMail}, which asks somebody to help ADMINISTER a customer
 * account on this platform. The two used to go out under the same subject line, so an
 * invitee could not tell from their inbox whether they were being given an app login or
 * the keys to somebody's identity platform. They say different things now, from the
 * subject down.
 */
final class InvitationMail extends BrandedMailable
{
    public function __construct(
        public string $organization,
        public string $inviter,
        public string $url,
        /** The role they will hold, as a label — "Member", "Admin". */
        public ?string $role = null,
        /** The app that sent the invitation, when one did. */
        public ?string $app = null,
    ) {}

    protected function subjectLine(): string
    {
        $subject = __('mail.invitation.subject', [
            'inviter' => $this->inviter,
            'organization' => $this->organization,
        ]);

        return is_string($subject) ? $subject : '';
    }

    protected function views(): array
    {
        return ['mail.invitation', 'mail.text.invitation'];
    }

    protected function data(): array
    {
        return ['roleName' => MailText::role($this->role)];
    }
}
