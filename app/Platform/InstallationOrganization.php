<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Console\ConsoleScope;
use Cbox\Id\Organization\Models\Organization;

/**
 * THE ORGANIZATION A SINGLE-TENANT INSTALL BELONGS TO — its own, as opposed to the customer
 * organizations it may also host.
 *
 * A single-tenant install has no environment console, so somebody on the organization
 * console has to be able to change the environment's own sign-in settings — passkeys,
 * magic links, sessions, SMS, the environment's social providers
 * ({@see ConsoleScope::administersEnvironment()}). It must not be "whoever administers an
 * organization": an install like that can host customers, and a customer's administrator
 * changing the sign-in methods of everybody else's people is the thing to rule out.
 *
 * NOTHING ELSE NAMED IT. The installer creates the environment and the operator but no
 * organization on this shape, so there was no record of which organization is the
 * install's own. This is that record: a flag under that organization's own `settings`
 * (`installation_own`), on at most one organization, set by whoever runs the install
 * (`php artisan cbox-id:installation-organization`).
 * Unset, nobody on an organization console holds the environment — only platform operators
 * do — which is the safe direction to be wrong in.
 */
final readonly class InstallationOrganization
{
    /** The flag under the organization's own `settings`. */
    public const SETTING = 'installation_own';

    /** The current environment's own organization, or null when none has been named. */
    public function id(): ?string
    {
        $id = Organization::query()->where('settings->'.self::SETTING, true)->orderBy('id')->value('id');

        return is_string($id) ? $id : null;
    }

    public function is(?string $organizationId): bool
    {
        return $organizationId !== null && $organizationId === $this->id();
    }

    /**
     * Name the current environment's own organization — one of its organizations — or clear
     * the flag with null. At most one carries it: naming another moves it.
     *
     * @return bool whether it was set (false for an organization this environment does not have)
     */
    public function set(?string $organizationId): bool
    {
        $target = $organizationId === null ? null : Organization::query()->find($organizationId);

        if ($organizationId !== null && $target === null) {
            return false;
        }

        foreach (Organization::query()->where('settings->'.self::SETTING, true)->get() as $flagged) {
            if ($flagged->id !== $target?->id) {
                $settings = $flagged->settings;
                unset($settings[self::SETTING]);
                $flagged->settings = $settings;
                $flagged->save();
            }
        }

        if ($target !== null) {
            $target->settings = [...($target->settings ?? []), self::SETTING => true];
            $target->save();
        }

        return true;
    }
}
