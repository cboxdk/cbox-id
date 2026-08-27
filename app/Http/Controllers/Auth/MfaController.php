<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\PageController;
use App\Http\Requests\Auth\VerifyMfaRequest;
use App\Http\Requests\Auth\VerifyRecoveryCodeRequest;
use App\Platform\IntendedUrl;
use App\Platform\PlatformAuth;
use App\Platform\SamlSsoHandoff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Response;

/**
 * THE SECOND FACTOR, between a correct password and a session.
 *
 * Both doors — the authenticator code and a recovery code — share one throttle, keyed to
 * the PENDING SUBJECT rather than to the caller. Keying it on the IP would let somebody
 * behind a rotating address grind a six-digit space, and keying it per door would let
 * them take five guesses at each.
 */
final readonly class MfaController extends PageController
{
    public function show(Request $request, PlatformAuth $auth): Response|RedirectResponse
    {
        // No pending password step means there is nothing to verify. Sent back to the
        // door rather than shown a form that can never succeed.
        if ($auth->pendingMfaSubject($request) === null) {
            return to_route('login');
        }

        return $this->page('auth/mfa', 'Two-factor verification');
    }

    public function verify(VerifyMfaRequest $request, PlatformAuth $auth): RedirectResponse
    {
        $throttle = $this->throttleKey($request, $auth);

        if (RateLimiter::tooManyAttempts($throttle, 5)) {
            return $this->tooManyAttempts($throttle, 'code');
        }

        if (! $auth->completeMfa($request, $request->code())) {
            RateLimiter::hit($throttle, 60);

            return back()->withErrors(['code' => 'That code is incorrect or has expired.']);
        }

        RateLimiter::clear($throttle);

        return redirect()->to($this->destination());
    }

    public function recover(VerifyRecoveryCodeRequest $request, PlatformAuth $auth): RedirectResponse
    {
        $throttle = $this->throttleKey($request, $auth);

        if (RateLimiter::tooManyAttempts($throttle, 5)) {
            return $this->tooManyAttempts($throttle, 'recoveryCode');
        }

        if (! $auth->completeMfaWithRecoveryCode($request, $request->recoveryCode())) {
            RateLimiter::hit($throttle, 60);

            return back()->withErrors([
                'recoveryCode' => 'That recovery code is invalid or already used.',
            ]);
        }

        RateLimiter::clear($throttle);

        return redirect()->to($this->destination());
    }

    /**
     * ONE KEY FOR BOTH DOORS.
     *
     * Keyed to the pending subject, so somebody behind a rotating address cannot grind
     * the six-digit space; shared between the code and the recovery path, so they cannot
     * take five guesses at each.
     */
    private function throttleKey(Request $request, PlatformAuth $auth): string
    {
        return 'mfa|'.($auth->pendingMfaSubject($request) ?? $request->ip());
    }

    private function tooManyAttempts(string $key, string $field): RedirectResponse
    {
        return back()->withErrors([
            $field => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
        ]);
    }

    /**
     * Where a completed second factor lands.
     *
     * A SAML sign-in that stopped here has a service provider waiting for an assertion,
     * and resuming it is not optional — dropping the person on the dashboard leaves the
     * application they were actually trying to reach with nothing. Then wherever they
     * were headed, then the console.
     */
    private function destination(): string
    {
        return app(SamlSsoHandoff::class)->resumeUrl()
            ?? IntendedUrl::pullForSubject()
            ?? route('dashboard');
    }
}
