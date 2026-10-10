<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\CurrentUser;
use App\Platform\OAuth\ConsentScopes;
use App\Platform\OAuth\DeviceUserCode;
use App\Platform\Radar\Enums\RadarMethod;
use App\Platform\RiskGuard;
use App\Platform\StepUpReason;
use App\Platform\Sudo;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\DeviceAuthorization;
use Cbox\Id\OAuthServer\Models\Client;
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
 * and shows what is being asked for; approving is still a deliberate click — and on a
 * sign-in Radar finds unusual, a fresh confirmation of who is holding the phone first.
 */
final readonly class DeviceApprovalController extends PageController
{
    /** Where the resolved, consented-to code is kept between the two requests. */
    private const CODE_KEY = 'device.user_code';

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
            $request->session()->forget(self::CODE_KEY);
        }

        return $this->page('oauth/device', __('oauth.device.title'), [
            'client' => $pending === null ? null : [
                'name' => $pending['clientName'],
                'scopes' => $pending['scopes'],
            ],
            // The code being approved, for the person to hold against their TV's screen.
            // Read back from the session; nothing the browser sends can change it.
            'userCode' => $pending['code'] ?? null,
            'me' => [
                'name' => $me->name(),
                'email' => $me->email(),
                'initial' => mb_strtoupper(mb_substr($me->name(), 0, 1)),
            ],
            'urls' => [
                'lookup' => route('device.lookup'),
                'approve' => route('device.approve'),
                'deny' => route('device.deny'),
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
     * a sign-in: a block refuses it, and a challenge asks the person to confirm it is them
     * (the console's step-up, `sudo`) before the device is connected. The session itself
     * already went through the sign-in's own Radar assessment and second factor; this is
     * the second look at the moment that matters.
     */
    public function approve(Request $request, DeviceAuthorization $devices, RiskGuard $risk, Sudo $sudo): RedirectResponse
    {
        $me = app(CurrentUser::class);

        abort_unless($me->check(), 403);

        $code = $this->consentedCode($request);

        $assessment = $risk->assess($request, 'device_approval', $me->email(), method: RadarMethod::Password);

        if ($risk->shouldBlock($assessment)) {
            $request->session()->forget(self::CODE_KEY);
            $this->inertia->flash('deviceError', __('oauth.device.blocked'));

            return redirect()->route('device');
        }

        if ($risk->shouldStepUp($assessment) && ! $sudo->confirmed()) {
            $intended = route('device');

            // The code stays in the session: confirming who they are brings the person
            // back to the same request, not to an empty form.
            $request->session()->put('sudo.intended', $intended);
            StepUpReason::record('sudo', __('oauth.device.step_up'), $intended);

            return redirect()->route('sudo');
        }

        /*
         * NO ORGANIZATION-STATUS CHECK HERE, and its absence is deliberate:
         * {@see \App\Http\Middleware\Authenticate} asks {@see \App\Platform\OrganizationAccess}
         * of every authenticated request, so a suspended or deleted organization never
         * reaches this line. DeletedOrganizationEnforcementTest asks the door that answers.
         */
        if (! $devices->approve($code, $me->id(), $me->organizationId())) {
            // Expired between the consent screen and the click — send them back to the form.
            $request->session()->forget(self::CODE_KEY);

            return back()->withErrors(['userCode' => __('oauth.device.invalid')]);
        }

        $request->session()->forget(self::CODE_KEY);

        $this->inertia->flash('deviceOutcome', 'approved');

        return redirect()->route('device');
    }

    /** Step 2b — deny, so the requesting device stops polling with `access_denied`. */
    public function deny(Request $request, DeviceAuthorization $devices): RedirectResponse
    {
        abort_unless(app(CurrentUser::class)->check(), 403);

        $devices->deny($this->consentedCode($request));

        $request->session()->forget(self::CODE_KEY);

        $this->inertia->flash('deviceOutcome', 'denied');

        return redirect()->route('device');
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
