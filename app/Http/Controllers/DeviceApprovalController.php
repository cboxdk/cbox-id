<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\CurrentUser;
use App\Platform\OAuth\ConsentScopes;
use App\Platform\OAuth\DeviceUserCode;
use App\Platform\Radar\Enums\RadarMethod;
use App\Platform\RiskGuard;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\DeviceAuthorization;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Otp\Contracts\OtpService;
use Cbox\Id\Otp\Exceptions\OtpRateLimitExceeded;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Response;

/**
 * RFC 8628 DEVICE GRANT — where a person approves the code their television, console or
 * command line is showing them.
 *
 * A HOSTED PAGE, NOT A CONSOLE ONE. It used to render inside the admin console's chrome, in
 * English only, for a person who had just scanned a QR code off their TV with a phone and
 * has never seen an admin console in their life. It is now drawn like the other doors —
 * the environment's (or Cbox's) brand, the visitor's language, built for a phone first —
 * because that is who arrives here: an end user of the vendor's app, holding a remote.
 *
 * THE RESOLVED CODE LIVES IN THE SESSION, never in the page and never in the URL after the
 * first hop. A value the browser never holds cannot be swapped between the screen that
 * showed one request and the click that approves another, so the guarantee is structural.
 * The code IS shown on the consent step — rendered from the session, as text — because the
 * one check a person can make against device-code phishing is "does this match my TV".
 *
 * NOTHING IS APPROVED BY ARRIVING. Following `verification_uri_complete` resolves the code
 * and shows what is being asked for; approving is still a deliberate click — and on an
 * approval Radar finds unusual, an emailed code first, to show who is holding the phone.
 */
