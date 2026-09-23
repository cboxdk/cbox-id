<?php

declare(strict_types=1);

namespace App\Platform\Invitations\ValueObjects;

/**
 * Who sent an invitation: the subject id the audit trail is keyed on, and the name the
 * invitee reads in the mail and on the confirmation page.
 *
 * Two fields because they come from different places. An environment administrator is a
 * subject of the platform root, and the invitee reads the page on the tenant's host — so
 * the name is resolved once, by the caller that knows where its actor lives, and carried.
 */
final readonly class Inviter
{
    public function __construct(
        public ?string $subjectId,
        public string $name,
    ) {}
}
