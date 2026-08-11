<?php

declare(strict_types=1);

namespace App\Platform\FrontendApi;

use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Illuminate\Http\Request;

/**
 * Turning a redeemed login ticket into an ordinary session.
 *
 * The last step of an embedded sign-in, and deliberately the least interesting one: by the
 * time this runs the password has already been checked by the same code the hosted form
 * uses, with the same lockout, the same breach check and the same audit entries. All that
 * is left is to establish the session `/authorize` will find — after which the flow is
 * indistinguishable from somebody who typed their password on the hosted page.
 *
 * THE `amr` TRAVELS WITH THE TICKET. Re-deriving it here would mean guessing what the
 * check established, and a guess that says "pwd" for a passkey sign-in produces a session
 * whose `acr` understates it — the same class of untruth the SAML assertion carried until
 * this week. It is carried, not inferred.
 *
 * WHAT MAKES A STOLEN TICKET USELESS is in {@see LoginTickets::redeem()} rather than here:
 * single use enforced by a conditional update, sixty seconds, and a bind to the
 * environment. This class assumes redemption already said yes, and does nothing that
 * would matter if it had not — it establishes a session for a subject id that a redeemed
 * ticket names, and a ticket that did not redeem never reaches it.
 */
final class SignInWithTicket
{
    public function __construct(
        private readonly LoginTickets $tickets,
        private readonly Subjects $subjects,
        private readonly SessionManager $sessions,
        private readonly EnvironmentContext $environments,
    ) {}

    /**
     * Redeem the ticket and sign the browser in, or do nothing.
     *
     * Returns quietly on every failure. The caller's next line asks whether anybody is
     * signed in, and a ticket that was expired, replayed, or minted for another
     * environment must produce exactly the answer an absent ticket does — anything louder
     * tells a caller which of those it was.
     */
    public function establish(Request $request, string $token): bool
    {
        $ticket = $this->tickets->redeem($token);

        if ($ticket === null) {
            return false;
        }

        // A ticket carries the environment it was minted in, and this endpoint resolves
        // its own from the host. A mismatch means the ticket is being replayed somewhere
        // it was not issued for, which redemption cannot see because it reads under the
        // scope it is given.
        if ($ticket->environment_id !== $this->environments->current()?->environmentKey()) {
            return false;
        }

        $subject = $this->subjects->find($ticket->subject_id);

        if ($subject === null) {
            // Deleted between minting and redeeming — sixty seconds is short, and this is
            // still the honest answer rather than a session for somebody who is gone.
            return false;
        }

        /** @var list<string> $amr */
        $amr = array_values(array_filter($ticket->amr, is_string(...)));

        $session = $this->sessions->start($subject->id, null, $amr);

        $request->session()->put(PlatformAuth::SESSION_KEY, $session->id);

        return true;
    }
}
