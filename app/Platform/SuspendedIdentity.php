<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Console\ConsoleScope;

/**
 * Everything the browser was signed in as, put aside for the duration of an
 * impersonation — and nothing else.
 *
 * There is more than one of them, which is the whole reason this is a type rather than a
 * string on the marker. {@see ConsoleScope} resolves operator authority from EITHER an
 * ordinary subject session or an account-member one (the account console is where staff
 * sign in, and its session key holds the MEMBER id, not the subject's). A suspension that
 * put aside one and left the other would leave the acting person's authority in the
 * session they are supposedly not in — and it would LOOK correct, because the resolver
 * happens to prefer the subject session, which during an impersonation is the victim's.
 * Safety by precedence in someone else's method is not safety.
 *
 * The member's security stamp travels with the member id deliberately. It is captured, not
 * re-read on the way back: a password reset during the window bumps the member's version,
 * and the whole point of the stamp is that every session carrying the old value is dead.
 * Re-reading it live would quietly resurrect exactly the session the reset killed.
 *
 * Nothing here is an authorization. Each value is a hint about what to put back, and every
 * one is re-bound to the freshly-resolved acting principal before it is restored.
 */
final readonly class SuspendedIdentity
{
    public function __construct(
        public ?string $subjectSessionId = null,
        public ?string $accountMemberId = null,
        public ?int $accountMemberVersion = null,
    ) {}

    /**
     * Re-narrow the flat session shape. Anything malformed reads as absent, which
     * restores nothing — the acting person comes back to the sign-in door rather than to
     * a session assembled out of values that did not survive a round trip.
     */
    public static function fromSession(mixed $data): self
    {
        if (! is_array($data)) {
            return new self;
        }

        $session = $data['subject_session'] ?? null;
        $member = $data['account_member'] ?? null;
        $version = $data['account_member_version'] ?? null;

        return new self(
            subjectSessionId: is_string($session) && $session !== '' ? $session : null,
            accountMemberId: is_string($member) && $member !== '' ? $member : null,
            accountMemberVersion: is_int($version) ? $version : null,
        );
    }

    /**
     * @return array{subject_session: string|null, account_member: string|null, account_member_version: int|null}
     */
    public function toSession(): array
    {
        return [
            'subject_session' => $this->subjectSessionId,
            'account_member' => $this->accountMemberId,
            'account_member_version' => $this->accountMemberVersion,
        ];
    }
}
