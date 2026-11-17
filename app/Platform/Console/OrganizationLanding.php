<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Platform\Actions\ActionRefused;
use App\Platform\CurrentUser;
use App\Platform\Organizations\OrganizationRequired;
use App\Platform\ServiceAccess\ProductAdmission;

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
            // Projects are the ID product (identity platforms for the organization's own
            // SaaS), behind admission; administering the organization itself is not.
            return $this->admitted() ? route('orgs.projects') : route('orgs.settings.members');
        }
        if ($capabilities?->canReadBilling() === true && config('billing.enabled') === true) {
            return route('orgs.settings.billing', ['organization' => $this->scope->organizationId()]);
        }

        // No product access is a person's own account; no ORGANIZATION at all, in the
        // Cbox realm, is the screen that gives them one.
        $required = app(OrganizationRequired::class);

        return $required->appliesTo(app(CurrentUser::class)) ? $required->url() : route('account');
    }

    private function admitted(): bool
    {
        $organization = $this->scope->organizationId();
        if ($organization === null) {
            return true;
        }
        try {
            app(ProductAdmission::class)->assertGranted($this->scope->actorId(), $organization);

            return true;
        } catch (ActionRefused) {
            return false;
        }
    }
}
