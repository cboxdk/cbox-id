<?php

declare(strict_types=1);

namespace App\Platform;

use App\Http\Middleware\SetEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentResolver;
use Cbox\Id\Platform\PlatformRoot;

/**
 * Which SURFACES a request's host serves, decided from the host and the environment it
 * resolved to.
 *
 * There is exactly one implementation of each question, because two would eventually
 * disagree — and the consumers are the route gate (`plane:*`, which decides whether a
 * surface exists here at all) and the post-authentication landings (which decide whether a
 * verified identity becomes an account session or a subject one). If those ever diverged,
 * a login could be admitted onto a plane whose routes are 404 — or, worse, onto one whose
 * routes are not.
 *
 * FOUR questions, not one. They used to be fewer, and the shortage is what made the
 * platform root a host with no sign-in page on it:
 *
 *  - {@see servesConsole()} — does the console live here? Everywhere, the root included.
 *  - {@see servesIssuer()} — does an identity PROVIDER answer here? Never on the root.
 *  - {@see servesEnvironmentAdmin()} — is this an environment an account administers from
 *    the outside? Never on the root.
 *  - {@see onAccountPlane()} — is this the account plane itself? The root alone.
 *
 * The second and third share an answer today and are still asked separately, because they
 * are different questions about different surfaces and a shared answer is a coincidence of
 * this deployment shape rather than a fact about either. Collapsing two questions into one
 * name is precisely how the console went missing from the root: "which host serves the
 * console" and "which host is an issuer" were one predicate, so withholding the issuer
 * surface withheld the console with it.
 */
final class PlaneResolver
{
    public function __construct(
        private readonly EnvironmentContext $environments,
        private readonly EnvironmentResolver $resolver,
    ) {}

    /**
     * The multi-tenant SaaS shape — subdomain→environment routing is configured, so
     * the account plane and the tenant planes live on separate hosts. Empty
     * `base_domains` means a single-tenant / self-hosted deployment (one forced IdP),
     * where there is no plane split at all: one host serves the whole product, and the
     * account door does not exist as a separate thing.
     */
    public function isMultiTenant(): bool
    {
        // Stated, when the deployment states it. This decides whether the host bulkheads
        // exist at all — every "does this host serve X" question below answers true
        // unconditionally in the single-tenant shape — and a security control that
        // load-bearing must not be inferred from a domain list.
        $stated = config('cbox-id.tenancy.multi_tenant');

        if (is_bool($stated)) {
            return $stated;
        }

        // Compatibility fallback for a deployment that has not stated it yet. Kept
        // deliberately, because the alternative — defaulting to single-tenant — would
        // turn the bulkheads OFF on every existing multi-tenant install at upgrade,
        // silently, which is the exact failure this change exists to prevent.
        $bases = config('cbox-id.environments.base_domains', []);

        return is_array($bases) && $bases !== [];
    }

    /**
     * Whether this request is on the ACCOUNT plane — the platform-root host of a
     * multi-tenant deployment.
     *
     * False on a single-host deployment even though the lone environment IS the
     * platform root: with no host split there is no separate account plane to land on,
     * and treating one host as both would refuse ordinary subject sign-ins there.
     */
    public function onAccountPlane(): bool
    {
        return $this->isMultiTenant() && $this->contextIsPlatformRoot();
    }

    /**
     * Whether the CONSOLE is served here — the sign-in door, and every subject-facing page
     * behind it.
     *
     * Every host this deployment answers on, the PLATFORM ROOT INCLUDED. The root is a
     * tenant like any other: people sign in there, hold a profile and a password there,
     * and administer the organizations they belong to there. What makes it the root is the
     * operator area and the account plane standing ALONGSIDE that console — not a console
     * being absent. It was absent: `/login` and `/dashboard` both 404'd on `cboxid.com`,
     * so the one host every account member already has an identity on was the one host
     * they could not sign in to as themselves.
     *
     * Asked of the HOST rather than of the resolved context, and that is the whole of the
     * safety here. {@see SetEnvironment} answers an UNMAPPED host with
     * the platform root, deliberately — so "the context is the root environment" is also
     * true of `nope.cboxid.com` and of every wildcard name pointed at us, all of which
     * `TrustHosts` admits under `base_domains`. Serving a sign-in form on the strength of
     * the context would put one on all of them. An exact match against the root's own host
     * is what those cannot satisfy.
     */
    public function servesConsole(string $host): bool
    {
        return $this->onTenantEnvironment() || $this->isPlatformRootHost($host);
    }

