<?php

declare(strict_types=1);

namespace App\Platform\Console;

/**
 * The first usable workspace page follows the current native app permissions.
 * Reading environments opens Projects, financial access opens Billing, and a
 * person without product access retains their own account. This also keeps a
 * support session within the impersonated person's actual access.
 */
final readonly class WorkspaceLanding
{
    public function __construct(private ConsoleScope $scope) {}

    public function url(): string
    {
        $capabilities = $this->scope->capabilities();
        if ($capabilities?->canReadEnvironments() === true) {
            return route('projects');
        }
        if ($capabilities?->canReadBilling() === true && config('billing.enabled') === true) {
            return route('billing', ['organization' => $this->scope->organizationId()]);
        }

        return route('account');
    }
}
