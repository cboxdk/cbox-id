<?php

declare(strict_types=1);

namespace App\Platform\SupportAccess\ValueObjects;

/**
 * An app a support session can reach, and where to send a browser to start its sign-in.
 */
readonly class SupportApp
{
    public function __construct(
        public string $clientId,
        public string $name,
        /** The app's own entry point: the origin of its first web redirect URI. */
        public string $launchUrl,
    ) {}
}
