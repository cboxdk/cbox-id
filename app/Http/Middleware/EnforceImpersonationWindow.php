<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Time-box impersonation. Runs ahead of {@see Authenticate} on the authenticated
 * console: if an impersonation has outlived {@see Impersonation::MAX_MINUTES}, it
 * self-terminates — the subject session ends, the operator session is restored,
 * and the browser is bounced to the operator console. So a forgotten "act as"
 * cannot linger indefinitely.
 *
 * Ordering matters: this must short-circuit BEFORE Authenticate resolves the
 * subject session, otherwise Authenticate would send the now-ended subject to the
 * user login instead of returning the operator to their console.
 */
final class EnforceImpersonationWindow
{
    public function __construct(private readonly Impersonation $impersonation) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $marker = $this->impersonation->active();

        if ($marker !== null && now()->getTimestamp() - $marker->startedAt > Impersonation::MAX_MINUTES * 60) {
            // Return to whichever control plane started it (exit() restores that
            // session): the env-admin console for an account member, else operator.
            $isAccountMember = $marker->isAccountMember();
            $this->impersonation->exit($request);

            return redirect()->route($isAccountMember ? 'environment.home' : 'operator.organizations')
                ->with('status', 'Impersonation session expired.');
        }

        return $next($request);
    }
}
