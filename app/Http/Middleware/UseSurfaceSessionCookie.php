<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Surfaces\SessionSurface;
use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * The session cookie of the surface this request is on ({@see SessionSurface}).
 *
 * First in the `web` group, ahead of `StartSession`, which reads the session id from the
 * cookie of this name and writes it back under it. The name is set on every request to a
 * surface that has one, in the configuration and on the session store itself, so a
 * long-lived worker cannot carry one request's name into the next.
 *
 * With no login host configured this changes nothing.
 */
final class UseSurfaceSessionCookie
{
    public function __construct(
        private readonly SessionSurface $sessions,
        private readonly SessionManager $manager,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $name = $this->sessions->cookieName($request);

        if ($name === null) {
            return $next($request);
        }

        // `__Host-`: host-only, path `/`, and Secure whenever the request is.
        config([
            'session.cookie' => $name,
            'session.domain' => null,
            'session.path' => '/',
            'session.secure' => $request->isSecure() ? true : config('session.secure'),
        ]);

        $store = $this->manager->driver();

        if ($store instanceof Session) {
            $store->setName($name);
        }

        return $next($request);
    }
}
