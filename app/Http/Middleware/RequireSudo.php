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

            if (is_string($referer) && $referer !== '' && str_starts_with($referer, $request->schemeAndHttpHost())) {
                $request->session()->put('sudo.intended', $referer);
            }

            return new JsonResponse([
                'error' => 'Confirm your identity first — re-enter your password, then try again.',
                'sudo' => route('sudo'),
            ], 403);
        }

        $request->session()->put('sudo.intended', $request->fullUrl());

        return redirect()->route('sudo');
    }
}
