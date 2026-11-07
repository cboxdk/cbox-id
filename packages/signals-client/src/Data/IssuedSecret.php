<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

/** A one-time plaintext read secret and the application's handle for it. */
final readonly class IssuedSecret
{
    public function __construct(
        /** Plaintext; transported once to Signals and never stored or logged by this package. */
        public string $plaintext,
        /** The application's own identifier for the key (for later retirement). */
        public string $reference,
    ) {}
}
