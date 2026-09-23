<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Props\Auth\LinkConfirmationProps;
use App\Platform\Enums\RefusedFactor;
use App\Platform\PlatformAuth;
use App\Platform\RiskGuard;
use App\Platform\SsoRefusal;
use Cbox\Id\Identity\Contracts\MagicLink;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Exceptions\InvalidMagicLink;
use Cbox\Id\Identity\Models\Session;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Redeeming an emailed sign-in link, on either plane.
 *
 * A magic link is a bearer token delivered to an inbox: a password-equivalent factor with
 * WEAKER binding than a password, since it survives a forwarded mail and needs nothing the
 * person knows. An organization that mandates SSO has said email possession is not what
 * decides who gets in — so both doors refuse it, and refuse it after the token is spent
 * rather than before, so redeeming can never answer "does this address exist here".
 *
 * OPENING THE LINK SPENDS NOTHING. The GET renders a one-button page and the POST redeems;
 * a mail scanner that fetched the URL first used to be the one that got the session. The
 * page asks nothing of the token either — no lookup, no "this link has expired" — so
 * opening it cannot tell a scanner or a guesser whether the token is live.
 */
final readonly class MagicLinkController extends PageController
{
    public function show(string $token): Response
    {
        return $this->page('auth/confirm-sign-in', 'Sign in', [
            'confirmation' => new LinkConfirmationProps(
                heading: 'Finish signing in',
                lead: 'You opened a sign-in link. Continue to sign in on this device.',
                actionLabel: 'Sign in',
                actionUrl: route('magic.redeem.store', $token),
                note: 'The link works once. If you did not ask to sign in, close this page — nothing happens until you press the button.',
            ),
        ]);
    }

    public function redeem(Request $request, string $token, MagicLink $magicLink, PlatformAuth $auth, RiskGuard $risk, SessionManager $sessions): RedirectResponse
    {
        // Hard-block a Reject before consuming the single-use token, so a risky
        // context can be retried from a safer one. (Magic-link is already an
        // email-possession factor, so a step-up on top would be redundant.)
        if ($risk->shouldBlock($risk->assess($request, 'login'))) {
            return redirect()->route('login')->with('error', 'We could not process this request. Please try again later.');
        }

        try {
            $session = $magicLink->redeem($token);
        } catch (InvalidMagicLink) {
            return redirect()->route('login')->with('error', 'That sign-in link is invalid or has expired.');
        }

        // The redemption already STARTED a framework session — the link is spent and the
        // row exists — so a refusal has to end it, not merely decline to hand it to the
        // browser. Left alive it is an unadopted session nobody holds, and the subject's
        // own device list would show a sign-in they were told did not happen.
        if (! $auth->localSignInAllowedFor($session->user_id)) {
            $sessions->revoke($session->id);
            SsoRefusal::hold($session->user_id, RefusedFactor::MagicLink);

            return redirect()->route('login');
        }

        $auth->adopt($request, $session);

        return redirect()->route('dashboard');
    }
}
