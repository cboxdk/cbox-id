<?php

declare(strict_types=1);

namespace App\Platform\Environments;

use App\Http\Middleware\RedirectLegacyTenantHost;

/**
 * THE DOMAIN CUSTOMER ENVIRONMENTS HANG OFF: `{slug}.{base}`.
 *
 * `cbox-id.environments.base_domains` (`CBOX_ID_ENVIRONMENT_BASE_DOMAINS`, a comma list)
 * is an ORDERED list, and the order is the setting:
 *
 *  - the FIRST base is canonical. An environment without a verified custom domain has the
 *    issuer `https://{slug}.{first}`, and every address the product prints for it (the
 *    console, the API, quickstarts, mail) is built from it;
 *  - every LATER base is a legacy alias. `{slug}.{later}` still resolves to the same
 *    environment, its metadata answers with a redirect to the canonical host (the
 *    framework's `CanonicalIssuerHost`), and a browser that navigates there is sent to the
 *    canonical host ({@see RedirectLegacyTenantHost}).
 *
 * So moving every environment to a new domain is: list the new base first and keep the old
 * one behind it. Removing the old base afterwards is what stops its names resolving.
 *
 * Issuers are computed from this list, never stored: reordering it changes the `iss` of
 * every environment that has no custom domain, at once.
 */
final class TenantBaseDomain
{
    /** @return list<string> every configured base, canonical first, lower-cased */
    public static function all(): array
    {
        $configured = config('cbox-id.environments.base_domains', []);
        $bases = [];

        foreach (is_array($configured) ? $configured : [$configured] as $base) {
            if (! is_string($base)) {
                continue;
            }

            $base = ltrim(mb_strtolower(trim($base)), '.');

            if ($base !== '' && ! in_array($base, $bases, true)) {
                $bases[] = $base;
            }
        }

        return $bases;
    }

    /** The base new addresses are built from, or null on a deployment without subdomains. */
    public static function canonical(): ?string
    {
        return self::all()[0] ?? null;
    }

    /** @return list<string> the bases that only keep older addresses working */
    public static function legacy(): array
    {
        return array_slice(self::all(), 1);
    }

    /** `{slug}.{canonical base}`, or null on a deployment without subdomains. */
    public static function hostFor(string $slug): ?string
    {
        $base = self::canonical();

        return $base === null ? null : mb_strtolower($slug).'.'.$base;
    }

    /**
     * The slug a host names under a LEGACY base (`acme` for `acme.old.example`), or null:
     * the canonical base, a deeper name (`a.b.old.example`) and a foreign host name none.
     */
    public static function legacySlugOf(string $host): ?string
    {
        $host = mb_strtolower(trim($host));

        foreach (self::legacy() as $base) {
            if (! str_ends_with($host, '.'.$base)) {
                continue;
            }

            $label = substr($host, 0, -strlen('.'.$base));

            return $label !== '' && ! str_contains($label, '.') ? $label : null;
        }

        return null;
    }
}
