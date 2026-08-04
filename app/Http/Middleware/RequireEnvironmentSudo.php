<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\EnvironmentSudo;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate an ENVIRONMENT control-plane action behind a fresh step-up ("sudo")
 * confirmation. The environment-plane mirror of {@see RequireSudo}, keyed off
 * {@see EnvironmentSudo}.
 *
 * The plane it guards is the wider of the two: an environment administrator acts on every
 * organization in the environment, so an action that demands a fresh password from a
 * tenant admin must demand at least as much here. It did not — the token vault was gated
 * by `sudo` on the organization plane and by nothing at all on this one, which made the
 * step-up avoidable by using the more privileged door.
 */
final class RequireEnvironmentSudo
{
    public function __construct(private readonly EnvironmentSudo $sudo) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->sudo->confirmed()) {
            return $next($request);
        }

        // JSON/ceremony endpoints can't follow a redirect; tell the client where to
        // re-authenticate. Record the PAGE that made the call (the origin-matched,
        // path-relative referer) so sudo returns the administrator to where they were —
        // never an attacker-controlled absolute URL.
        if ($request->expectsJson() || $request->ajax()) {
            $intended = $this->sameOriginPath($request, $request->headers->get('referer'));

            if ($intended !== null) {
                $request->session()->put('environment.sudo.intended', $intended);
            }

            // THROWN, not returned: Livewire's Utils::applyMiddleware() only honours a
            // short-circuit that is a RedirectResponse, so a returned JsonResponse is
            // silently discarded and the component action runs anyway — which would make
            // this gate bypassable with one client-controlled header on a replayed
            // /livewire/update. Both sibling step-ups throw for the same reason.
            throw new HttpResponseException(new JsonResponse([
                'error' => 'Confirm your identity first — re-enter your password, then try again.',
                'sudo' => route('environment.sudo'),
            ], 403));
        }

        $request->session()->put('environment.sudo.intended', $request->fullUrl());

        return redirect()->route('environment.sudo');
    }

    /**
     * The path+query of a referer EXACTLY same-origin as this request, or null.
     * Root-relative by construction, so the eventual sudo redirect can never leave the
     * app regardless of the referer's authority.
     */
    private function sameOriginPath(Request $request, ?string $referer): ?string
    {
        if (! is_string($referer) || $referer === '') {
            return null;
        }

        $parts = parse_url($referer);

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
        // protocol-relative absolute URLs, so the step-up would redirect off-site.
        if (! str_starts_with($path, '/') || str_starts_with($path, '//') || str_starts_with($path, '/\\')) {
            return null;
        }

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
