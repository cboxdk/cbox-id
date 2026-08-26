<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\CurrentUser;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\DeviceAuthorization;
use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Response;

/**
 * RFC 8628 DEVICE GRANT — where a signed-in person approves the code their television,
 * console or command line is showing them.
 *
 * THE RESOLVED CODE LIVES IN THE SESSION, never in the page and never in the URL after the
 * first hop. Under Volt it was a `#[Locked]` component property, which existed because the
 * browser would otherwise have been able to swap the app identity, the scopes or the code
 * itself between the render and the click — approving a DIFFERENT request than the one
 * consented to. A value the client never holds cannot be swapped at all, so the guarantee
 * is now structural rather than an attribute somebody has to remember.
 *
 * NOTHING IS APPROVED BY ARRIVING. Following `verification_uri_complete` resolves the code
 * and shows what is being asked for — which is the screen they came to read — and
 * approving is still a deliberate click.
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

    /** What each scope means, said in the words of the person approving it. */
    private const SCOPE_LABELS = [
        'openid' => 'Verify your identity',
        'profile' => 'Your name',
        'email' => 'Your email address',
        'offline_access' => 'Stay signed in',
    ];

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
            $resolved = $this->resolve($fromLink, $devices, $clients, $me);

            if ($resolved === null) {
                /*
                 * A bad code in a LINK is not the same event as a bad code somebody typed:
                 * they got here by following a link, so "check the code on your device" is
                 * advice about a code they never saw.
                 */
                /*
                 * ON THE FLASH CHANNEL. It is a sentence about the request that just
                 * happened, true for exactly one render — a prop would put it in the
                 * browser's history entry and show it again on a Back.
                 */
                $this->inertia->flash('deviceError', 'That sign-in request has expired or already finished. Enter the code shown on your device.');

                return redirect()->route('device');
            }

            $request->session()->put(self::CODE_KEY, $resolved['code']);

            // Redirected so the code leaves the address bar — and so a refresh re-reads the
            // session rather than resolving the code a second time.
            return redirect()->route('device');
        }

        $code = $request->session()->get(self::CODE_KEY);
        $pending = is_string($code) ? $this->resolve($code, $devices, $clients, $me) : null;

        if ($pending === null) {
            // Whatever was consented to is gone — expired, finished, or never there. Drop
            // it rather than leaving a stale code the approve endpoint would act on.
            $request->session()->forget(self::CODE_KEY);
        }

        return $this->page('device', 'Connect a device', [
            'client' => $pending === null ? null : [
                'name' => $pending['clientName'],
                'scopes' => $pending['scopes'],
            ],
            'me' => [
                'name' => $me->name(),
                'email' => $me->email(),
                'initial' => mb_strtoupper(mb_substr($me->name(), 0, 1)),
            ],
            'urls' => [
                'lookup' => route('device.lookup'),
                'approve' => route('device.approve'),
                'deny' => route('device.deny'),
            ],
        ]);
    }

    /** Step 1 — resolve a TYPED code to the app and scopes it authorizes. */
    public function lookup(Request $request, DeviceAuthorization $devices, ClientRegistry $clients): RedirectResponse
    {
        $me = app(CurrentUser::class);

        abort_unless($me->check(), 403);

        $request->validate(['userCode' => ['required', 'string']]);

        $key = 'device-lookup|'.$me->id();

        if (RateLimiter::tooManyAttempts($key, self::LOOKUP_ATTEMPTS)) {
            return back()->withErrors([
                'userCode' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        $resolved = $this->resolve((string) $request->string('userCode'), $devices, $clients, $me);

        if ($resolved === null) {
            RateLimiter::hit($key, 60);

            return back()->withErrors([
                'userCode' => 'That code is invalid or has expired. Check the code on your device and try again.',
            ]);
        }

        RateLimiter::clear($key);

        $request->session()->put(self::CODE_KEY, $resolved['code']);

        return redirect()->route('device');
    }

    /** Step 2a — approve, binding the acting person and organization to the request. */
    public function approve(Request $request, DeviceAuthorization $devices): RedirectResponse
    {
        $me = app(CurrentUser::class);

        abort_unless($me->check(), 403);

        $code = $this->consentedCode($request);

        /*
         * NO ORGANIZATION-STATUS CHECK HERE, and its absence is deliberate.
         *
         * An organization that is no longer live — suspended or deleted — cannot connect
         * devices or mint tokens, and this method used to say so itself. It had to: under
         * Volt the approval was a component action on the shared `/livewire/update`
         * endpoint, which route middleware never saw, so the page was the only place that
         * could refuse.
         *
         * It is a route now, and {@see \App\Http\Middleware\Authenticate} asks
         * {@see \App\Platform\OrganizationAccess} of every authenticated request — so a copy here would
         * be a branch no request can reach, and an unreachable guard is worse than none:
         * it reads as the thing holding the line while something else quietly does.
         * DeletedOrganizationEnforcementTest asks the door that actually answers.
         */
        if (! $devices->approve($code, $me->id(), $me->organizationId())) {
            // Expired between the consent screen and the click — send them back to the form.
            $request->session()->forget(self::CODE_KEY);

            return back()->withErrors([
                'userCode' => 'That code is invalid or has expired. Check the code on your device and try again.',
            ]);
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
     * @return array{code: string, clientName: string, scopes: list<array{scope: string, label: string}>}|null
     */
    private function resolve(string $userCode, DeviceAuthorization $devices, ClientRegistry $clients, CurrentUser $me): ?array
    {
        // Upper-cased and trimmed: the code is shown in capitals on the device, and a
        // person copying it from a phone keyboard sends whatever the keyboard decided.
        $code = mb_strtoupper(trim($userCode));

        if ($code === '') {
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
            'scopes' => array_map(
                static fn (string $scope): array => [
                    'scope' => $scope,
                    'label' => self::SCOPE_LABELS[$scope] ?? $scope,
                ],
                $pending->scopes,
            ),
        ];
    }
}
