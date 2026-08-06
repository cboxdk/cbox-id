<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps already-authenticated visitors out of the guest screens (login, signup).
 */
final class RedirectIfAuthenticated
{
    public function __construct(private readonly SessionManager $sessions) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $sessionId = $request->session()->get(PlatformAuth::SESSION_KEY);

        if (is_string($sessionId) && $this->sessions->active($sessionId) !== null) {
            return redirect()->route('dashboard');
        }

        return $next($request);
    }
}
