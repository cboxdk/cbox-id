<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defense-in-depth: refuse credential- and factor-mutating actions while an
 * operator is impersonating a subject. An impersonator must never be able to plant
 * persistence in the subject's account (a passkey, a linked social provider, a new
 * password) or clear the step-up gate that guards those.
 *
 * Most of these routes are already sudo-gated, and an impersonator does not know
 * the subject's password — but blocking explicitly turns "they probably can't"
 * into a provable, tested property. Applied ahead of the sudo guard so the refusal
 * is an unambiguous 403 rather than a step-up redirect.
 */
final class BlockDuringImpersonation
{
    public function __construct(private readonly Impersonation $impersonation) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($this->impersonation->isImpersonating(), 403, 'This action is not available while impersonating a user.');

        return $next($request);
    }
}
