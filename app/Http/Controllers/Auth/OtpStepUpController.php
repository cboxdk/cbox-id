<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\PageController;
use App\Http\Requests\Auth\VerifyMfaRequest;
use App\Platform\IntendedUrl;
use App\Platform\PlatformAuth;
use App\Platform\SamlSsoHandoff;
use Cbox\Id\Otp\Exceptions\OtpRateLimitExceeded;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Response;

/**
 * THE ADAPTIVE-RISK STEP-UP — a one-time code, emailed, because this sign-in looked
 * unusual.
 *
 * The same interstitial state as the second factor: the password passed, but the session
 * does not exist yet. Everything about who is being verified lives in the session and
 * nothing about it is submitted, so there is no field here an attacker can point at
 * somebody else.
 */
final readonly class OtpStepUpController extends PageController
{
    public function show(Request $request, PlatformAuth $auth): Response|RedirectResponse
    {
        $pending = $auth->pendingOtpStepUp($request);

        if ($pending === null) {
            return to_route('login');
        }

        return $this->page('auth/otp-step-up', 'Additional verification', [
            // MASKED. The person already knows their own address; the point of showing it
            // is to say WHICH inbox to look in, and an unmasked address on a page reached
            // without a session is one an onlooker learns too.
            'maskedEmail' => self::mask($pending['email']),
        ]);
    }

    public function verify(VerifyMfaRequest $request, PlatformAuth $auth): RedirectResponse
    {
        $pending = $auth->pendingOtpStepUp($request);

        if ($pending === null) {
            return to_route('login');
        }

        $key = 'otp-step-up|'.$pending['subject'];

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors([
                'code' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        if (! $auth->completeOtpStepUp($request, $request->code())) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['code' => 'That code is incorrect or has expired.']);
        }

        RateLimiter::clear($key);

        return redirect()->to(
            app(SamlSsoHandoff::class)->resumeUrl()
                ?? IntendedUrl::pullForSubject()
                ?? route('dashboard'),
        );
    }

    public function resend(Request $request, PlatformAuth $auth): RedirectResponse
    {
        $pending = $auth->pendingOtpStepUp($request);

        if ($pending === null) {
            return to_route('login');
        }

        // A throttle of our own on top of the OTP service's issuance cap. Both are wanted:
        // this one is about the button, that one is about the mailbox.
        $key = 'otp-step-up-resend|'.$pending['subject'];

        if (RateLimiter::tooManyAttempts($key, 3)) {
            return back()->withErrors([
                'code' => 'Too many requests. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        RateLimiter::hit($key, 60);

        try {
            $auth->resendOtpStepUp($request);
        } catch (OtpRateLimitExceeded) {
            return back()->withErrors([
                'code' => 'Too many codes requested. Please wait a moment and try again.',
            ]);
        }

        $this->inertia->flash('resent', 'We sent a new code to '.self::mask($pending['email']).'.');

        return back();
    }

    /** `d••••@acme.test` — enough to identify the inbox, not enough to be one. */
    private static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        $masked = mb_substr($local, 0, 1).str_repeat('•', max(1, mb_strlen($local) - 1));

        return $domain === '' ? $masked : $masked.'@'.$domain;
    }
}
