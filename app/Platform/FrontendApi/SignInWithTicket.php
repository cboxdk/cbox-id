<?php

declare(strict_types=1);

namespace App\Platform\FrontendApi;

use App\Platform\CurrentUser;
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
        private readonly PlatformAuth $auth,
        private readonly Subjects $subjects,
        private readonly EnvironmentContext $environments,
        private readonly CurrentUser $current,
        private readonly SessionManager $sessions,
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

        // `establish()`, NOT `sessions->start()`. Starting a session directly produced one
        // that was missing four things the hosted path gives every other sign-in, and the
        // first two are security properties:
        //
        //  - the sudo confirmation and any half-finished second-factor handles survived
        //    into the new session. Minting a session is exactly the moment a prior step-up
        //    should stop being established, and `establish()` forgets them by name.
        //  - the session id was never rotated, so a fixed id set before the sign-in
        //    carried through it.
        //  - no organization was pinned, so the session landed with none — which the
        //    console, the entitlement reads and the org scope all resolve from.
        //  - no IP or user-agent was recorded, so adaptive risk had no history to compare
        //    the next login against.
        //
        // The same lesson as everywhere else in this channel: call the app's own code
        // rather than a subset of it that will fall behind.
        $this->auth->establish($request, $subject->id, $amr);

        // Read through the session helper rather than off the request: a caller may hand
        // us a bare Request with no store bound, and this is the LAST step of a sign-in
        // that has already succeeded — failing it over a lookup would be the tail wagging
        // the dog.
        $sessionId = session()->get(PlatformAuth::SESSION_KEY);
        $session = is_string($sessionId) ? $this->sessions->active($sessionId) : null;

        // AND THE REQUEST'S OWN VIEW OF WHO IS SIGNED IN, which is not the same thing.
        //
        // `CurrentUser` is resolved once, by the auth middleware, at the START of a
        // request. Every hosted door redirects immediately after establishing, so the next
        // request re-resolves it and nobody noticed — but this one establishes a session
        // and then CONTINUES: `/oauth/authorize` redeems in `mount()` and asks `check()`
        // three lines later.
        //
        // For the caller this whole channel exists for — a page on another origin, with no
        // session cookie at all — that answer was false. The ticket was spent, a perfectly
        // good session was created, and the person was redirected to the hosted sign-in
        // page they had embedded a form to avoid. It passed in tests only because the test
        // client carried the sign-in POST's session in the same cookie jar, which no
        // cross-origin browser does.
        //
        // Set HERE rather than inside `establish()`: this is the one door that carries on
        // after establishing, and refreshing the resolved identity underneath every other
        // caller — impersonation, org switching, the operator handoff — changes what their
        // own later reads see, which is a much larger claim than this bug needs.
        if ($session !== null) {
            $this->current->set($subject, $session, null, null);
        }

        return true;
    }
}
