<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Http\Middleware\RequireEnvironmentSudo;
use App\Http\Middleware\RequireSudo;
use App\Platform\EnvironmentSudo;
use App\Platform\StepUpReason;
use App\Platform\Sudo;

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
 * plane has always put it (the account API-key page's own inline step-up).
 *
 * ROTATION WAS ONLY HALF OF IT. Every credential this guards is minted twice — once when
 * the app, directory, webhook or hook is CREATED, and again when its secret is rotated —
 * and gating only the second left the first as a way around: register a new app and read
 * its secret off the confirmation rather than rotate an existing one, and the outcome is
 * the same live credential with no re-authentication anywhere in the path. So the create
 * pages call this too, and they call it TWICE: once from `mount()`, so the prompt lands
 * before an empty form rather than on top of a filled one, and once immediately before
 * the write, which is the half that actually enforces — `mount()` never runs for a
 * crafted POST to the update endpoint.
 *
 * NOT "GATE THE REVEAL INSTEAD". The tempting alternative — let anyone create, and put a
 * step-up on retrieving the plaintext afterwards — cannot be built without keeping the
 * plaintext. These secrets are stored as a SHA-256 hash or sealed and never read back;
 * making them retrievable later is a worse posture than showing them once. And the
 * plaintext is not the only prize: an environment-wide first-party client skips consent
 * in every organization, and a webhook or directory is a live data path. Creation is the
 * dangerous act, so creation is what asks.
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
     * `$reason` is required rather than optional because the default it would otherwise
     * take is the one sentence this screen must not say: a generic "this is a protected
     * action" is what teaches people to type a password without reading the page.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function challenge(string $organizationRoute, string $environmentRoute, array $parameters, string $reason): ?string
    {
        if ($this->onEnvironmentPlane()) {
            return $this->confirmed(
                $this->environment->confirmed(),
                'environment.sudo',
                $environmentRoute,
                $parameters,
                $reason,
            );
        }

        return $this->confirmed(
            $this->organization->confirmed(),
            'sudo',
            $organizationRoute,
            $parameters,
            $reason,
        );
    }

    private function onEnvironmentPlane(): bool
    {
        return $this->scope->plane() === ConsolePlane::Environment;
    }

    /**
     * The step-up route name doubles as the session prefix its own screen reads back:
     * `sudo` owns `sudo.intended`, `environment.sudo` owns `environment.sudo.intended`,
     * and the account plane spells its pair the same way. One string rather than two so
     * a page cannot be sent to one plane's screen while its intent is filed under the
     * other's — which would strand the administrator on the environment home page after
     * a confirmation that worked.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function confirmed(
        bool $open,
        string $sudo,
        string $returnRoute,
        array $parameters,
        string $reason,
    ): ?string {
        if ($open) {
            return null;
        }

        $intended = route($returnRoute, $parameters);

        session()->put($sudo.'.intended', $intended);
        StepUpReason::record($sudo, $reason, $intended);

        return $sudo;
    }
}
