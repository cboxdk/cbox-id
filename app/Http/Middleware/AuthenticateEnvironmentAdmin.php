<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Console\HandoffTarget;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\PlaneResolver;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Contracts\MfaMandate;
use Cbox\Id\Identity\Contracts\PasswordExpiry;
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
 *
 * THE PAGE THEY ASKED FOR SURVIVES THE TRIP. The bounce to the root carries the path that
 * was requested (`/open/{environment}?to=/admin/users`), validated as an environment-console
 * GET route here and again, signed, on redemption ({@see HandoffTarget}). So somebody who
 * opens `acme.cboxid.com/admin/users` signed out signs in at the root — with whichever of
 * the root's own sign-in methods they use: password, passkey, magic link, SSO, or the root
 * environment's social providers — and lands on Users, not on the console's front page.
 *
 * THE PLATFORM ROOT'S OWN CONSOLE is the one place this door does not bounce. There the
 * root environment is administered by the deployment's platform operators
 * ({@see EnvironmentAdminAuth::rootOperator()}), who are signed in on this very host: a
 * person with no session is sent to the root's sign-in page and brought back to the page
 * they asked for; a person who is signed in but runs nothing here is answered 404, as the
 * platform pages answer them; and an operator held by their organization's rules (a second
 * factor to enrol, a password to change, a linked identity to confirm) is sent to the
 * console that holds them, rather than walking around the hold through a second door.
 */
final class AuthenticateEnvironmentAdmin
{
    public function __construct(
        private readonly EnvironmentAdminAuth $auth,
        private readonly EnvironmentContext $environments,
        private readonly PlaneResolver $planes,
        private readonly HandoffTarget $targets,
        private readonly MfaMandate $mfaMandate,
        private readonly AdminPasswords $adminPasswords,
        private readonly PasswordExpiry $passwordExpiry,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->planes->onRootEnvironmentConsole($request->getHost())) {
            return $this->rootConsole($request, $next);
        }

        if ($this->auth->membership() !== null) {
            return $next($request);
        }

        $root = $this->rootHost();
        $environment = $this->environments->current();

        if ($root !== null && $environment !== null) {
            // Pull flow: authenticate at the root, then handoff back to this env — to the
            // page that was asked for, when it is one this console serves.
            $open = 'https://'.$root.route('environment.open', $environment->environmentKey(), false);
            $to = $request->isMethod('GET') ? $this->targets->sanitize('/'.ltrim($request->path(), '/')) : null;

            return redirect()->away($to === null || $to === '/admin'
                ? $open
                : $open.'?'.http_build_query(['to' => $to], '', '&', PHP_QUERY_RFC3986));
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
        abort(503, 'This deployment is configured as multi-tenant but names no account host, so there is no console to sign in at. Set CBOX_ID_CONSOLE_HOST.');
    }

    /**
     * The platform root's environment console: its operators, signed in on this host.
     *
     * @param  Closure(Request): Response  $next
     */
    private function rootConsole(Request $request, Closure $next): Response
    {
        $this->auth->enterRootConsole();

        $operator = $this->auth->rootOperator();

        if ($operator !== null) {
            $subjectId = (string) $operator->subject_id;

            // The holds the console's own sign-in gate applies ({@see Authenticate}), so the
            // environment console is not a way round them. The console that applies them is
            // one click away, on this same host.
            if ($this->held($subjectId)) {
                return redirect()->route('dashboard');
            }

            return $next($request);
        }

        // Signed in, and runs nothing here: the same answer the platform pages give, and for
        // the same reason — a 403 would confirm to every account holder that this host has
        // an environment console behind which the deployment's own sign-in is configured.
        abort_if($this->auth->signedInRootSubject() !== null, 404);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Your session has expired.', 'redirect' => route('login')], 401);
        }

        // Signed out: the root's own sign-in page, and back here afterwards.
        return redirect()->guest(route('login'));
    }

    /** Whether the console's sign-in gate would hold this person on its own pages first. */
    private function held(string $subjectId): bool
    {
        return $this->adminPasswords->requiresChange($subjectId)
            || $this->passwordExpiry->hasExpired($subjectId)
            || $this->mfaMandate->requiresEnrolment($subjectId);
    }

    /**
     * @see PlaneResolver::consoleHost() — one definition, because the content security
     * policy now has to name this same host and a second copy would drift from it.
     */
    private function rootHost(): ?string
    {
        return $this->planes->consoleHost();
    }
}
