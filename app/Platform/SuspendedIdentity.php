<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Console\ConsoleScope;

/**
 * What the browser was signed in as, put aside for the duration of an impersonation —
 * and nothing else.
 *
 * A type rather than a bare string because it used to hold three values, and the reason it
 * no longer does is the point. There were two places an acting principal's identity could
 * live — an ordinary subject session and an account-member one, with its own security
 * stamp — so a suspension had to put aside both, and putting aside only one LOOKED
 * correct: {@see ConsoleScope} happened to prefer the subject session, which during an
 * impersonation is the victim's. Safety by precedence in someone else's method is not
 * safety. There is one session now, so there is one thing to suspend, and the way to get
 * this wrong has been removed rather than guarded.
 *
 * The environment BINDING is not here. It is not an identity — it is which environment an
 * admin session is anchored to — and it travels on the marker beside the plane an operator
 * had the console aimed at, for the same reason: both are selections, restored as
 * preferences rather than as authorizations.
 *
 * Nothing here is an authorization either. The value is a hint about what to put back, and
 * it is re-bound to the freshly-resolved acting principal before it is restored.
 */
final readonly class SuspendedIdentity
{
    public function __construct(
        public ?string $subjectSessionId = null,
    ) {}

    /**
     * Re-narrow the flat session shape. Anything malformed reads as absent, which
     * restores nothing — the acting person comes back to the sign-in door rather than to
     * a session assembled out of values that did not survive a round trip.
     *
     * Keys written by an older build are ignored rather than rejected: an impersonation
     * in flight across the deploy restores the one identity it still has, which is the
     * only one that ever mattered.
     */
    public static function fromSession(mixed $data): self
    {
        if (! is_array($data)) {
            return new self;
        }

        $session = $data['subject_session'] ?? null;

        return new self(
            subjectSessionId: is_string($session) && $session !== '' ? $session : null,
        );
    }

    /**
     * @return array{subject_session: string|null}
     */
    public function toSession(): array
    {
        return ['subject_session' => $this->subjectSessionId];
    }
}
