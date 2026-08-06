<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Install\Contracts\PlatformInstaller;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two halves of "this deployment has not been installed yet".
 *
 * While the platform is EMPTY, every human-facing web route points at the first-run
 * screen. Without this, a fresh deployment answers its own front door with a sign-in
 * form that no credential can ever satisfy and a console with nothing in it — which
 * reads as a broken product, not an unfinished install, and sends the operator looking
 * for a bug instead of for the setup token.
 *
 * Once it is NOT empty, the first-run screen stops existing — 404, not a redirect and
 * not a permission error. This is the route's real lifetime: the screen provisions the
 * platform root, so leaving it addressable after the platform is claimed would leave the
 * highest-privilege door in the product standing open behind a single check.
 *
 * SCOPE, deliberately narrow. Only GET/HEAD requests that want HTML are redirected: a
 * signed SAML POST, an OAuth back-channel call or a JSON API request must fail as
 * themselves rather than as a 302 to a setup page no machine can read.
 */
final class PointAtFirstRun
{
    /** The first-run screen's own path — the one place the redirect must not point back at. */
    public const PATH = 'first-run';

    public function __construct(
        private readonly PlatformInstaller $installer,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $onFirstRun = $request->is(self::PATH);

        // One database question per request, and on a live deployment it stops at the
        // first operator row.
        if (! $this->installer->isEmpty()) {
            abort_if($onFirstRun, 404);

            return $next($request);
        }

        if ($onFirstRun || ! $this->pointable($request)) {
            return $next($request);
        }

        return redirect()->route('first-run');
    }

    /**
     * Whether this request is a person asking for a page.
     *
     * `livewire/*` is excluded even though it is a browser request: it is the first-run
     * screen's OWN action channel, and redirecting it would break the form that fixes
     * the condition being detected. The component re-checks emptiness and the setup
     * token on every action, so nothing is being trusted to the route alone.
     */
    private function pointable(Request $request): bool
    {
        if (! $request->isMethodSafe() || $request->expectsJson()) {
            return false;
        }

        return ! $request->is(
            'livewire/*',
            'api/*',
            '.well-known/*',
            'up',
            // Protocol front channels. They arrive in a browser and they accept HTML, but
            // they are not navigation: OIDC and SAML both specify what an endpoint
            // answers when it cannot proceed, and a 302 to a setup page is not it. A
            // `prompt=none` renewal in a hidden iframe would silently follow it and hang
            // instead of receiving `login_required`.
            'oauth/*',
            'sso/*',
        );
    }
}
