<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\OAuth\PendingAuthorizations;
use App\Platform\PlaneResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which SURFACES exist on this host, enforced with a 404 — the wrong plane on a host does
 * not merely refuse, it is absent:
 *
 *  - `plane:signup` — `/signup`. On the platform root (cboxid.com) it is "buy an identity
 *    platform"; on a tenant host it is that environment's own self-service sign-up, open
 *    only while the environment's switch is on (SignupPolicy decides that, not this gate).
 *    It was `plane:account` — root only — which is why every tenant's sign-in page linked
 *    to a 404. `account` had no other route left, so the name went with it.
 *  - `plane:console` — the sign-in door and the subject console behind it. Served on
 *    EVERY host this deployment answers on, the platform root included: the root is a
 *    tenant whose subjects sign in and administer their organizations exactly as any
 *    other tenant's do.
 *  - `plane:issuer` — the identity-provider protocol surface: discovery, JWKS,
 *    `/oauth/*`, the SAML IdP bindings, SCIM. Served on every host EXCEPT the platform
 *    root, which is an issuer for nobody.
 *  - `plane:keys` — `/.well-known/jwks.json`. Served wherever tokens are issued, which
 *    includes the platform root: a host that signs a token and withholds the key to
 *    verify it has shipped half an IdP. Public key material discloses nothing.
 *  - `plane:first-party` — the three token endpoints (`/oauth/authorize`, `/oauth/token`,
 *    `/oauth/revoke`), which is `issuer` plus one narrow exception: on the platform root
 *    they are served for a PLATFORM-OWNED first-party client and refused for every other.
 *    The root holds subjects who sign in and enrol authenticators, so it must be able to
 *    issue them tokens for the binary we ship — without becoming an issuer for the OAuth
 *    clients an organization admin can create there.
 *  - `plane:environment` — the environment-admin console under `/admin`, reached by
 *    redeeming the account plane's signed handoff. Never on the account plane itself.
 *
 * `console` and `issuer` were ONE plane, named `subject`, and the conflation is what left
 * the platform root with no sign-in page: the root must not answer as an identity
 * provider, one gate said "not the root", and `/login` and `/dashboard` went with it. The
 * plane was renamed rather than redefined on purpose — a route left spelling the old name
 * falls to `default => false` here and 404s loudly, instead of silently acquiring the
 * wider meaning.
 *
 * A session still carries no weight where its surface is absent: an account session gets
 * nothing on a tenant host, and an env-admin session nothing on the account plane.
 * Deny-by-default throughout — an unmapped host resolves to the platform root, so it is
 * refused by the same rules the root is.
 */
final class EnforcePlane
{
    /**
     * Every plane name that IS a name, listed once.
     *
     * The single-tenant branch below admits any of them and the match arms decide the
     * rest, so the two had to agree on what a plane is — and they did not while the list
     * was written out by hand in one of the two places. One constant, no drift.
     *
     * @var list<string>
     */
    private const PLANES = ['signup', 'console', 'issuer', 'first-party', 'keys', 'environment', 'operator'];

    /**
     * Where a client identifier is found on the endpoints carrying `plane:first-party`.
     *
     * `/oauth/authorize` takes it in the query string; `/oauth/token` and `/oauth/revoke`
     * take it in the form body — the authenticator is a PUBLIC client, so it never
     * authenticates with a Basic header and `client_id` is always stated outright.
     * `$request->input()` reads both, which is why this is one lookup rather than three.
     */
    private const CLIENT_ID = 'client_id';

    /**
     * The route parameter that names a PENDING authorization rather than a client: the
     * consent screen, its two answers and the hosted organization steps.
     */
    private const PENDING_AUTHORIZATION = 'authorization';

