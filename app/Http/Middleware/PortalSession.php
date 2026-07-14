<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\AdminPortal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the Admin Portal setup screen: a request may proceed only while a valid,
 * unexpired, still-entitled portal session is held. Anything else is bounced to
 * the friendly "link expired" page — never to the platform login, because a
 * portal session is not a platform login.
 *
 * Registered as Livewire persistent middleware so it re-runs on every
 * `/livewire/update`, keeping the setup component's actions guarded.
 */
final class PortalSession
{
    public function __construct(private readonly AdminPortal $portal) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->portal->sessionValid()) {
            $this->portal->clearSession();

            return redirect()->route('portal.expired');
        }

        return $next($request);
    }
}
