<?php

declare(strict_types=1);

namespace App\Platform\ServiceAccess;

use App\Platform\Actions\ActionRefused;
use Cbox\Id\Platform\PlatformRoot;

/** This gate applies to buying the ID product, never to a tenant's identity users. */
final readonly class ProductAdmission
{
    public function __construct(private BillingWaitlists $billing, private PlatformRoot $root) {}

    public function enabled(): bool
    {
        return ! app()->environment(['local', 'testing']) || config('service-access.product_enabled') === true;
    }

    public function assertWebGranted(string $subject, string $organization): void
    {
        try {
            $this->assertGranted($subject, $organization);
        } catch (ActionRefused $error) {
            abort($error->status, $error->getMessage());
        }
    }

    public function assertGranted(string $subject, string $organization): void
    {
        if (! $this->enabled()) {
            return;
        }
        try {
            $allowed = $this->root->run(fn (): bool => $this->billing->productAllowed($subject, $organization));
        } catch (ServiceAccessUnavailable) {
            throw new ActionRefused('service_admission_unavailable', __('service_access.unavailable'), 503);
        }
        if ($allowed === null) {
            throw new ActionRefused('service_admission_unavailable', __('service_access.unavailable'), 503);
        }
        if (! $allowed) {
            throw new ActionRefused('service_admission_required', __('registration.access_required'), 403);
        }
    }
}
