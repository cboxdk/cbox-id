<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\WorkspaceSudo;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate an account-member (workspace) action behind a fresh step-up ("sudo")
 * confirmation. Enrolling a passkey establishes a new, persistent way in, so it is
 * exactly as sensitive as minting an API key: a hijacked-but-stale workspace
 * session must not be able to plant durable persistence. The account-plane mirror
 * of {@see RequireSudo}, keyed off {@see WorkspaceSudo}.
 */
final class RequireWorkspaceSudo
{
    public function __construct(private readonly WorkspaceSudo $sudo) {}

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
        // (the origin-matched, path-relative referer) so sudo returns the member to
        // where they were — never an attacker-controlled absolute URL.
        if ($request->expectsJson() || $request->ajax()) {
            $intended = $this->sameOriginPath($request, $request->headers->get('referer'));

            if ($intended !== null) {
                $request->session()->put('workspace.sudo.intended', $intended);
            }

            // THROWN, not returned: Livewire only honours a short-circuit that is a
            // RedirectResponse, so a returned JsonResponse would be discarded and the
            // action would run anyway — the exact retained-snapshot bypass this gate closes.
            throw new HttpResponseException(new JsonResponse([
                'error' => 'Confirm your identity first — re-enter your password, then try again.',
                'sudo' => route('workspace.sudo'),
            ], 403));
        }

        $request->session()->put('workspace.sudo.intended', $request->fullUrl());

        return redirect()->route('workspace.sudo');
    }

    /**
     * The path+query of a referer EXACTLY same-origin as this request, or null.
     * Root-relative by construction, so the eventual sudo redirect can never leave
     * the app regardless of the referer's authority.
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

        return ! str_starts_with($path, '/') ? null : $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
