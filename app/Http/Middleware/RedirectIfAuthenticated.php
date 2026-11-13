<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Impersonation;
use App\Platform\PlatformAuth;
use App\Platform\SigningInAgain;
use Cbox\Id\Identity\Contracts\SessionManager;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps already-authenticated visitors out of the guest screens (login, signup).
 *
 * Except a person who is SIGNING IN AGAIN ({@see SigningInAgain}): the add-another-account
 * page, which is also where an app's `prompt=login`, `max_age` and step-up send a signed-in
 * person, posts to these same doors. Never during impersonation, where no sign-in may
 * happen on the person's behalf.
 *
 * A JSON caller is never answered with a redirect. The passkey ceremony asks these doors for
 * its challenge with `fetch`, and a fetch FOLLOWS a 302: in login-host mode the dashboard is
 * on the app host, so the redirect left the origin, `connect-src 'self'` stopped it, and the
 * page could only say "Load failed". A sign-in page left open while the browser signed in
 * elsewhere — a restored tab on a phone, most often — is exactly that browser. It is told
 * where to go instead (409 with `redirect`), and the page goes there itself, as a navigation.
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
            if ($this->wantsJson($request)) {
                return new JsonResponse([
                    'message' => 'You are already signed in.',
                    'redirect' => route('dashboard'),
                ], 409);
            }

            return redirect()->route('dashboard');
        }

        return $next($request);
    }

    /**
     * A script asking for JSON — not an Inertia visit, which has its own way to follow a
     * redirect ({@see RedirectOutsideInertia}) and must keep getting one.
     */
    private function wantsJson(Request $request): bool
    {
        return ! $request->hasHeader('X-Inertia') && ($request->expectsJson() || $request->isJson());
    }
}
