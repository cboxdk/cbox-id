<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Platform\CurrentUser;
use App\Platform\Organizations\OrganizationRequired;

/**
 * The first usable workspace page follows the current native app permissions.
 * Reading environments opens Projects, financial access opens Billing, and a
 * person without product access retains their own account. This also keeps a
 * support session within the impersonated person's actual access.
 */
final readonly class OrganizationLanding
{
    public function __construct(private ConsoleScope $scope) {}

    public function url(): string
    {
        $capabilities = $this->scope->capabilities();
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
