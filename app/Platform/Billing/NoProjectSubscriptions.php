<?php

declare(strict_types=1);

namespace App\Platform\Billing;

/** A deployment without the billing module: no project has a plan to cancel. */
final readonly class NoProjectSubscriptions implements ProjectSubscriptions
{
    public function renews(string $organizationId, string $projectId): bool
    {
        return false;
    }
}
