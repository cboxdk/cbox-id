<?php

declare(strict_types=1);

namespace App\Http;

use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Rate limiting for the REST management API, keyed on the CREDENTIAL rather than the
 * client IP.
 *
 * The routes used to carry a bare `throttle:240,1`, which is Laravel's default IP key.
 * Every customer whose CI egresses through one NAT — a GitHub Actions runner, a
 * corporate proxy, a k8s node pool — therefore shared a single bucket, so one noisy
 * tenant throttled all the others. That is exactly wrong for the Terraform/CLI/SDK
 * workloads this API is about to carry, whose traffic is bursty and machine-driven.
 *
 * Each plane gets its own named limiter (`api-account`, `api-environment`, `api-vault`,
 * `api-apps`) because the planes have genuinely different budgets, but they all key
 * the same way.
 *
 * ── On WHERE the key comes from ──────────────────────────────────────────────────
 * `throttle` runs BEFORE the authentication middleware in the route's stack, so the
 * resolved API key is not on the request yet when the limiter callback is evaluated.
 * Moving the throttle after authentication would fix that but leave every
 * unauthenticated request unmetered, and re-resolving the key here would mean a second
 * hashed database lookup AND a second `last_used_at` write per request.
 *
 * So {@see credential()} keys on the CREDENTIAL AS PRESENTED: a SHA-256 fingerprint of
 * the bearer token, which is one-to-one with the key row the auth middleware resolves
 * (that row is stored under exactly this hash) and costs nothing to compute. Only a
 * request presenting no credential at all falls back to the IP.
 *
 * Because a fingerprint cannot say whether the credential is VALID, a second, much
 * laxer per-IP limit rides alongside it — otherwise a flood of distinct bogus tokens
 * would mint itself a fresh bucket per request. See config/api.php.
 */
final class ApiRateLimiters
{
    /** The planes, and the config key holding each one's per-minute budget. */
    private const PLANES = ['account', 'environment', 'vault', 'apps'];

    public static function register(): void
    {
        foreach (self::PLANES as $plane) {
            RateLimiter::for(
                'api-'.$plane,
                /** @return list<Limit> */
                fn (Request $request): array => self::limits($request, $plane),
            );
        }
    }

    /**
     * @return list<Limit>
     */
    private static function limits(Request $request, string $plane): array
    {
        $budget = self::budget($plane);
        $credential = self::credential($request);
        $ip = (string) ($request->ip() ?? 'unknown');

        // The tenant's own allowance.
        $limits = [Limit::perMinute($budget)->by($credential ?? 'ip:'.$ip)];

        $multiplier = self::intConfig('api.ip_ceiling_multiplier', 10);

        // The abuse backstop. Skipped when there is no credential at all, because the
        // primary limit is already keyed on the IP in that case and a second, laxer
        // limit on the same key would be dead weight.
        if ($multiplier > 0 && $credential !== null) {
            $limits[] = Limit::perMinute($budget * $multiplier)->by('ip:'.$ip);
        }

        return $limits;
    }

    /**
     * The stable identity of the caller, or null when the request presents no
     * credential whatsoever.
     */
    private static function credential(Request $request): ?string
    {
        // Read off the REQUEST, never out of the container. AccountApiContext and
        // EnvironmentApiContext are `scoped` bindings, and a scoped binding is only
        // reset between requests under Octane — consulting them here made the bucket
        // key flip from the fingerprint on the first request to the key id on every
        // one after it, splitting a single caller across two buckets and roughly
        // doubling its real allowance.
        //
        // The vault / app-manifest planes authenticate an OAuth access token, so the
        // client is the tenant unit there. (Empty at the position the throttle actually
        // runs, but request-scoped and therefore safe if it is ever moved after auth.)
        $token = $request->attributes->get('cbox_token');

        if ($token instanceof Introspection && $token->clientId !== null) {
            return 'client:'.$token->clientId;
        }

        $bearer = $request->bearerToken();

        if (is_string($bearer) && $bearer !== '') {
            // A SHA-256 fingerprint of the presented credential. This is one-to-one with
            // the API key row the auth middleware would resolve — the keys are stored as
            // exactly this hash — so it buckets by tenant just as precisely, without a
            // second database lookup (and, more importantly, without a second
            // `last_used_at` write) on every request.
            //
            // Never the raw secret, even though Laravel hashes limiter keys again before
            // they reach the cache store.
            return 'cred:'.substr(hash('sha256', $bearer), 0, 32);
        }

        return null;
    }

    private static function budget(string $plane): int
    {
        return max(1, self::intConfig('api.rate_limits.'.$plane, 120));
    }

    private static function intConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
