<?php

declare(strict_types=1);

namespace App\Platform\SupportAccess\ValueObjects;

/**
 * One app's support session, as this browser holds it.
 */
readonly class SupportHandoffEntry
{
    public function __construct(
        public string $clientId,
        public string $sessionId,
        public string $actorId,
        /** The administrator's app-host session the console started this from. */
        public ?string $consoleSessionId = null,
    ) {}
}
