<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Props\Auth\LinkConfirmationProps;
use App\Platform\PlatformAuth;
use App\Platform\SignupProvisioner;
use Cbox\Id\Identity\Contracts\EmailVerification;
use Cbox\Id\Identity\Exceptions\InvalidEmailVerification;
use Cbox\Id\Organization\Contracts\Memberships;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * Confirming an address from the link mailed to it.
 *
 * TWO REQUESTS, and the first one spends nothing. Confirming used to happen on the GET, so
 * the mail scanner that fetched the link before anybody read the message was the one that
 * confirmed it — and the person who then clicked was told the link was invalid, about an
 * address that was in fact now verified. The GET renders a button; the POST verifies.
 */
final readonly class EmailVerificationController extends PageController
{
    public function show(string $token): Response
    {
        return $this->page('auth/confirm-email', 'Confirm your email', [
            'confirmation' => new LinkConfirmationProps(
                heading: 'Confirm your email address',
                lead: 'You opened the confirmation link we sent. Confirm to finish verifying this address.',
                actionLabel: 'Confirm email address',
                actionUrl: route('verification.verify.store', $token),
            ),
        ]);
    }

    public function verify(
        string $token,
        EmailVerification $verification,
        Memberships $members,
        SignupProvisioner $provisioner,
    ): RedirectResponse {
        try {
            $subjectId = $verification->verify($token);
        } catch (InvalidEmailVerification) {
            return redirect()->route('login')->with('error', 'That verification link is invalid or has expired.');
        }

        // An owner verifying their address is the moment their first environment is
        // finally stood up — self-serve signup deliberately defers it until here, so an
        // unverified (bot) signup never provisions an IdP. Idempotent: a replayed link
        // finds the environment already there and does nothing.
        $membership = $members->forUser($subjectId)->first();

        if ($membership !== null) {
            $provisioner->releaseEnvironment($membership->organization_id);

            // The ONE session, asked at the cookie rather than through CurrentUser: this
            // route is deliberately outside the auth middleware (the token is the proof,
            // clickable signed in or out), so nothing has resolved an identity here. It
            // decides where to land, not whether to admit — a wrong guess costs a
            // redundant sign-in page, never access.
            return session()->has(PlatformAuth::SESSION_KEY)
                ? redirect()->route('projects')->with('status', 'Email verified — your environment is ready.')
                : redirect()->route('login')->with('status', 'Email verified — sign in to open your environment.');
        }

        return redirect()->route('login')->with('status', 'Your email is verified — you can sign in.');
    }
}
