<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\PageController;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\SendPasswordResetRequest;
use App\Mail\PasswordResetMail;
use App\Platform\MailLinks;
use Cbox\Id\Identity\Contracts\PasswordReset;
use Cbox\Id\Identity\Exceptions\InvalidPasswordReset;
use Cbox\Id\Identity\Exceptions\PolicyViolation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Response;

/**
 * FORGETTING A PASSWORD, AND SETTING A NEW ONE.
 *
 * The whole design of this pair is about not answering a question nobody is entitled to
 * ask: whether an address has an account here. The confirmation is identical either way,
 * the throttle is keyed on the address so it cannot be used to probe, and nothing about
 * the timing or the wording differs between a hit and a miss.
 */
final readonly class PasswordResetController extends PageController
{
    public function request(): Response
    {
        return $this->page('auth/forgot-password', 'Reset password');
    }

    public function send(
        SendPasswordResetRequest $request,
        PasswordReset $resets,
        MailLinks $links,
    ): RedirectResponse {
        /*
         * Throttled on the address AND the caller, so the endpoint cannot be used to
         * spray reset mail at somebody, or to probe which addresses are registered by
         * watching which ones start rate-limiting.
         */
        $key = 'pwreset:'.sha1(mb_strtolower($request->email()).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, 3)) {
            return back()->withInput()->withErrors([
                'email' => 'Too many attempts. Please wait a few minutes and try again.',
            ]);
        }

        RateLimiter::hit($key, 900);

        // Null for an unknown address. The SAME confirmation is shown either way, so the
        // page never reveals whether an account exists.
        $token = $resets->request($request->email());

        $devUrl = null;

        if ($token !== null) {
            $url = $links->route('password.reset', $token);
            Mail::to($request->email())->send(new PasswordResetMail($url));

            // Surfaced only on a local install, so a developer with no mail transport can
            // still walk the flow. Never in any other environment: it is a live
            // credential in a page body.
            $devUrl = app()->environment('local') ? $url : null;
        }

        return back()
            ->with('sentTo', $request->email())
            ->with('devResetUrl', $devUrl);
    }

    public function edit(string $token): Response
    {
        return $this->page('auth/reset-password', 'Choose a new password', [
            'token' => $token,
        ]);
    }

    public function update(ResetPasswordRequest $request, PasswordReset $resets): RedirectResponse
    {
        try {
            $resets->reset($request->token(), $request->password());
        } catch (InvalidPasswordReset) {
            return back()->withErrors([
                'password' => 'This reset link is invalid or has expired. Request a new one.',
            ]);
        } catch (PolicyViolation $violation) {
            /*
             * The tenant's own policy, refusing at the last moment.
             *
             * The rule on the request cannot check password REUSE, because the subject is
             * identified by the token and this form deliberately never resolves it —
             * doing so would make the page an account-existence oracle. The reset itself
             * can, and does. Turned into a field error rather than a 500, because
             * "you have used this password before" is something the person can act on.
             */
            return back()->withErrors(['password' => $violation->getMessage()]);
        }

        return to_route('login')
            ->with('status', 'Your password has been reset — sign in with your new password.');
    }
}