    public function __construct(
        private readonly PlaneResolver $planes,
        private readonly PendingAuthorizations $pending = new PendingAuthorizations,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $plane): Response
    {
        // Single-tenant / self-hosted is ONE host serving the whole product — there is no
        // account/tenant host split, so the bulkheads don't apply and every plane is
        // served. Only the multi-tenant SaaS shape has a platform root distinct from the
        // tenant hosts under it.
        if (! $this->planes->isMultiTenant()) {
            // …but an unknown plane name is still refused, in every shape.
            abort_unless(in_array($plane, self::PLANES, true), 404);

            return $next($request);
        }

        $allowed = match ($plane) {
            // Signup: the platform root's "buy an identity platform", and on a tenant
            // environment the vendor's own self-service sign-up. The page is served on both;
            // whether a tenant's is OPEN is the environment's switch, which SignupPolicy
            // reads — a host question here, a setting question there.
            'signup' => $this->planes->servesSignup(),
            // A HOST question — the only one of the four that has to be, because
            // SetEnvironment answers an unmapped name with the platform root, so the
            // CONTEXT cannot tell `cboxid.com` from `anything.invalid`. Serving a sign-in
            // form on the strength of the context would serve one under every name
            // pointed at us. See PlaneResolver::servesConsole().
            'console' => $this->planes->servesConsole($request->getHost()),
            // The issuer surface, which is what `plane:subject` actually enforced. The
            // platform root is not an identity provider, and this is the wall that says
            // so — the app's own /oauth/authorize and SAML IdP overrides included, or
            // they would be the holes left in it.
            'issuer' => $this->planes->servesIssuer(),
            // The token endpoints, which the platform root serves for the binary we ship
            // and for nothing else. See PlaneResolver::servesFirstPartyIssuer() for why
            // the root needs them at all and why the wall above stays exactly where it is.
            // Public verification keys, served wherever this deployment issues tokens —
            // which now includes the platform root. See servesVerificationKeys().
            'keys' => $this->planes->servesVerificationKeys(),
            'first-party' => $this->planes->servesFirstPartyIssuer($this->clientIdOf($request)),
            // The environment-admin console. Asked as its own question rather than
            // borrowed from `issuer`: same answer today, different reason, and a shared
            // name is how two surfaces end up moving together when only one should.
            'environment' => $this->planes->servesEnvironmentAdmin(),
            // The staff console. It shipped with no bulkhead at all, so the Cbox
            // operator sign-in was served on every tenant subdomain AND on every
            // customer-controlled brand domain (whitelabel writes environments.domain,
            // which is what host resolution keys on). No privilege was granted there —
            // AuthenticateOperator guards the pages themselves — but a staff login form
            // on a customer's own domain is a phishing surface and an unnecessary
            // disclosure. It belongs on the platform root, with the account plane.
            //
            // Nothing carries `plane:operator` today: the staff pages became a SECTION of
            // the one console (`/platform`), which asks who you are rather than which host
            // you are on, and the separate operator door they needed a bulkhead for is
            // gone. Kept because the plane name is still a name a route may take, and
            // because it is still the answer for the question "may a staff sign-in be
            // served here" if one is ever served again.
            // A HOST question, not a context question — see PlaneResolver::onOperatorPlane().
            // Asking onAccountPlane() here meant an operator who used the environment
            // switcher 404'd out of the entire staff console, logout included.
            'operator' => $this->planes->onOperatorPlane($request->getHost()),
            default => false,
        };

        abort_unless($allowed, 404);

        return $next($request);
    }

    /**
     * The client a `plane:first-party` request is for.
     *
     * `/oauth/authorize` and the token endpoints NAME it (`client_id`). The steps that follow
     * the authorize request — the consent screen, approve and deny, the hosted organization
     * picker and "create a team" — name only the PENDING authorization this session holds
     * under an opaque id, so on the platform root they asked about client `''` and 404'd
     * for our own first-party app, the one client the root serves.
     *
     * For those, the client is the pending authorization's — and ONLY that: a `client_id`
     * posted alongside is ignored, so a step cannot be admitted on another client's name.
     * No guard is loosened by this. A pending authorization is written only by
     * `/oauth/authorize`, after THIS gate admitted its client, and it is read back from the
     * same session and asked the same question again; an id this session does not hold
     * names no client and is refused as before.
     */
    private function clientIdOf(Request $request): string
    {
        $route = $request->route();

        if ($route instanceof Route && $route->hasParameter(self::PENDING_AUTHORIZATION)) {
            $id = $route->parameter(self::PENDING_AUTHORIZATION);

            return is_string($id) && $request->hasSession()
                ? ($this->pending->find($request, $id)->clientId ?? '')
                : '';
        }

        return is_string($id = $request->input(self::CLIENT_ID)) ? $id : '';
    }
}
