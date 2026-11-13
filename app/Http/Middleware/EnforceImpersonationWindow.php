<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\ImpersonationController;
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

        // A marker that is there but does not parse is an impersonation nobody can bound:
        // no start time to measure, no principal to restore. It ends now.
        if ($marker === null && $request->hasSession() && $request->session()->has(Impersonation::SESSION_KEY)) {
            $this->impersonation->exit($request);

            return redirect()->route('login');
        }

        if ($marker !== null && now()->getTimestamp() - $marker->startedAt > Impersonation::MAX_MINUTES * 60) {
            // Return to whichever control plane started it (exit() restores that
            // session): the env-admin console for an account member, else operator.
            $isMembership = $marker->isMembership();
            $this->impersonation->exit($request);

            $back = $isMembership
                ? ImpersonationController::backToConsole($marker->environmentKey, $request)
                : redirect()->route('platform.organizations');

            return $back->with('status', 'Impersonation session expired.');
        }

        return $next($request);
    }
}
