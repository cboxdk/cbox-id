<?php

declare(strict_types=1);

namespace App\Platform\SupportAccess\ValueObjects;

/**
 * "Sign in to this app as this person, in this organization, for this reason, for this
 * long" — as an environment administrator asks it from the console.
 */
readonly class SupportSignInRequest
{
    public function __construct(
        /** The administrator: a subject of the platform root, never of this environment. */
        public string $actorId,
        public string $targetUserId,
        public string $organizationId,
        public string $clientId,
        /** Shown to the organization on its activity log and in its webhook. */
        public string $reason,
        public int $minutes,
    ) {}
}
