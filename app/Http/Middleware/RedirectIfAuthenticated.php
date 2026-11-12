<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Impersonation;
use App\Platform\PlatformAuth;
use App\Platform\SigningInAgain;
use Cbox\Id\Identity\Contracts\SessionManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps already-authenticated visitors out of the guest screens (login, signup).
 *
 * Except a person who is SIGNING IN AGAIN ({@see SigningInAgain}): the add-another-account
 * page, which is also where an app's `prompt=login`, `max_age` and step-up send a signed-in
 * person, posts to these same doors. Never during impersonation, where no sign-in may
 * happen on the person's behalf.
 */
final class RedirectIfAuthenticated
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly Impersonation $impersonation,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $sessionId = $request->session()->get(PlatformAuth::SESSION_KEY);

        if (is_string($sessionId) && $this->sessions->active($sessionId) !== null
            && (! SigningInAgain::underWay($request) || $this->impersonation->isImpersonating())) {
            return redirect()->route('dashboard');
        }

        return $next($request);
    }
}
