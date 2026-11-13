<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Props\Auth\LinkConfirmationProps;
use App\Platform\Enums\RefusedFactor;
use App\Platform\IntendedUrl;
use App\Platform\OAuth\AppSignIns;
use App\Platform\PlatformAuth;
use App\Platform\Radar\Enums\RadarMethod;
use App\Platform\RiskGuard;
use App\Platform\SignInHolds;
use App\Platform\SsoRefusal;
use App\SignalsData\IdentityCapture;
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
    public function show(Request $request, string $token): Response
    {
        // The app the link was asked for from, as the link names it: carried to the
        // button and looked up only when the link is redeemed.
        $forApp = $request->query(AppSignIns::PARAMETER);
        $carried = is_string($forApp) && $forApp !== '' ? [AppSignIns::PARAMETER => $forApp] : [];

        return $this->page('auth/confirm-sign-in', __('auth.confirm_sign_in.title'), [
            'confirmation' => new LinkConfirmationProps(
                heading: __('auth.confirm_sign_in.heading'),
                lead: __('auth.confirm_sign_in.lead'),
                actionLabel: __('auth.confirm_sign_in.action'),
                actionUrl: route('magic.redeem.store', ['token' => $token, ...$carried]),
                note: __('auth.confirm_sign_in.note'),
            ),
        ]);
    }

    public function redeem(Request $request, string $token, MagicLink $magicLink, PlatformAuth $auth, RiskGuard $risk, SessionManager $sessions): RedirectResponse
    {
        app(IdentityCapture::class)->begin($request);
        // Risk rejection precedes consumption. A magic link proves mailbox access;
        // it does not satisfy an enrolled second factor.
        if ($risk->shouldBlock($risk->assess($request, 'login', method: RadarMethod::MagicLink))) {
            app(IdentityCapture::class)->failed($request, reason: 'policy');

            return redirect()->route('login')->with('error', __('auth.common.could_not_process'));
        }

        try {
            $session = $magicLink->redeem($token);
        } catch (InvalidMagicLink) {
            app(IdentityCapture::class)->failed($request, reason: 'invalid_credentials');

            return redirect()->route('login')->with('error', __('auth.confirm_sign_in.invalid'));
        }

        /*
         * OPENED IN ANOTHER BROWSER than the one that asked for it, the link arrives in a
         * session with no request waiting. It names the app it was asked for from, so the
         * sign-in goes on to that app (through the second factor too, which reads the same
         * intent) instead of to a console the person was never headed for.
         */
        if (! $request->session()->has(IntendedUrl::KEY)) {
            $continue = app(AppSignIns::class)->continueTo($request, $request->query(AppSignIns::PARAMETER));

            if ($continue !== null) {
                $request->session()->put(IntendedUrl::KEY, $continue);
            }
        }

        // The redemption already STARTED a framework session — the link is spent and the
        // row exists — so a refusal has to end it, not merely decline to hand it to the
        // browser. Left alive it is an unadopted session nobody holds, and the subject's
        // own device list would show a sign-in they were told did not happen.
        if (! $auth->localSignInAllowedFor($session->user_id)) {
            app(IdentityCapture::class)->failed($request, $session->user_id, 'policy');
            $sessions->revoke($session->id);
            SsoRefusal::hold($session->user_id, RefusedFactor::MagicLink);

            return redirect()->route('login');
        }

        // The link proved the mailbox. An account whose address was never confirmed has
        // already become this person's alone inside redeem() (the framework's MailboxProof,
        // see PlatformMailboxProof) — before the session started and before the
        // second-factor check below, so a factor somebody else enrolled on it cannot stand
        // between them and their own address.

        // The SAME question every door asks ({@see SignInHolds::mustVerify()}). It used to
        // skip the challenge whenever the mandate also wanted a stronger factor, which is
        // true of an owner or admin whose only factor is SMS: the link (the mailbox alone)
        // then adopted a session past the phone they had enrolled.
        if (app(SignInHolds::class)->mustVerify($session->user_id, array_values($session->amr))) {
            return redirect()->to($auth->challengeSession($request, $session));
        }

        $auth->adopt($request, $session);

        // Back to where they were going when the link was opened in the browser that asked
        // for it — a device approval, an app's sign-in. A link opened elsewhere has no
        // intent in its session and lands on the dashboard, as before.
        return redirect()->to(IntendedUrl::pullForSubject() ?? route('dashboard'));
    }
}
