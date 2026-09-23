<?php

declare(strict_types=1);

namespace App\Platform\Invitations\ValueObjects;

use Cbox\Id\Organization\Models\Invitation;

/** An invitation that was created (or re-issued) and mailed. */
final readonly class SentInvitation
{
    public function __construct(
        public Invitation $invitation,
        public ?ReturnTarget $returnTarget = null,
    ) {}
}
