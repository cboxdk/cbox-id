<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Invites a teammate to help ADMINISTER a customer account — the organization that owns
 * identity providers on this platform.
 *
 * Not {@see InvitationMail}, which invites somebody to join an organization as one of its
 * people. The subject line says which, so an invitee can tell the two apart before opening
 * either.
 */
final class OrganizationInviteMail extends BrandedMailable
{
    public function __construct(
        public string $organization,
        /** A NAME. It was once handed the inviter's subject id, which put a ULID in the mail. */
        public string $inviter,
        public string $url,
        public ?string $role = null,
    ) {}

    protected function subjectLine(): string
    {
        $subject = __('mail.organization_invite.subject', [
            'inviter' => $this->inviter,
            'organization' => $this->organization,
            'brand' => $this->brand()->name,
        ]);

        return is_string($subject) ? $subject : '';
    }

    protected function views(): array
    {
        return ['mail.organization-invite', 'mail.text.organization-invite'];
    }

    protected function data(): array
    {
        return ['roleName' => MailText::role($this->role)];
    }
}
