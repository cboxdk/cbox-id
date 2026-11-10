<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;

/**
 * "PEOPLE SIGN IN HERE THROUGH THAT IDENTITY PROVIDER": an environment whose sign-in page is
 * another identity provider's.
 *
 * The case it was built for is the platform root on the hosted service. Its console
 * (`app.cboxid.com`) is one of Cbox's own apps, and every other Cbox app signs in through
 * the `cbox` environment — so the root names an OpenID Connect connection to that
 * environment here, and its `/login` stops being a page and becomes a redirect to it. A
 * person already signed in to Billing or Brain arrives signed in; the app switcher's "ID"
 * is a single sign-on hop rather than a second identity.
 *
 * The connection must be the ENVIRONMENT's own (not one organization's), OpenID Connect,
 * and active. A connection that is later turned off or deleted stops the redirect rather
 * than breaking the door: the plain sign-in page comes back on its own.
 *
 * NEVER THE ONLY WAY IN. `/login/direct` is always the environment's own sign-in page,
 * with every method it has, and the redirect never runs there — so an identity provider
 * that is down cannot lock out the operators who would fix it. Nor does the redirect run
 * when the page has something to say (a refused sign-in, a provider that did not answer,
 * a form being filled in): that page renders, with the provider offered as a button.
 *
 * Stored under the environment's `settings`, like {@see SelfServiceSignup}; a missing key is
 * "off", which is every environment that existed before this.
 */
final readonly class SignInThrough
{
    /** The key under `environments.settings`. */
    public const SETTING = 'sign_in_through';

    public function __construct(
        private EnvironmentContext $environments,
        private Connections $connections,
    ) {}

    /**
     * The connection this environment's sign-in goes through, when one is named and can
     * still sign anybody in — or null, and the sign-in page is the environment's own.
     */
    public function connection(): ?Connection
    {
        $key = $this->environments->current()?->environmentKey();

        if ($key === null) {
            return null;
        }

        $id = self::configuredFor(Environment::query()->whereKey($key)->first(['id', 'settings']));

        if ($id === null) {
            return null;
        }

        $connection = $this->connections->byId($id);

        return $connection !== null && self::usable($connection) ? $connection : null;
    }

    /** The connection id named on $environment, whether or not it still works. */
    public static function configuredFor(?Environment $environment): ?string
    {
        $id = $environment?->settings[self::SETTING] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** Whether $connection can be what an environment signs in through. */
    public static function eligible(Connection $connection): bool
    {
        return $connection->organization_id === null && $connection->type === ConnectionType::Oidc;
    }

    /** Eligible, and signing people in right now. */
    public static function usable(Connection $connection): bool
    {
        return self::eligible($connection) && $connection->isActive();
    }

    /**
     * Name the connection, or clear it with null, keeping everything else under `settings`.
     *
     * @return bool whether anything changed
     */
    public function set(Environment $environment, ?string $connectionId): bool
    {
        if (self::configuredFor($environment) === $connectionId) {
            return false;
        }

        $settings = $environment->settings;

        if ($connectionId === null) {
            unset($settings[self::SETTING]);
        } else {
            $settings[self::SETTING] = $connectionId;
        }

        $environment->settings = $settings;
        $environment->save();

        return true;
    }
}
