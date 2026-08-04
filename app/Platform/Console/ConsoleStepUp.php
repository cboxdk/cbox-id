<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Http\Middleware\RequireEnvironmentSudo;
use App\Http\Middleware\RequireSudo;
use App\Platform\EnvironmentSudo;
use App\Platform\Sudo;
use App\Platform\WorkspaceSudo;

/**
 * The step-up a CONSOLE component must clear before it mints or reveals a credential —
 * resolved against the plane the session is actually on.
 *
 * WHY A COMPONENT-LEVEL CHECK AT ALL. `env.sudo` and `sudo` are route middleware, and
 * route middleware gates a PAGE. That is the right shape for the token vault, which is
 * nothing but secrets — but the pages this guards are ordinary detail pages (an
 * application's name and redirect URIs, a directory's mappings, a webhook's endpoint)
 * with one dangerous button on them. Gating the page would demand a password to read
 * a redirect URI, so the gate belongs on the ACTION, which is also where the account
 * plane has always put it ({@see WorkspaceSudo} via `api-keys`).
 *
 * WHY IT IS PLANE-AWARE. These components are MERGED: one file serves the organization
 * plane and the environment plane, and the two answer to different authorities. A
 * confirmation made as an organization member must never satisfy the environment
 * administrator's gate — that is the whole reason {@see EnvironmentSudo} is a separate
 * session key from {@see Sudo} rather than a shared one — so the component cannot simply
 * pick a store, and every component picking for itself is four chances to pick wrong.
 *
 * WHAT IT DOES NOT REPLACE. The two middlewares ({@see RequireSudo},
 * {@see RequireEnvironmentSudo}) still guard the vault's pages and still re-run on every
 * Livewire action, because they are registered persistent. This is the same gate expressed
 * where a page-level one would be too blunt, not a softer one.
 */
final class ConsoleStepUp
{
    public function __construct(
        private readonly ConsoleScope $scope,
        private readonly Sudo $organization,
        private readonly EnvironmentSudo $environment,
    ) {}

    /**
     * The step-up route this action must clear first, or null when the window is already
     * open. A non-null answer has ALREADY recorded where to return to.
     *
     * The caller names the page under both of its route names, because the merged
     * components are reached under a different one on each plane and only the component
     * knows which pair it belongs to. It cannot be read off the request instead: a
     * component action arrives as a POST to `/livewire/update`, so `url()->current()` here
     * would send the administrator back to Livewire's own endpoint.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function challenge(string $organizationRoute, string $environmentRoute, array $parameters = []): ?string
    {
        if ($this->onEnvironmentPlane()) {
            return $this->confirmed(
                $this->environment->confirmed(),
                'environment.sudo',
                'environment.sudo.intended',
                $environmentRoute,
                $parameters,
            );
        }

        return $this->confirmed(
            $this->organization->confirmed(),
            'sudo',
            'sudo.intended',
            $organizationRoute,
            $parameters,
        );
    }

    private function onEnvironmentPlane(): bool
    {
        return $this->scope->plane() === ConsolePlane::Environment;
    }

    /**
     * The intended-URL keys are spelled to match `auth/sudo` and `environment/sudo`, which
     * own the other half of this handshake and pull from them by name.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function confirmed(
        bool $open,
        string $sudoRoute,
        string $intendedKey,
        string $returnRoute,
        array $parameters,
    ): ?string {
        if ($open) {
            return null;
        }

        session()->put($intendedKey, route($returnRoute, $parameters));

        return $sudoRoute;
    }
}