    /**
     * Whether this host answers AS AN IDENTITY PROVIDER — OIDC discovery and JWKS, the
     * RFC 8414 / RFC 9728 metadata, every `/oauth/*` endpoint, the SAML IdP bindings, SCIM.
     *
     * Every host EXCEPT the platform root, and that exclusion is not a consequence of the
     * root being "not a tenant" — it is one: `cboxid.com` issues tokens for nobody, signs
     * assertions for nobody and has no relying parties. It served half an IdP once —
     * discovery answered 200 there advertising an `authorization_endpoint` that 404'd —
     * and half an IdP is worse than none, because a conformant client can discover it and
     * follow it into a dead end.
     *
     * INBOUND federation is deliberately not part of this. Answering as an issuer and
     * consuming somebody else's assertion are opposite roles, and the account plane
     * genuinely does the second — see the SSO callbacks in `routes/web.php`.
     */
    public function servesIssuer(): bool
    {
        return $this->onTenantEnvironment();
    }

    /**
     * Whether the ENVIRONMENT-ADMIN door is served here — `/admin/*`, entered by redeeming
     * the account plane's signed handoff.
     *
     * The same hosts {@see servesIssuer()} names, and asked separately because it is a
     * different question that happens to share an answer. That console is how an ACCOUNT
     * reaches INTO an environment it owns from the account plane; the account plane is not
     * something it reaches into, and the platform root's own environment belongs to no
     * account, so there is nobody the door could ever open for there. The operator area is
     * how the root environment is administered.
     */
    public function servesEnvironmentAdmin(): bool
    {
        return $this->onTenantEnvironment();
    }

    /**
     * Whether the staff console may be served here — a question about the HOST, not
     * about whatever environment the operator is currently looking at.
     *
     * The distinction is the whole bug. `SetEnvironment` gives an authenticated operator
     * their PINNED environment rather than the host-resolved one, deliberately, so the
     * console does not jump planes under them. The operator gate then asked
     * `onAccountPlane()`, which compares the current context to the platform root — so
     * the moment a staff member used the environment switcher that sits in the operator
     * layout on every page, the context stopped being the root and every `/operator/*`
     * route 404'd. Including `POST /operator/logout` and `GET /operator/login`: the
     * console locked itself, and the only exit was clearing the session cookie by hand.
     * `jumpToOrganization` and creating an environment both pin as well, so those flows
     * were broken end to end.
     *
     * Resolving the host directly is what makes this immune to the operator's own
     * selection. It cannot be spoofed either: an unmapped Host resolves to null, which
     * fails closed.
     */
    public function onOperatorPlane(string $host): bool
    {
        if (! $this->isMultiTenant()) {
            return true;
        }

        return $this->isPlatformRootHost($host);
    }

    /**
     * Whether a HOST is the platform root's own host — the account plane's origin, the
     * operator area's origin, and the one host whose console {@see servesConsole()} has to
     * name explicitly because the context cannot distinguish it from an unmapped name.
     *
     * Fails closed, and the failure mode is the reason it is spelled out rather than
     * inferred: this predicate decides whether a staff sign-in form and an account
     * sign-in form are served under a given name.
     */
    public function isPlatformRootHost(string $host): bool
    {
        // NO fallback to the default environment. `platformRootKey()` resolves through
        // `defaultEnvironment()` itself, so falling back to it here would compare a value
        // to its own source and return true for every host that maps to nothing —
        // including `Host: anything.invalid`, and every wildcard name under the base
        // domain that is not a tenant slug. The first version of this method did exactly
        // that while its docblock claimed it failed closed, which is worse than the bug:
        // the staff sign-in form would be served on any name pointed at us, which is the
        // phishing surface this bulkhead exists to remove.
        $resolved = $this->resolver->resolveForHost($host)?->environmentKey();
        $root = $this->platformRootKey();

        if ($resolved !== null) {
            return $root !== null && $resolved === $root;
        }

        // A root environment provisioned without its own `domain` row does not resolve
        // by host at all, so the apex has to be named rather than inferred.
        //
        // Named by `accountHost()`, not by `platformRootHosts()` alone. The operator
        // console and the account console are the SAME origin by design — both are the
        // platform root's apex — so a host that can serve one must serve the other. They
        // did not agree: `accountHost()` resolves through a three-step chain that lands
        // in every real shape, while `platformRootHosts()` reads a config key with no env
        // binding that no deployment sets. So `cboxid.com` served `/workspace/login` and
        // 404'd `/operator/login`, and the staff console had no door at all in
        // production — silently, because a 404 on a plane bulkhead looks exactly like a
        // route that was never meant to be there.
        //
        // Still an exact match against ONE host, so the wildcard surface the branch above
        // exists to close stays closed: `anything.invalid` matches neither.
        $account = $this->accountHost();

        return ($account !== null && $host === $account)
            || in_array($host, $this->platformRootHosts(), true);
    }

