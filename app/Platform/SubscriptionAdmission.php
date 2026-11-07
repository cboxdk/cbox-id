<?php

declare(strict_types=1);

namespace App\Platform;

use Illuminate\Support\Facades\Config;

/** Project provisioning is independent of access to identity security features. */
final class SubscriptionAdmission
{
    public static function required(): bool
    {
        // Preserve existing installations until they explicitly select a billing policy.
        return config('billing.require_subscription') === null
            ? config('cbox-id.entitlements.mode') === 'metered'
            : Config::boolean('billing.require_subscription');
    }
}
