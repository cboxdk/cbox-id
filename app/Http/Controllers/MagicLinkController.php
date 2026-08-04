<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\AccountAuth;
use App\Platform\Enums\AttemptOutcome;
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

/**
 * Redeeming an emailed sign-in link, on either plane.
 *
 * A magic link is a bearer token delivered to an inbox: a password-equivalent factor with
 * WEAKER binding than a password, since it survives a forwarded mail and needs nothing the
 * person knows. An organization that mandates SSO has said email possession is not what
 * decides who gets in — so both doors refuse it, and refuse it after the token is spent
 * rather than before, so redeeming can never answer "does this address exist here".
 */
final class MagicLinkController extends Controller
{
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

    /**
     * Redeem a magic link on the ACCOUNT plane — the workspace door.
     *
     * Account members are ordinary subjects in the platform-root environment, which is
     * the only reason this works at all: the same single-use token machinery serves both
     * doors. What the account plane adds is the membership check — a redeemed link for a
     * subject that carries no account membership signs nobody in here, so a link issued
     * on the tenant plane can never be walked into the account console. A member holding
     * a second factor is still held at the challenge.
     *
     * `adoptLocal`, because that is what this is: the same landing the account's own SSO
     * assertion uses, entered from the side the mandate closes.
     */
    public function redeemForWorkspace(Request $request, string $token, MagicLink $magicLink, AccountAuth $auth, RiskGuard $risk, SessionManager $sessions): RedirectResponse
    {
        if ($risk->shouldBlock($risk->assess($request, 'login'))) {
            return redirect()->route('workspace.login')->with('error', 'We could not process this request. Please try again later.');
        }

        try {
            $session = $magicLink->redeem($token);
        } catch (InvalidMagicLink) {
            return redirect()->route('workspace.login')->with('error', 'That sign-in link is invalid or has expired.');
        }

        return match ($auth->adoptLocal($session)) {
            AttemptOutcome::Ok => redirect()->intended(route('workspace.home')),
            AttemptOutcome::Mfa => redirect()->route('workspace.login.mfa'),
            // Not "invalid or expired" — the link was neither, and saying so to somebody
            // whose organization has simply moved the door is the dead end this replaces.
            AttemptOutcome::SsoRequired => $this->refuseWorkspace($sessions, $session),
            default => redirect()->route('workspace.login')->with('error', 'That sign-in link is invalid or has expired.'),
        };
    }

    /**
     * End the session the redemption started, and send the member somewhere that can
     * explain why. Same two moves as the tenant door above, and for the same reasons.
     */
    private function refuseWorkspace(SessionManager $sessions, Session $session): RedirectResponse
    {
        $sessions->revoke($session->id);
        SsoRefusal::hold($session->user_id, RefusedFactor::MagicLink);

        return redirect()->route('workspace.login');
    }
}
