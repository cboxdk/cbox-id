<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Impersonation;
use App\Platform\ImpersonationCallGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SUPPORT IMPERSONATION IS READ-ONLY, and this is what makes that true.
 *
 * The guard it replaces lived at Livewire's `call` seam, and it lived there for a
 * reason: every console mutation was a component action POSTed to the single
 * `/livewire/update` endpoint, so route middleware never saw the individual action and
 * a guard placed on a route could not cover them.
 *
 * That is no longer the shape of the application. Every mutation is its own request with
 * its own verb, so the question "is this a write?" is answered by the HTTP method — which
 * is a stronger answer than an allowlist of method names, because a new endpoint is
 * refused by default rather than refused only if somebody remembered to think about it.
 * That deny-by-default property is the whole point, and it survives the move.
 *
 * GLOBAL, in the `web` group. A guard registered per route group is a guard that a future
 * route group will not carry.
 */
final class ReadOnlyWhileImpersonating
{
    /**
     * The only writes that stay available while impersonating.
     *
     * Each is a way OUT, and nothing else. Exiting has to work — it is the single control
     * that ends the session, and a supporter locked into an impersonation they cannot end
     * is a worse failure than any write this refuses. Signing out has to work for the same
     * reason: it is the other door.
     *
     * `livewire/update` is TRANSITIONAL. The pages that have not been ported still post
     * their actions there, and {@see ImpersonationCallGuard} still holds
     * that endpoint to its own allowlist. It goes when the last Volt page does, and this
     * list is then two entries.
     *
     * @var list<string>
     */
    private const ALWAYS_ALLOWED = [
        'impersonation/exit',
        'logout',
        'livewire/update',
        'livewire/update/*',
    ];

    /**
     * Methods that do not change anything.
     *
     * OPTIONS is here because a cross-origin caller sends a preflight before the request
     * it is actually making — refusing the preflight would answer a question nobody asked
     * yet, and the request it precedes is refused on its own merits a moment later.
     *
     * @var list<string>
     */
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private readonly Impersonation $impersonation) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), self::READ_METHODS, true)) {
            return $next($request);
        }

        if (! $this->impersonation->isImpersonating()) {
            return $next($request);
        }

        if ($request->is(...self::ALWAYS_ALLOWED)) {
            return $next($request);
        }

        abort(403, 'This action is not available while impersonating a user.');
    }
}
