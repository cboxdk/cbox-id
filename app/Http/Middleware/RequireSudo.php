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
        // the client to re-authenticate. Don't record an intended URL for these —
        // they are POST API calls, not navigable pages.
        if ($request->expectsJson() || $request->ajax()) {
            return new JsonResponse([
                'error' => 'Confirm your identity first — re-enter your password, then try again.',
                'sudo' => route('sudo'),
            ], 403);
        }

        $request->session()->put('sudo.intended', $request->fullUrl());

        return redirect()->route('sudo');
    }
}
