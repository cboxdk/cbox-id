<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Console\EnvironmentConsolePath;
use App\Platform\Environments\TenantBaseDomain;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;

/**
 * THE "I'M AN ADMINISTRATOR" LINK on an environment's sign-in page.
 *
 * An environment's own host serves its end users; whoever administers the environment does
 * so in the console on the app host. An administrator who lands on the sign-in page their
 * users see had nothing there pointing at the console, so the page gets a discreet link to
 * it.
 *
 * A setting of the ENVIRONMENT (`settings.admin_sign_in_link`), with three states:
 *
 *  - unset: shown on the environment's address under a tenant base domain
 *    (`{slug}.{base}`), hidden on a custom domain. A custom domain is the vendor's own
 *    brand in front of their users, and a link to somebody else's console does not belong
 *    there unless they ask for it;
 *  - true: shown on every host of the environment;
 *  - false: hidden on every host.
 *
 * On every plan. Only ever drawn where there IS a console on another host: a multi-tenant
 * deployment with a console host that is not the host being looked at.
 */
final readonly class AdminSignInLink
{
    public const SETTING = 'admin_sign_in_link';

    public function __construct(
        private EnvironmentContext $environments,
        private PlaneResolver $planes,
        private EnvironmentConsolePath $paths,
    ) {}

    /** What the environment's administrator chose, or null when they left it to the default. */
    public static function chosenFor(?Environment $environment): ?bool
    {
        $value = $environment?->settings[self::SETTING] ?? null;

        return is_bool($value) ? $value : null;
    }

    /** Whether the link is shown for this environment on this host. */
    public static function shownFor(?Environment $environment, string $host): bool
    {
        if ($environment === null) {
            return false;
        }

        return self::chosenFor($environment) ?? self::defaultFor($environment, $host);
    }

    /**
     * The default: on for the environment's own name under a tenant base domain (the
     * canonical base or an older one), off anywhere else. The platform's own environment is
     * not served by its slug, so its default is off.
     */
    public static function defaultFor(Environment $environment, string $host): bool
    {
        if ($environment->is_default) {
            return false;
        }

        $host = mb_strtolower(trim($host));
        $slug = mb_strtolower($environment->slug);

        foreach (TenantBaseDomain::all() as $base) {
            if ($host === $slug.'.'.$base) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where the link on THIS request's sign-in page leads: the environment's console on the
     * app host. Null when the link is not shown, or there is no console elsewhere to open.
     */
    public function urlHere(string $host, string $scheme = 'https'): ?string
    {
        if (! $this->planes->isMultiTenant()) {
            return null;
        }

        $consoleHost = $this->planes->consoleHost();

        if ($consoleHost === null || strcasecmp($consoleHost, $host) === 0) {
            return null;
        }

        $key = $this->environments->current()?->environmentKey();
        $environment = $key === null ? null : Environment::query()->whereKey($key)->first(['id', 'slug', 'is_default', 'settings']);

        if (! self::shownFor($environment, $host)) {
            return null;
        }

        $console = $this->paths->for((string) $key);

        return $console === null ? null : $scheme.'://'.$consoleHost.'/'.$console;
    }

    /** Record the choice. Returns whether anything changed. */
    public function set(Environment $environment, bool $shown): bool
    {
        if (self::chosenFor($environment) === $shown) {
            return false;
        }

        $settings = $environment->settings;
        $settings[self::SETTING] = $shown;

        $environment->settings = $settings;
        $environment->save();

        return true;
    }
}
