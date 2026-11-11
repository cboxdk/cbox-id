<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Surfaces\Surfaces;
use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * IN LOGIN-HOST MODE THE FRONTEND API HOLDS NO SESSION.
 *
 * The embedded sign-in endpoints (`/frontend/v1/*`) run in the `web` group, because
 * `PlatformAuth::attemptPassword()` writes the session it establishes and the controller
 * reads it back to mint the ticket. That put the issuer's own session cookie on a
 * cross-origin endpoint that answers `Access-Control-Allow-Credentials: true`: a page that
 * called it with credentials had the person's SSO session read, written to and signed out
 * by the request, and was handed a session cookie in the answer.
 *
 * Here the request arrives with no cookies, its session lives in memory for that request
 * only, and the answer carries no cookie and does not allow credentials. A login ticket is
 * the only thing that leaves; `/oauth/authorize` on the issuer turns it into a session.
 *
 * Global, so it wraps the `web` group and the framework's own `/frontend/v1/config` and
 * `/session` alike. With no login host configured this passes every request through.
 */
final class StatelessFrontendApi
{
    public const string PATHS = 'frontend/v1/*';

    public function __construct(
        private readonly Surfaces $surfaces,
        private readonly SessionManager $sessions,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->surfaces->split() || ! $request->is(self::PATHS)) {
            return $next($request);
        }

        $request->cookies->replace([]);
        $request->headers->remove('Cookie');

        $driver = config('session.driver');
        config(['session.driver' => 'array']);

        try {
            $response = $next($request);
        } finally {
            // Nothing outlives the request, in a long-lived worker either.
            $store = $this->sessions->driver('array');

            if ($store instanceof Session) {
                $store->flush();
            }

            config(['session.driver' => $driver]);
        }

        foreach ($response->headers->getCookies() as $cookie) {
            $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
        }

        $response->headers->remove('Set-Cookie');
        $response->headers->remove('Access-Control-Allow-Credentials');

        return $response;
    }
}
