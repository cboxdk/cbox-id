<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;

/**
 * THE ENVIRONMENT'S OWN "LET PEOPLE SIGN THEMSELVES UP" SWITCH, on the multi-tenant shape.
 *
 * A vendor running their app on a Cbox ID environment decides whether a stranger may
 * create an account there — and with it their own organization — or whether every person
 * arrives by invitation. That is the vendor's decision, not the platform operator's, so it
 * is a setting on the environment rather than the deployment-wide `cbox-id.signup.mode`
 * (which on the SaaS shape governs who may buy an identity platform at the root).
 *
 * OFF UNLESS SOMEBODY TURNED IT ON. Stored under the environment's `settings`, and a missing
 * key reads as off — so every environment that existed before the switch keeps exactly the
 * door it had, and a new one opens only when an administrator says so.
 *
 * The decision of what the switch MEANS on each shape lives in {@see SignupPolicy}; this
 * class only stores it.
 */
final readonly class SelfServiceSignup
{
    /** The key under `environments.settings`. */
    public const SETTING = 'self_service_signup';

    public function __construct(private EnvironmentContext $environments) {}

    /** Whether the environment this request stands in has switched it on. */
    public function enabledHere(): bool
    {
        $key = $this->environments->current()?->environmentKey();

        if ($key === null) {
            return false;
        }

        return self::enabledFor(Environment::query()->whereKey($key)->first(['id', 'settings']));
    }

    public static function enabledFor(?Environment $environment): bool
    {
        return $environment !== null && ($environment->settings[self::SETTING] ?? false) === true;
    }

    /**
     * Switch it on or off, keeping everything else under `settings` (the environment's
     * default theme lives there too).
     *
     * @return bool whether anything changed
     */
    public function set(Environment $environment, bool $enabled): bool
    {
        if (self::enabledFor($environment) === $enabled) {
            return false;
        }

        $settings = $environment->settings;
        $settings[self::SETTING] = $enabled;

        $environment->settings = $settings;
        $environment->save();

        return true;
    }
}
