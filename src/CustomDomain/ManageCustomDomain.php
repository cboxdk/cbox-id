<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel\CustomDomain;

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment as EnvironmentModel;
use Cbox\Id\Whitelabel\CustomDomain\Exceptions\InvalidCustomDomain;
use Cbox\Ssrf\Contracts\UrlGuard;
use Cbox\Ssrf\Exceptions\BlockedUrl;

/**
 * Points the current environment at a custom brand domain by writing
 * `environments.domain`, which laravel-id's existing DatabaseEnvironmentResolver
 * already consults on every inbound request — so the brand is served with no new
 * routing. The host is validated for shape and then run through laravel-ssrf's
 * guard (reused, never loosened): IP literals and private/reserved/blocked hosts
 * are refused so a tenant can't alias the platform to an internal name. DNS is NOT
 * resolved here — the vanity host is resolved by the browser at request time, so a
 * domain that has not finished pointing at us can still be saved and verified later.
 *
 * This is a BRANDING/ROUTING domain, not an identity claim: laravel-id's issuer
 * resolver only adopts a custom domain as the OIDC `iss` / SAML entityID once DNS
 * control is proven via `EnvironmentDomainService` (which stamps `domain_verified_at`).
 * A domain set here therefore never asserts an issuer for a host the tenant has not
 * shown they control. It also cannot collide with another environment's domain —
 * that is refused up front (below) as well as by the column's unique constraint.
 */
class ManageCustomDomain
{
    public function __construct(
        private readonly EnvironmentContext $environment,
        private readonly UrlGuard $guard,
        private readonly bool $verifyHost = true,
    ) {}

    /**
     * Set the current environment's custom domain. Returns the normalized host.
     *
     * @throws InvalidCustomDomain
     */
    public function set(string $host): string
    {
        $host = $this->normalize($host);

        if (! $this->isWellFormed($host)) {
            throw InvalidCustomDomain::malformed($host);
        }

        if ($this->verifyHost) {
            try {
                // assertSafeRedirect blocks unsafe hosts WITHOUT resolving DNS.
                $this->guard->assertSafeRedirect('https://'.$host);
            } catch (BlockedUrl) {
                throw InvalidCustomDomain::unsafe($host);
            }
        }

        $environment = $this->currentEnvironment();

        // A domain belongs to at most one environment — refuse one already taken by
        // another (a clean error rather than the raw unique-constraint violation).
        $owner = EnvironmentModel::query()->where('domain', $host)->value('id');
        if (is_string($owner) && $owner !== $environment->id) {
            throw InvalidCustomDomain::taken($host);
        }

        $environment->forceFill(['domain' => $host])->save();

        return $host;
    }

    /** Remove the current environment's custom domain. */
    public function clear(): void
    {
        $this->currentEnvironment()->forceFill(['domain' => null])->save();
    }

    /**
     * The custom domain currently set on the environment, or null. A read for
     * display, so it never throws: no environment context, or no persisted
     * environment row, simply means "no custom domain" (not a 404).
     */
    public function current(): ?string
    {
        $environment = $this->environment->current();

        if ($environment === null) {
            return null;
        }

        // Read via getAttribute: laravel-id's Environment model does not declare a
        // `domain` @property, so a direct access would not type-check.
        $domain = EnvironmentModel::query()->find($environment->environmentKey())?->getAttribute('domain');

        return is_string($domain) && $domain !== '' ? $domain : null;
    }

    private function currentEnvironment(): EnvironmentModel
    {
        // The Environment is the boundary, not environment-owned, so this is unscoped
        // — the same lookup the resolver performs.
        return EnvironmentModel::query()->findOrFail(
            $this->environment->requireEnvironment()->environmentKey(),
        );
    }

    private function normalize(string $host): string
    {
        $host = strtolower(trim($host));

        if (str_contains($host, '://')) {
            $parsed = parse_url($host, PHP_URL_HOST);
            $host = is_string($parsed) ? $parsed : '';
        }

        return rtrim($host, '.');
    }

    /** A dotted, RFC-1123-ish hostname (at least one label + a TLD); rejects bare names and IPs. */
    private function isWellFormed(string $host): bool
    {
        if ($host === '' || strlen($host) > 253 || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        return preg_match(
            '/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/',
            $host,
        ) === 1;
    }
}
