<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\PlatformAuth;
use App\Platform\SignupProvisioner;
use Cbox\Id\Identity\Contracts\EmailVerification;
use Cbox\Id\Identity\Exceptions\InvalidEmailVerification;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Illuminate\Http\RedirectResponse;

final class EmailVerificationController extends Controller
{
    public function verify(
        string $token,
        EmailVerification $verification,
        AccountMembers $members,
        SignupProvisioner $provisioner,
    ): RedirectResponse {
        try {
            $subjectId = $verification->verify($token);
        } catch (InvalidEmailVerification) {
            return redirect()->route('login')->with('error', 'That verification link is invalid or has expired.');
        }

        // An ACCOUNT owner verifying their address is the moment their first
        // environment is finally stood up — self-serve signup deliberately defers it
        // until here, so an unverified (bot) signup never provisions an IdP. Idempotent:
        // a replayed link finds the environment already there and does nothing.
        $member = $members->findBySubject($subjectId);

        if ($member !== null) {
            $provisioner->releaseEnvironment($member);

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
