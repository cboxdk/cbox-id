<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\EnvironmentAdminAuth;
use App\Platform\PlaneResolver;
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
 * A request without an admin session is NEVER shown a credential form on this tenant
 * host. Doing so would (a) invite account credentials onto a tenant-controlled host, and
 * (b) mint a session that lives ONLY here — so the moment the admin clicks "back to
 * account" on the root, they'd be forced to sign in again. Instead we bounce to the ROOT
 * console's "open environment" door: the root authenticates the account member once,
 * mints a signed single-use handoff, and SSO-redeems it back here. The root is the shadow
 * tenant that owns the one account session; every environment admin console is reached
 * THROUGH it. THIS MIDDLEWARE IS THE DOOR — there is no other.
 *
 * This used to describe a second shape: a single-host deployment where the account
 * console and this admin console share an origin, served by a local "sign in as admin"
 * form. That shape has not existed since the whole `/admin` prefix took `multi.tenant` —
 * a single-tenant install 404s the console outright, because its lone environment is the
 * platform root and belongs to no account, so nobody could ever be handed off into it.
 * The form survived the change as a fallback reachable only when
 * {@see PlaneResolver::misconfigured()}, which is to say: only when a deployment claims
 * multi-tenancy and names nowhere for the account console to live. That state already has
 * two places that name it — the first-run screen refuses to install into it, and
 * `cbox-id:doctor` reports it — so a third answer that quietly opens a credential form
 * instead was the wrong one. The form is gone; this refuses out loud.
 */
final class AuthenticateEnvironmentAdmin
{
    public function __construct(
        private readonly EnvironmentAdminAuth $auth,
        private readonly EnvironmentContext $environments,
        private readonly PlaneResolver $planes,
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
                'https://'.$root.route('environment.open', $environment->environmentKey(), false)
            );
        }

        // Nowhere to send them. Behind `plane:environment` + `multi.tenant` the environment
        // always resolves, so this is {@see PlaneResolver::misconfigured()} and nothing
        // else: multi-tenancy claimed, and no account host named anywhere. Said out loud
        // rather than answered with a local credential form — a form here would be a
        // second account-credential store on a tenant-controlled host, which is the thing
        // this gate exists to prevent, and it would make a configuration error look like
        // a working product to everyone except the person whose password it took.
        //
        // 503, not 404: the console is not absent, the deployment is unfinished, and an
        // operator reading a log needs those to be different. Nobody should meet this —
        // the first-run screen refuses to install into this state and
        // {@see \App\Platform\Health\TenancyHealthCheck} reports it — so it exists for the
        // one way in that is left: configuration changed after a good install.
        abort(503, 'This deployment is configured as multi-tenant but names no account host, so there is no console to sign in at. Set CBOX_ID_ACCOUNT_HOST.');
    }

    /**
     * @see PlaneResolver::accountHost() — one definition, because the content security
     * policy now has to name this same host and a second copy would drift from it.
     */
    private function rootHost(): ?string
    {
        return $this->planes->accountHost();
    }
}
