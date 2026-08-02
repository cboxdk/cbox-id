<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentResolver;
use Cbox\Id\Platform\PlatformRoot;

/**
 * Which PLANE a request is on, decided from the host-resolved environment alone.
 *
 * There is exactly one implementation of this question, because two would eventually
 * disagree — and the two consumers are the route gate (`plane:account`, which decides
 * whether a surface exists here at all) and the post-authentication landings (which
 * decide whether a verified identity becomes an account session or a subject one). If
 * those ever diverged, a login could be admitted onto a plane whose routes are 404 — or,
 * worse, onto one whose routes are not.
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
        return $this->isMultiTenant() && $this->onPlatformRootHost();
    }

    /** Whether this request is on a TENANT host — the subject plane in the SaaS shape. */
    public function onSubjectPlane(): bool
    {
        if (! $this->isMultiTenant()) {
            return true;
        }

        return $this->environments->current() !== null && ! $this->onPlatformRootHost();
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

        $resolved = $this->resolver->resolveForHost($host)?->environmentKey()
            ?? $this->resolver->defaultEnvironment()?->environmentKey();

        $root = $this->platformRootKey();

        return $resolved !== null && $root !== null && $resolved === $root;
    }

    private function onPlatformRootHost(): bool
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
