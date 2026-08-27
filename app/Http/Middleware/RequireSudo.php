<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Sudo;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate an action behind a fresh step-up ("sudo") confirmation. Adding a
 * credential — a passkey or a linked social provider — establishes a new,
 * persistent way in, so it is exactly as sensitive as removing one: a hijacked
 * but stale session must not be able to plant persistence. Mirrors the inline
 * step-up already guarding credential REMOVAL in the settings component.
 */
final class RequireSudo
{
    public function __construct(private readonly Sudo $sudo) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->sudo->confirmed()) {
            return $next($request);
        }

        /*
         * AN INERTIA VISIT IS A REDIRECT, NOT AN ERROR.
         *
         * It arrives as an XHR — `X-Requested-With: XMLHttpRequest` — so without this it
         * would fall into the ceremony branch below and get a 403 JSON body the client
         * turns into nothing a person can act on. Inertia's own contract is that a 302 is
         * followed and the page it names is rendered, which is exactly the step-up screen.
         *
         * The intended target is taken from the REFERER, for the same reason the ceremony
         * branch takes it there: `fullUrl()` on a POST endpoint would send somebody back to
         * an action by GET after they re-entered their password, and a 405 is a poor reward
         * for proving who you are.
         */
        if ($request->header('X-Inertia') !== null) {
            $intended = $this->sameOriginPath($request, $request->headers->get('referer'));

            if ($intended !== null) {
                $request->session()->put('sudo.intended', $intended);
            }

            return redirect()->route('sudo');
        }

        // JSON/ceremony endpoints (passkey enrolment) can't follow a redirect; tell
        // the client where to re-authenticate. Record the PAGE that made the call
        // (the referer) — not the POST endpoint — so sudo returns the user to where
        // they were to finish the action.
        if ($request->expectsJson() || $request->ajax()) {
            $referer = $request->headers->get('referer');

            // Store only the ORIGIN-MATCHED, path-relative referer. A prefix check
            // (str_starts_with) would pass a look-alike like `https://host.evil/…`;
            // and since sudo redirects to this value, an absolute URL would be an
            // open-redirect sink. Keep just the path+query → always same-origin.
            $intended = $this->sameOriginPath($request, $referer);

            if ($intended !== null) {
                $request->session()->put('sudo.intended', $intended);
            }

            // THROWN, not returned. Livewire's Utils::applyMiddleware() only honours a
            // short-circuit that is a RedirectResponse — anything else is silently
            // discarded and the component action runs anyway. Returning a JsonResponse
            // here therefore made the sudo gate bypassable with one client-controlled
            // header (`Accept: application/json`) on a replayed /livewire/update, which
            // is exactly the retained-snapshot bypass persisting this middleware was
            // meant to close. EnforcePlane and BlockDuringImpersonation already throw.
            throw new HttpResponseException(new JsonResponse([
                'error' => 'Confirm your identity first — re-enter your password, then try again.',
                'sudo' => route('sudo'),
            ], 403));
        }

        /*
         * A PLAIN BROWSER REQUEST. `fullUrl()` is right here and only here: this branch is
         * reached by a GET for a gated PAGE, which is a place to come back to. A form POST
         * that lands here would store its own endpoint — so console writes are Inertia
         * visits, handled above, and the two ceremony endpoints are handled below.
         */
        $request->session()->put('sudo.intended', $request->fullUrl());

        return redirect()->route('sudo');
    }

    /**
     * The path+query of a referer that is EXACTLY same-origin as this request, or
     * null. Returns a root-relative string so the eventual sudo redirect can never
     * leave the app, regardless of the referer's authority.
     */
    private function sameOriginPath(Request $request, ?string $referer): ?string
    {
        if (! is_string($referer) || $referer === '') {
            return null;
        }

        $parts = parse_url($referer);

        // Exact host + scheme match (not a prefix). The returned value is root-relative
        // regardless, so the redirect target is same-origin by construction.
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
        // protocol-relative absolute URLs — so a Referer of
        // `https://<our-host>//evil.tld/x` came back out of here verbatim and the
        // step-up redirected off-site. The comment above this method claimed the result
        // is always same-origin; it was one slash away from not being.
        if (! str_starts_with($path, '/') || str_starts_with($path, '//') || str_starts_with($path, '/\\')) {
            return null;
        }

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
