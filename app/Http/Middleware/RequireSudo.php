<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Sudo;
use Closure;
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

            return new JsonResponse([
                'error' => 'Confirm your identity first — re-enter your password, then try again.',
                'sudo' => route('sudo'),
            ], 403);
        }

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

        return ! str_starts_with($path, '/') ? null : $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
