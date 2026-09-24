<?php

declare(strict_types=1);

namespace App\Platform\SupportAccess\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * A support session that is open right now, with the names a console row needs.
 */
readonly class ActiveSupportSession
{
    public function __construct(
        public string $id,
        public string $targetUserId,
        public ?string $targetLabel,
        public string $organizationId,
        public ?string $organizationName,
        public string $clientId,
        public ?string $appName,
        /** The app's entry point, to go back into it while the session is open. */
        public ?string $launchUrl,
        public string $reason,
        /** Who started it, as a name or an address, or null when it no longer resolves. */
        public ?string $actorLabel,
        public CarbonImmutable $expiresAt,
    ) {}
}
