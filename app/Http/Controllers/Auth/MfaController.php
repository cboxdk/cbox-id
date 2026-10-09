<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\PageController;
use App\Http\Requests\Auth\VerifyMfaRequest;
use App\Http\Requests\Auth\VerifyRecoveryCodeRequest;
use App\Http\Requests\Auth\VerifySmsCodeRequest;
use App\Platform\IntendedUrl;
use App\Platform\PlatformAuth;
use App\Platform\SamlSsoHandoff;
use Cbox\Id\Otp\Exceptions\OtpRateLimitExceeded;
use Cbox\Id\Otp\Sms\Exceptions\SmsDeliveryFailed;
use Cbox\Id\Otp\Sms\Exceptions\SmsSendRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Response;

/**
 * THE SECOND FACTOR, between a correct password and a session.
 *
 * Every door — the authenticator code, a texted code and a recovery code — shares one
 * throttle, keyed to the PENDING SUBJECT rather than to the caller. Keying it on the IP
 * would let somebody behind a rotating address grind a six-digit space, and keying it per
 * door would let them take five guesses at each.
 *
 * THE TEXT IS SENT ON REQUEST, never on arrival. Rendering the page is a GET that a reload,
 * a prefetch or a back button repeats; a send costs money and burns the cooldown, so it is
 * a button the person presses. The page offers it first when SMS is their only factor.
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

        return $this->page('auth/mfa', __('auth.mfa.title'), [
            'factors' => $auth->pendingMfaFactors($request),
        ]);
    }

    /**
     * Text a sign-in code to the pending person's number.
     *
     * Every refusal reads the same to the page — "wait a moment" for the caps, "could not
     * send" for a provider failure — and none of them names the number or why it was
     * refused: the person at this page has typed a password, not proved a phone.
     */
    public function sendSms(Request $request, PlatformAuth $auth): RedirectResponse
    {
        if ($auth->pendingMfaSubject($request) === null) {
            return to_route('login');
        }

        try {
            $sent = $auth->sendSmsChallenge($request);
        } catch (OtpRateLimitExceeded|SmsSendRefused) {
            return back()->withErrors(['smsCode' => __('auth.mfa.sms.wait')]);
        } catch (SmsDeliveryFailed $e) {
            report($e);

            return back()->withErrors(['smsCode' => __('auth.mfa.sms.failed')]);
        }

        if ($sent === null) {
            return back()->withErrors(['smsCode' => __('auth.mfa.sms.unavailable')]);
        }

        $this->inertia->flash('smsSentTo', $sent->maskedNumber);

        return back();
    }

    public function verifySms(VerifySmsCodeRequest $request, PlatformAuth $auth): RedirectResponse
    {
        $throttle = $this->throttleKey($request, $auth);

        if (RateLimiter::tooManyAttempts($throttle, 5)) {
            return $this->tooManyAttempts($throttle, 'smsCode');
        }

        if (! $auth->completeMfaWithSms($request, $request->code())) {
            RateLimiter::hit($throttle, 60);

            return back()->withErrors(['smsCode' => __('auth.common.code_incorrect')]);
        }

        RateLimiter::clear($throttle);

        return redirect()->to($this->destination());
    }

    public function verify(VerifyMfaRequest $request, PlatformAuth $auth): RedirectResponse
    {
        $throttle = $this->throttleKey($request, $auth);

        if (RateLimiter::tooManyAttempts($throttle, 5)) {
            return $this->tooManyAttempts($throttle, 'code');
        }

        if (! $auth->completeMfa($request, $request->code())) {
            RateLimiter::hit($throttle, 60);

            return back()->withErrors(['code' => __('auth.common.code_incorrect')]);
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
                'recoveryCode' => __('auth.mfa.recovery.invalid'),
            ]);
        }

        RateLimiter::clear($throttle);

        return redirect()->to($this->destination());
    }

    /**
     * ONE KEY FOR EVERY DOOR.
     *
     * Keyed to the pending subject, so somebody behind a rotating address cannot grind
     * the six-digit space; shared between the authenticator, the texted code and the
     * recovery path, so they cannot take five guesses at each.
     */
    private function throttleKey(Request $request, PlatformAuth $auth): string
    {
        return 'mfa|'.($auth->pendingMfaSubject($request) ?? $request->ip());
    }

    private function tooManyAttempts(string $key, string $field): RedirectResponse
    {
        return back()->withErrors([
            $field => trans_choice('auth.common.too_many_attempts', RateLimiter::availableIn($key)),
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