final readonly class DeviceApprovalController extends PageController
{
    /** Where the resolved, consented-to code is kept between the two requests. */
    private const CODE_KEY = 'device.user_code';

    /**
     * The code a Radar challenge asked to be confirmed by email, and — once the emailed
     * code was entered — the code that confirmation is good for. Both name the USER CODE,
     * so a confirmation earned for one device request never approves another.
     */
    private const STEP_UP_KEY = 'device.step_up';

    private const STEP_UP_VERIFIED_KEY = 'device.step_up_verified';

    /** The one-time-code purpose, kept apart from the sign-in step-up's. */
    private const OTP_PURPOSE = 'device_step_up';

    /**
     * A signed-in session must not become a way to brute-force short user codes.
     *
     * Per PERSON rather than per address: the address is shared by everyone behind one
     * office NAT, and the session is the thing actually doing the guessing.
     */
    private const LOOKUP_ATTEMPTS = 10;

    public function show(Request $request, DeviceAuthorization $devices, ClientRegistry $clients): Response|RedirectResponse
    {
        $me = app(CurrentUser::class);

        abort_unless($me->check(), 403);

        /*
         * THE DEVICE'S OWN LINK. `verification_uri_complete` (RFC 8628 §3.3.1) exists
         * precisely so the person does not have to type or confirm the code — following
         * the link IS the step. Stopping to ask them to press Continue on a form they did
         * not fill in reads as "something went wrong", on a phone, in the middle of
         * somebody else's terminal session.
         */
        $fromLink = $request->query('user_code');

        if (is_string($fromLink)) {
            $resolved = $this->resolve($fromLink, $devices, $clients);

            if ($resolved === null) {
                /*
                 * A bad code in a LINK is not the same event as a bad code somebody typed:
                 * they got here by following a link, so "check the code on your device" is
                 * advice about a code they never saw. On the flash channel — a sentence
                 * about the request that just happened, true for exactly one render.
                 */
                $this->inertia->flash('deviceError', __('oauth.device.link_expired'));

                return redirect()->route('device');
            }

            $request->session()->put(self::CODE_KEY, $resolved['code']);

            // Redirected so the code leaves the address bar — and so a refresh re-reads the
            // session rather than resolving the code a second time.
            return redirect()->route('device');
        }

        $code = $request->session()->get(self::CODE_KEY);
        $pending = is_string($code) ? $this->resolve($code, $devices, $clients) : null;

        if ($pending === null) {
            // Whatever was consented to is gone — expired, finished, or never there. Drop
            // it rather than leaving a stale code the approve endpoint would act on.
            $this->forgetConsent($request);
        }

        return $this->page('oauth/device', __('oauth.device.title'), [
            'client' => $pending === null ? null : [
                'name' => $pending['clientName'],
                'scopes' => $pending['scopes'],
            ],
            // The code being approved, for the person to hold against their TV's screen.
            // Read back from the session; nothing the browser sends can change it.
            'userCode' => $pending['code'] ?? null,
            // Radar asked for a confirmation: where the emailed code went, masked.
            'stepUp' => $pending !== null && $request->session()->get(self::STEP_UP_KEY) === $pending['code']
                ? ['sentTo' => self::mask((string) $me->email())]
                : null,
            'me' => [
                'name' => $me->name(),
                'email' => $me->email(),
                'initial' => mb_strtoupper(mb_substr($me->name(), 0, 1)),
            ],
            'urls' => [
                'lookup' => route('device.lookup'),
                'approve' => route('device.approve'),
                'deny' => route('device.deny'),
                'verify' => route('device.verify'),
                'start' => route('device'),
            ],
        ]);
    }

    /** Step 1 — resolve a TYPED code to the app and scopes it authorizes. */
    public function lookup(Request $request, DeviceAuthorization $devices, ClientRegistry $clients): RedirectResponse
    {
        $me = app(CurrentUser::class);

        abort_unless($me->check(), 403);

        $request->validate(['userCode' => ['required', 'string', 'max:32']], [
            'userCode.required' => __('oauth.device.code_required'),
        ]);

        $key = 'device-lookup|'.$me->id();

        if (RateLimiter::tooManyAttempts($key, self::LOOKUP_ATTEMPTS)) {
            return back()->withErrors([
                'userCode' => __('oauth.device.too_many', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        $resolved = $this->resolve((string) $request->string('userCode'), $devices, $clients);

        if ($resolved === null) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['userCode' => __('oauth.device.invalid')]);
        }

        RateLimiter::clear($key);

        $request->session()->put(self::CODE_KEY, $resolved['code']);

        return redirect()->route('device');
    }

    /**
     * Step 2a — approve, binding the acting person and organization to the request.
     *
     * RADAR HAS A SAY HERE TOO. Approving a device code mints tokens for a device that is
     * not this one — it is a sign-in, on somebody else's screen, and it is exactly what a
     * device-code phishing attack asks its victim to do. So the approval is assessed like
     * a sign-in: a block refuses it, and a challenge sends a one-time code to the person's
     * email and asks for it on this page before the device is connected — the same proof
     * Radar's sign-in challenge asks for, and one every account can give, whether it signs
     * in with a password, a passkey or Google. The session itself already went through the
     * sign-in's own Radar assessment and second factor; this is the second look at the
     * moment that matters.
     */
    public function approve(Request $request, DeviceAuthorization $devices, RiskGuard $risk, OtpService $otp): RedirectResponse
    {
        $me = app(CurrentUser::class);

        abort_unless($me->check(), 403);

        $code = $this->consentedCode($request);

        // Already confirmed for THIS code by the emailed one: no second assessment, which
        // would only ask again for what was just proved.
        if ($request->session()->get(self::STEP_UP_VERIFIED_KEY) !== $code) {
            $assessment = $risk->assess($request, 'device_approval', $me->email(), method: RadarMethod::Password);

            if ($risk->shouldBlock($assessment)) {
                $this->forgetConsent($request);
                $this->inertia->flash('deviceError', __('oauth.device.blocked'));

                return redirect()->route('device');
            }

            if ($risk->shouldStepUp($assessment)) {
                return $this->challenge($request, $otp, $code, (string) $me->email());
            }
        }

        return $this->complete($request, $devices, $code);
    }

    /**
     * Step 2a′ — the emailed code a Radar challenge asked for. Right, and the device is
     * approved in the same step: the person already pressed Approve once.
     */
    public function verify(Request $request, DeviceAuthorization $devices, OtpService $otp): RedirectResponse
    {
        $me = app(CurrentUser::class);

        abort_unless($me->check(), 403);

        $code = $this->consentedCode($request);

        abort_unless($request->session()->get(self::STEP_UP_KEY) === $code, 404);

        $request->validate(['stepUpCode' => ['required', 'string', 'max:16']], [
            'stepUpCode.required' => __('oauth.device.step_up_invalid'),
        ]);

        $typed = preg_replace('/\s+/', '', (string) $request->string('stepUpCode')) ?? '';

        if (! $otp->verifyLatest(self::OTP_PURPOSE, (string) $me->email(), $typed, $request->ip())->verified) {
            return back()->withErrors(['stepUpCode' => __('oauth.device.step_up_invalid')]);
        }

        $request->session()->forget(self::STEP_UP_KEY);
        $request->session()->put(self::STEP_UP_VERIFIED_KEY, $code);

        return $this->complete($request, $devices, $code);
    }

    /** Step 2b — deny, so the requesting device stops polling with `access_denied`. */
    public function deny(Request $request, DeviceAuthorization $devices): RedirectResponse
    {
        abort_unless(app(CurrentUser::class)->check(), 403);

        $devices->deny($this->consentedCode($request));

        $this->forgetConsent($request);

        $this->inertia->flash('deviceOutcome', 'denied');

        return redirect()->route('device');
    }

    /** Approve the consented code, now that nothing stands in the way. */
    private function complete(Request $request, DeviceAuthorization $devices, string $code): RedirectResponse
    {
        $me = app(CurrentUser::class);

        /*
         * NO ORGANIZATION-STATUS CHECK HERE, and its absence is deliberate:
         * {@see \App\Http\Middleware\Authenticate} asks {@see \App\Platform\OrganizationAccess}
         * of every authenticated request, so a suspended or deleted organization never
         * reaches this line. DeletedOrganizationEnforcementTest asks the door that answers.
         */
        if (! $devices->approve($code, $me->id(), $me->organizationId())) {
            // Expired between the consent screen and the click — send them back to the form.
            $this->forgetConsent($request);

            return back()->withErrors(['userCode' => __('oauth.device.invalid')]);
        }

        $this->forgetConsent($request);
        $this->inertia->flash('deviceOutcome', 'approved');

        return redirect()->route('device');
    }

    /**
     * Radar challenged the approval: email a one-time code and ask for it on this page.
     *
     * An account with no address to send it to cannot be challenged this way, and is
     * refused rather than waved through — the challenge exists because something about
     * this approval looked wrong.
     */
    private function challenge(Request $request, OtpService $otp, string $code, string $email): RedirectResponse
    {
        if ($email === '') {
            $this->forgetConsent($request);
            $this->inertia->flash('deviceError', __('oauth.device.blocked'));

            return redirect()->route('device');
        }

        try {
            $otp->issue(self::OTP_PURPOSE, $email, 'email', $request->ip());
        } catch (OtpRateLimitExceeded) {
            // One was sent a moment ago and is still good: keep asking for it.
            $request->session()->put(self::STEP_UP_KEY, $code);

            return redirect()->route('device')->withErrors(['stepUpCode' => __('oauth.device.step_up_wait')]);
        }

        $request->session()->put(self::STEP_UP_KEY, $code);

        return redirect()->route('device');
    }

    /** Whatever this session consented to, and any confirmation that went with it. */
    private function forgetConsent(Request $request): void
    {
        $request->session()->forget([self::CODE_KEY, self::STEP_UP_KEY, self::STEP_UP_VERIFIED_KEY]);
    }

    /** `d••••@acme.test` — enough to recognise the inbox, not enough to be one. */
    private static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        $masked = mb_substr($local, 0, 1).str_repeat('•', max(1, mb_strlen($local) - 1));

        return $domain === '' ? $masked : $masked.'@'.$domain;
    }

    /**
     * The code this session actually consented to.
     *
     * TAKEN FROM THE SESSION AND FROM NOWHERE ELSE — this is the whole reason the resolved
     * code is kept server-side. A code accepted from the request body here would let one
     * click approve a device request the person was never shown.
     */
    private function consentedCode(Request $request): string
    {
        $code = $request->session()->get(self::CODE_KEY);

        abort_unless(is_string($code) && $code !== '', 404);

        return $code;
    }

    /**
     * Resolve a user code to the client and scopes behind it, or null.
     *
     * The scopes are the consent screen's rows ({@see ConsentScopes}), in the visitor's
     * language — so a management scope the `cbox` CLI asks for reads as what it lets the CLI
     * do, with the critical ones flagged, and `email` reads as "Your email address".
     *
     * @return array{code: string, clientName: string, scopes: list<array{scope: string, label: string, management: bool, critical: bool}>}|null
     */
    private function resolve(string $userCode, DeviceAuthorization $devices, ClientRegistry $clients): ?array
    {
        $code = DeviceUserCode::normalize($userCode);

        if ($code === null) {
            return null;
        }

        $pending = $devices->pending($code);
        $client = $pending !== null ? $clients->byClientId($pending->clientId) : null;

        if ($pending === null || ! $client instanceof Client) {
            return null;
        }

        return [
            'code' => $code,
            'clientName' => $client->name,
            'scopes' => app(ConsentScopes::class)->rows($pending->scopes),
        ];
    }
}
