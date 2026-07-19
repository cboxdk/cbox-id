<?php

declare(strict_types=1);

namespace App\Platform\Enums;

/**
 * A capability an Admin Portal setup link can configure — SSO or SCIM. Its backing
 * value doubles as the entitlement key checked against the org, so a feature is only
 * configurable when the link's {@see PortalScope} permits it AND the org is entitled.
 */
enum PortalFeature: string
{
    case Sso = 'sso';
    case Scim = 'scim';

    /** The entitlement key gating this feature for an organization. */
    public function entitlement(): string
    {
        return $this->value;
    }
}
