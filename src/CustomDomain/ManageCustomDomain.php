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
 */
final class ManageCustomDomain
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

        $this->currentEnvironment()->forceFill(['domain' => $host])->save();

        return $host;
    }

    /** Remove the current environment's custom domain. */
    public function clear(): void
    {
        $this->currentEnvironment()->forceFill(['domain' => null])->save();
    }

    /** The custom domain currently set on the environment, or null. */
    public function current(): ?string
    {
        // Read via getAttribute: laravel-id's Environment model does not declare a
        // `domain` @property, so a direct access would not type-check.
        $domain = $this->currentEnvironment()->getAttribute('domain');

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
