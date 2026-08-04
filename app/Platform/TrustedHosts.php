<?php

declare(strict_types=1);

namespace App\Platform;

use App\Http\Middleware\SetEnvironment;
use Cbox\Id\Organization\EnvironmentIssuerResolver;
use Cbox\Id\Organization\EnvironmentResolutionCache;
use Cbox\Id\Organization\Models\Environment;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The Host headers this deployment answers on, as the regex patterns Laravel's
 * {@see TrustHosts} hands to Symfony.
 *
 * WHY THIS EXISTS. Nothing registered `TrustHosts`, so `Host:` was whatever the client
 * said — and {@see SetEnvironment} answers an unmapped host with the
 * PLATFORM ROOT rather than a refusal (host hardening is punted to the ingress there, on
 * purpose: which hosts serve the console, the apex and signup varies per deployment). The
 * two together mean every account-plane surface renders under any name pointed at us,
 * which is how a password-reset link was minted on an attacker's domain.
 *
 * DERIVED, never a hardcoded list. The set is exactly the four things a deployment
 * already states about itself:
 *
 *  - the hosts {@see PlaneResolver::formActionHosts()} already names — the account host
 *    and any explicit platform root. That method exists because the CSP `form-action`
 *    list asks the same question this does, and two answers would drift;
 *  - `base_domains`, apex AND every subdomain, because `{slug}.{base}` IS how a tenant
 *    plane is reached in the SaaS shape;
 *  - every custom domain an environment answers on, which are not enumerable from config
 *    at all — a tenant on `id.customer.example` would otherwise get a 400 on every
 *    request, which is a worse outage than the bug;
 *  - `app.url` and its subdomains, added by `TrustHosts` itself (`subdomains: true`), so
 *    a single-tenant deployment with no `base_domains` and no account host still trusts
 *    the one host it serves. That is why this returning `[]` must stay safe.
 */
final class TrustedHosts
{
    /**
     * Public so the invalidating caller can name it rather than reproduce it.
     *
     * {@see PlatformServiceProvider} forgets this on every `Environment` write, which is
     * the only thing that can change the answer. It shipped with no invalidation at all:
     * the key appeared exactly once in the codebase, and `Environment::booted()` forgets
     * the {@see EnvironmentResolutionCache} keys, which are different keys. So a tenant
     * that verified a custom domain was UNTRUSTED — 400 on every request to the host they
     * had just been told was live — until the TTL lapsed, and a removed domain went on
     * being trusted for just as long.
     */
    public const CACHE_KEY = 'cbox-id:trusted-hosts:domains';

    public function __construct(private readonly PlaneResolver $planes) {}

    /**
     * Drop the cached domain list.
     *
     * Called from the model event rather than from the three services that write a domain
     * ({@see EnvironmentDomainService::verify()}, `::clear()`, and the environment admin's
     * own edit), because a fourth writer added later cannot forget what it never had to
     * remember — the same argument `Environment::booted()` makes for the resolution cache.
     */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return list<string>
     */
    public function patterns(): array
    {
        $patterns = [];

        foreach ($this->planes->formActionHosts() as $host) {
            $patterns[] = $this->exactly($host);
        }

        foreach ($this->baseDomains() as $base) {
            // The apex and any label under it. `preg_quote` is not optional: Symfony
            // matches these UNANCHORED as `{pattern}i`, so a bare `cboxid.com` would also
            // match `cboxid-com.attacker.example` and trust the very header we are fencing.
            $patterns[] = '^(.+\.)?'.preg_quote($base).'$';
        }

        foreach ($this->customDomains() as $domain) {
            $patterns[] = $this->exactly($domain);
        }

        return array_values(array_unique($patterns));
    }

    private function exactly(string $host): string
    {
        return '^'.preg_quote($host).'$';
    }

    /**
     * @return list<string>
     */
    private function baseDomains(): array
    {
        $configured = config('cbox-id.environments.base_domains', []);

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $base): string => is_string($base) ? ltrim(mb_strtolower(trim($base)), '.') : '',
            $configured,
        ), static fn (string $base): bool => $base !== ''));
    }

    /**
     * The verified custom domains, cached for the same window host→environment resolution
     * is ({@see EnvironmentResolutionCache}), because this now runs before EVERY request
     * rather than only before one that has to resolve a plane.
     *
     * Failure is answered with an EMPTY list rather than an exception. This is read on the
     * way in to every request, including the ones an operator would use to fix the
     * database it reads from; a deployment whose `environments` table is unreachable (a
     * pending migration, a failover) must degrade to trusting `app.url` alone, not answer
     * 400 to everything.
     *
     * @return list<string>
     */
    private function customDomains(): array
    {
        $ttl = config('cbox-id.environments.resolution_cache_ttl', EnvironmentResolutionCache::DEFAULT_TTL);
        $ttl = is_int($ttl) ? $ttl : EnvironmentResolutionCache::DEFAULT_TTL;

        try {
            return $ttl > 0
                ? Cache::remember(self::CACHE_KEY, $ttl, $this->readCustomDomains(...))
                : $this->readCustomDomains();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * VERIFIED domains only — which is what the docblock above and `bootstrap/app.php`
     * both already claimed, and what the query did not do.
     *
     * `domain` alone is a value somebody typed into a form; `domain_verified_at` is the
     * DNS challenge answered. Trusting the unverified column makes this control derive
     * its allow-list from unproven input, which is the shape of the bug it was written to
     * close — and {@see EnvironmentIssuerResolver} already refuses an
     * unverified domain as an issuer for the same reason.
     *
     * @return list<string>
     */
    private function readCustomDomains(): array
    {
        return array_values(array_filter(
            Environment::query()
                ->whereNotNull('domain')
                ->where('domain', '!=', '')
                ->whereNotNull('domain_verified_at')
                ->pluck('domain')
                ->all(),
            is_string(...),
        ));
    }
}