    /**
     * The account host on a multi-tenant deployment, or null when the
     * deployment is single-host and there is no second origin to name.
     *
     * Signing in is unified there, so this is the host an environment console hands the
     * browser to and the one it comes back from. Three places computed it independently —
     * the admin gate, the environment layout's "open" links, and the admin login page —
     * each with a comment asserting it matched the others. That is an invariant kept by
     * hand, and the security policy now depends on it too: a `form-action` naming a
     * different host than the redirect actually targets fails as a blocked form, which is
     * exactly the bug this replaced.
     */
    public function accountHost(): ?string
    {
        // Stated first. It used to be read only as `base_domains[0]`, which conflated
        // two unrelated questions — "under which domains do we resolve a subdomain to an
        // environment" and "where is the account console". Those coincide in the
        // subdomain shape and nowhere else.
        //
        // Multi-tenancy WITHOUT subdomains is a real deployment: every tenant on its own
        // domain, resolved by exact match, `base_domains` empty. The derivation returned
        // null there — which sent the environment console's sign-in handoff to a local
        // door instead of the account plane, and dropped the account host out of the CSP
        // `form-action` list so the browser refused cross-plane logout. Both silent.
        $stated = config('cbox-id.tenancy.account_host');

        if (is_string($stated) && trim($stated) !== '') {
            return mb_strtolower(trim($stated));
        }

        // Then a host explicitly named as the platform root, which is the same fact under
        // a different name and is already configured on some deployments.
        $named = $this->platformRootHosts();

        if ($named !== []) {
            return $named[0];
        }

        // Then the original derivation, so the subdomain shape needs no new configuration.
        $bases = config('cbox-id.environments.base_domains', []);

        if (! is_array($bases) || ! isset($bases[0]) || ! is_string($bases[0])) {
            return null;
        }

        $host = mb_strtolower(trim($bases[0]));

        return $host === '' ? null : $host;
    }

    /**
     * Whether this deployment is configured coherently enough to serve the shape it
     * claims.
     *
     * Multi-tenancy needs somewhere for the account console to live. Before the mode was
     * stated it could not be incoherent — it was DERIVED from the domain list, so
     * "multi-tenant" implied a domain existed. Making the mode explicit made the
     * incoherent state reachable, and it fails as two silent behaviour changes rather
     * than as an error, so something has to name it.
     */
    public function misconfigured(): bool
    {
        return $this->isMultiTenant() && $this->accountHost() === null;
    }

    /**
     * Every host that may legitimately terminate a form submission started on another
     * plane — the account host plus any explicitly-named root, deduplicated.
     *
     * @return list<string>
     */
    public function formActionHosts(): array
    {
        $account = $this->accountHost();

        return array_values(array_unique([
            ...($account === null ? [] : [$account]),
            ...$this->platformRootHosts(),
        ]));
    }

    /**
     * Hosts that ARE the platform root, named rather than inferred.
     *
     * Configured explicitly so an unmapped Host cannot become the account plane by
     * default. Empty means "only a host that resolves to the root environment counts",
     * which is the safe reading.
     *
     * @return list<string>
     */
    public function platformRootHosts(): array
    {
        $configured = config('cbox-id.environments.platform_root_hosts', []);

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $host): string => is_string($host) ? mb_strtolower(trim($host)) : '',
            $configured,
        ), static fn (string $host): bool => $host !== ''));
    }

    /**
     * The predicate {@see servesIssuer()} and {@see servesEnvironmentAdmin()} are both
     * answered from: this request resolved to an environment, and it is not the platform
     * root's.
     *
     * A CONTEXT question, unlike {@see isPlatformRootHost()}, and correctly so — an
     * unmapped host resolves to the platform root, which is exactly the "no issuer and no
     * environment console here" this has to answer for it.
     */
    private function onTenantEnvironment(): bool
    {
        if (! $this->isMultiTenant()) {
            return true;
        }

        return $this->environments->current() !== null && ! $this->contextIsPlatformRoot();
    }

    /** Whether the environment this request RESOLVED TO is the platform root's. */
    private function contextIsPlatformRoot(): bool
    {
        $current = $this->environments->current()?->environmentKey();
        $root = $this->platformRootKey();

        return $current !== null && $root !== null && $current === $root;
    }

    /**
     * The platform-root environment key — the account plane's host.
     *
     * Resolved in the SAME order as the SetEnvironment middleware, which is what makes
     * "is this the account-root host?" agree with the environment the request actually
     * resolved to: the database `is_default` environment, and only if there is none the
     * configured default (`CBOX_ID_ENVIRONMENT_DEFAULT`) as a bootstrap fallback.
     *
     * The order matters, and it used to be the other way round here. SetEnvironment sends
     * an unmapped host to `defaultEnvironment()` — the `is_default` ROW — and only falls
     * back to the configured key when no such row exists. A deployment that set both to
     * different environments therefore had the plane gate believing the account plane
     * lived on one environment while requests resolved to another: `plane:account` would
     * 404 on the host that actually is the account root. Same source, same order, no gap.
     * {@see PlatformRoot} resolves identically.
     */
    private function platformRootKey(): ?string
    {
        $default = $this->resolver->defaultEnvironment()?->environmentKey();

        if ($default !== null) {
            return $default;
        }

        $configured = config('cbox-id.environments.default');

        return is_string($configured) && $configured !== '' ? $configured : null;
    }
}
