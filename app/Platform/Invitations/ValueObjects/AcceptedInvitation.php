<?php

declare(strict_types=1);

namespace App\Platform\Invitations\ValueObjects;

use Cbox\Id\Organization\Models\Membership;

/**
 * The outcome of accepting: the membership that now exists, the subject it belongs to, and
 * where to send them — back to the app when the invitation named a return address that is
 * still valid, otherwise nowhere in particular.
 */
final readonly class AcceptedInvitation
{
    /**
     * @param  list<string>  $withheldRoleIds  parked access roles that could not be granted
     */
    public function __construct(
        public Membership $membership,
        public string $subjectId,
        public ?ReturnTarget $returnTarget = null,
        public array $withheldRoleIds = [],
    ) {}

    public function organizationId(): string
    {
        return $this->membership->organization_id;
    }
}
