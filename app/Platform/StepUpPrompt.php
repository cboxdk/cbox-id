<?php

declare(strict_types=1);

namespace App\Platform;

use App\Http\Middleware\RequireEnvironmentSudo;
use App\Http\Middleware\RequireSudo;
use App\Platform\Console\ConsoleStepUp;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ASKING FOR A STEP-UP — the one place every gate in the console does it.
 *
 * The two route middlewares ({@see RequireSudo}, {@see RequireEnvironmentSudo}) and the
 * action-level gate ({@see ConsoleStepUp}) all end here, so a step-up is asked for the
 * same way whichever of them noticed it was owed.
 *
 * IN PLACE, NOT A DETOUR. Every click in the console is an Inertia visit or a JSON call,
 * and both used to be answered with a trip to the full-page `/sudo` screen that came back
 * to the PAGE — never to the action. Somebody who pressed "Add passkey", typed their
 * password and landed back on My account with no passkey had done everything right and
 * got nothing. So a request a script made is answered with a 403 the client recognises
 * (the `X-Cbox-Step-Up` header, and `step_up` in the body): the page opens a "Confirm it's
 * you" dialog where it is, confirms against this plane's endpoint, and replays the exact
 * request it was holding. Nothing navigates.
 *
 * THE FULL PAGE STAYS for the one request that has no script to hold anything: a document
 * navigation (a bookmark, a typed URL, a link opened in a new tab). That is a GET for a
 * page, and the page itself is the place to come back to.
 *
 * THROWN, NOT RETURNED, for the in-place answer. A gate that returns a non-redirect
 * response can be discarded by a caller that only honours redirects — that is how
 * Livewire's persistent middleware once made the sudo gate bypassable with one
 * client-controlled header — and a component-level gate has no response to return through
 * at all. Throwing is the only shape that cannot be swallowed on the way out.
 */
final class StepUpPrompt
{
    /**
     * The header that marks a step-up refusal. Its value is the plane's confirm URL, so a
     * client can tell this 403 from every other one without parsing the body.
     */
    public const string HEADER = 'X-Cbox-Step-Up';

    /**
     * Ask for a step-up on `$plane` for the request in hand.
     *
     * `$intended` is where the FULL-PAGE screen sends the person once they confirm; when
     * null it is worked out from the request (the page for a GET, the page the write was
     * made from otherwise). `$reason` is the sentence the prompt shows — required by the
     * action-level gate, optional for a route that only says "this is protected".
     *
     * Returns the redirect for a document navigation; THROWS the in-place answer for
     * anything a script made.
     *
     * @throws HttpResponseException
     */
    public static function demand(Request $request, StepUpPlane $plane, ?string $intended = null, ?string $reason = null): RedirectResponse
    {
        $intended ??= self::intendedFor($request);

        if ($intended !== null) {
            $request->session()->put($plane->value.'.intended', $intended);
        }

        if ($reason !== null && $intended !== null) {
            StepUpReason::record($plane->value, $reason, $intended);
        } elseif ($reason === null) {
            // A sentence left behind by an abandoned step-up must not caption this one.
            StepUpReason::forget($plane->value);
        }

        if (self::inPlace($request)) {
            throw new HttpResponseException(self::response($plane, $reason));
        }

        return redirect()->to($plane->pageUrl());
    }

    /**
     * Whether a script made this request and can hold it while the person confirms: an
     * Inertia visit, a JSON call, or any other XHR. Only a document navigation is not.
     */
    public static function inPlace(Request $request): bool
    {
        return $request->header('X-Inertia') !== null
            || $request->expectsJson()
            || $request->ajax();
    }

    /**
     * The 403 a client turns into the "Confirm it's you" dialog.
     *
     * `error` and `sudo` are kept exactly as they always were, for a client that predates
     * the dialog: it can still send the person to the full page. `step_up` is what the
     * dialog reads — which plane, where to confirm, and why.
     */
    public static function response(StepUpPlane $plane, ?string $reason): JsonResponse
    {
        return new JsonResponse([
            'error' => 'Confirm it\'s you first — re-enter your password, then try again.',
            'sudo' => $plane->pageUrl(),
            'step_up' => [
                'plane' => $plane->label(),
                'confirm' => $plane->confirmUrl(),
                'reason' => $reason,
            ],
        ], 403, [
            self::HEADER => $plane->confirmUrl(),
            // Never cached by anything: the same request is the right answer a moment later.
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Where the full-page screen should return to: the page itself for a GET (the person
     * asked for that page), and the page the write was made FROM otherwise — `fullUrl()` of
     * a POST would send somebody to an action by GET after they re-entered their password,
     * and a 405 is a poor reward for proving who you are.
     */
    public static function intendedFor(Request $request): ?string
    {
        return $request->isMethod('GET')
            ? $request->fullUrl()
            : self::sameOriginPath($request, $request->headers->get('referer'));
    }

    /**
     * The path+query of a referer that is EXACTLY same-origin as this request, or null.
     * Root-relative by construction, so the eventual redirect can never leave the app
     * whatever authority the referer carried — the value is a redirect target, which makes
     * it an open-redirect sink, and the Referer is a header the caller writes.
     */
    public static function sameOriginPath(Request $request, ?string $referer): ?string
    {
        if (! is_string($referer) || $referer === '') {
            return null;
        }

        $parts = parse_url($referer);

        // Exact host + scheme match, not a prefix: a prefix check passes `https://host.evil/…`.
        if ($parts === false
            || ($parts['host'] ?? null) !== $request->getHost()
            || ($parts['scheme'] ?? null) !== $request->getScheme()) {
            return null;
        }

        $path = $parts['path'] ?? '/';

        // A path, and unambiguously a LOCAL one.
        //
        // `str_starts_with($path, '/')` alone admits `//evil.tld/x` and `/\evil.tld/x`,
        // which browsers and Laravel's own UrlGenerator::isValidUrl() read as
        // protocol-relative absolute URLs — so a Referer of `https://<our-host>//evil.tld/x`
        // came back out verbatim and the step-up redirected off-site.
        if (! str_starts_with($path, '/') || str_starts_with($path, '//') || str_starts_with($path, '/\\')) {
            return null;
        }

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
