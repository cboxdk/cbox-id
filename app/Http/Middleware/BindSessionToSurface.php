<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Surfaces\SessionSurface;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A session is accepted on the surface that minted it and on no other
 * ({@see SessionSurface::bind()}).
 *
 * Straight after `StartSession` (the middleware priority list puts it there), so nothing
 * reads a session that belongs to another surface: not the CSRF check, not the sign-in
 * gate, not the operator or portal gates behind it.
 */
final class BindSessionToSurface
{
    public function __construct(private readonly SessionSurface $sessions) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->sessions->bind($request);

        return $next($request);
    }
}
