<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for a tenant environment's ADMIN console. Access requires an environment-admin
 * session — an ACCOUNT-layer member ({@see EnvironmentAdminAuth}) who holds access to
 * THIS environment. A subject (end-user) session grants nothing here, and vice versa:
 * the admin of a tenant is never a subject inside it.
 *
 * On a MULTI-TENANT deployment (base domains configured), a request without an admin
 * session is NOT shown a credential form on this tenant host. Doing so would (a) invite
 * account credentials onto a tenant-controlled host, and (b) mint a session that lives
 * ONLY here — so the moment the admin clicks "back to account" on the root, they'd be
 * forced to sign in again. Instead we bounce to the ROOT workspace's "open environment"
 * door: the root authenticates the account member once, mints a signed single-use
 * handoff, and SSO-redeems it back here. The root is the shadow tenant that owns the
 * one account session; every environment admin console is reached THROUGH it.
 *
 * On a single-host deployment (no base domains — self-hosted single tenant) the account
 * console and this admin console share an origin, so the local "sign in as admin" door
 * is used instead.
 */
final class AuthenticateEnvironmentAdmin
{
    public function __construct(
        private readonly EnvironmentAdminAuth $auth,
        private readonly EnvironmentContext $environments,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->auth->current() !== null) {
            return $next($request);
        }

        $root = $this->rootHost();
        $environment = $this->environments->current();

        if ($root !== null && $environment !== null) {
            // Pull flow: authenticate at the root, then handoff back to this env.
            return redirect()->away(
                'https://'.$root.route('workspace.environment.open', $environment->environmentKey(), false)
            );
        }

        // Single-host deployment — the local admin door is same-origin, so it's safe.
        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()->route('admin.login');
    }

    /**
     * The account/workspace console host on a multi-tenant deployment, or null when the
     * deployment is single-host (no base domains). Matches the host the environment
     * console's own "back to account" links resolve to.
     */
    private function rootHost(): ?string
    {
        $bases = config('cbox-id.environments.base_domains', []);

        return is_array($bases) && isset($bases[0]) && is_string($bases[0]) && $bases[0] !== ''
            ? $bases[0]
            : null;
    }
}
