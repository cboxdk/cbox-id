<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Identity\Contracts\MailboxProof;
use Cbox\Id\Identity\ValueObjects\Subject;
use RuntimeException;

/**
 * THE MAILBOX OWNER TAKES OVER AN ACCOUNT NOBODY HAD PROVEN.
 *
 * Self-service signup makes a usable account for whatever address is typed, and the
 * address stays unconfirmed until somebody opens the link. Until then the account belongs
 * to whoever chose its password — which need not be the person who reads that inbox.
 *
 * Some doors are opened by a link only the mailbox receives: an emailed invitation is
 * one. Whoever walks through such a door has PROVEN the address, and an account matched
 * by that address must be handed to them, not to whoever registered it first. So,
 * before anything is attached to the account, everything set on it before the proof is
 * withdrawn and the address is marked confirmed, all in one step. The withdrawal itself is
 * the framework's {@see MailboxProof} (bound to {@see PlatformMailboxProof}).
 *
 * The rule is "an unconfirmed account never receives a membership": a door that cannot
 * prove the mailbox (an administrator typing an address) refuses instead of calling this,
 * and this refuses rather than hand back an account the proof did not confirm.
 *
 * Nothing happens to an account whose address was already confirmed: that one has an
 * owner, and the proof just given is theirs.
 */
final readonly class UnverifiedAccountClaim
{
    public function __construct(private MailboxProof $proof) {}

    /**
     * Hand the account matched by `$provenEmail` to whoever proved that mailbox.
     *
     * @return Subject the account as it now stands (confirmed)
     *
     * @throws RuntimeException the account is not under the proven address (it changed
     *                          since it was matched), so nothing may be attached to it
     */
    public function claim(Subject $subject, string $provenEmail): Subject
    {
        if ($subject->emailVerified) {
            return $subject;
        }

        $claimed = $this->proof->claim($subject, $provenEmail);

        if (! $claimed->emailVerified) {
            throw new RuntimeException('The account is no longer under the proven address.');
        }

        return $claimed;
    }
}
