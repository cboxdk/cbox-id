<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Platform\CurrentUser;
use App\Platform\Organizations\OrganizationRequired;

/**
 * At the platform root an organization opens on its home (people, sign-in, billing).
 * Elsewhere the first usable page follows the current native app permissions.
 * Reading environments opens Identity platforms, financial access opens Billing, and a
 * person without product access retains their own account. This also keeps a
 * support session within the impersonated person's actual access.
 */
final readonly class OrganizationLanding
{
    public function __construct(private ConsoleScope $scope) {}

    public function url(): string
    {
        // A Cbox organization lands on its home: its people, sign-in and bill first, and
        // identity platforms as a card on it. Only for somebody the home has something to
        // show; a Viewer with no product access keeps their own account, as before.
        $capabilities = $this->scope->capabilities();
        $reachesAny = $capabilities !== null && ($capabilities->canReadEnvironments() || $capabilities->canReadMembers()
            || ($capabilities->canReadBilling() && config('billing.enabled') === true));

        if ($reachesAny && $this->scope->atOrganizationAltitude()) {
            return route('orgs.overview');
        }

        if ($capabilities?->canReadEnvironments() === true) {
            return route('orgs.projects');
        }
        if ($capabilities?->canReadBilling() === true && config('billing.enabled') === true) {
            return route('orgs.settings.billing', ['organization' => $this->scope->organizationId()]);
        }

        // No product access is a person's own account; no ORGANIZATION at all, in the
        // Cbox realm, is the screen that gives them one.
        $required = app(OrganizationRequired::class);

        return $required->appliesTo(app(CurrentUser::class)) ? $required->url() : route('account');
    }
}
