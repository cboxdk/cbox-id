<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel\CustomDomain;

use App\Platform\TrustedHosts;
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
 * shown they control, and — because it leaves `domain_verified_at` null — is not added
 * to the trusted-Host allow-list either ({@see TrustedHosts}).
 *
 * TWO KINDS OF COLLISION, and this used to fence only one. An exact clash with another
 * environment's `domain` is refused below and again by the column's unique constraint.
 * A host under one of the deployment's own `base_domains` is the other kind, and it is
 * not a clash at all in column terms: `acme.cboxid.com` is how the `acme` environment is
 * addressed, yet it is nobody's `domain` value, so it read as free. Writing it would have
 * taken that tenant over, because host resolution matches `domain` before it resolves a
 * slug. It is now refused, as the verified-domain path has always refused it.
 *
 * NOTHING CURRENTLY CALLS THIS. It is bound and tested but has no route, controller or
 * console page, and the reservation above is why it is fenced rather than deleted: a
 * dormant writer to a routing-critical column should be safe before it is convenient.
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

        // A host under one of the deployment's OWN base domains is never a tenant's to
        // claim, and the uniqueness check below does not catch it: `acme.cboxid.com` is
        // how the `acme` environment is addressed, but it is nobody's `domain` column, so
        // it reads as free. Writing it here would hijack that tenant outright —
        // {@see \Cbox\Id\Organization\DatabaseEnvironmentResolver} matches `domain` BEFORE
        // it resolves a slug, so the victim's own subdomain would start serving the
        // claimant's environment. {@see \Cbox\Id\Organization\EnvironmentDomainService::assertUsable()}
        // has always enforced this reservation on the verified-domain path; this one was
        // written without it.
        $this->assertNotReserved($host);

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

    /**
     * Refuse the apex of any configured base domain, and anything under it.
     *
     * @throws InvalidCustomDomain
     */
    private function assertNotReserved(string $host): void
    {
        $configured = config('cbox-id.environments.base_domains', []);

        if (! is_array($configured)) {
            return;
        }

        foreach ($configured as $base) {
            if (! is_string($base)) {
                continue;
            }

            $base = ltrim(mb_strtolower(trim($base)), '.');

            if ($base !== '' && ($host === $base || str_ends_with($host, '.'.$base))) {
                throw InvalidCustomDomain::reserved($host);
            }
        }
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
