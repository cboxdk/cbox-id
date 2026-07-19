<?php

declare(strict_types=1);

namespace App\Platform\Enums;

/**
 * What a single-use Admin Portal setup link is permitted to configure. Backed by the
 * exact strings persisted on the framework's portal-link `scope` column, so it maps
 * to and from storage with `->value` / `tryFrom()` and needs no migration. A link is
 * scoped to SSO, to SCIM, or to both. Deny-by-default: an unrecognized stored value
 * reads as null (no scope), never a trusted one.
 */
enum PortalScope: string
{
    case Sso = 'sso';
    case Scim = 'scim';
    case Both = 'both';

    /**
     * Whether this scope permits configuring the given feature.
     */
    public function permits(PortalFeature $feature): bool
    {
        return $this === self::Both || $this->value === $feature->value;
    }

    /**
     * The features this scope covers — what an entitlement check must clear.
     *
     * @return list<PortalFeature>
     */
    public function features(): array
    {
        return $this === self::Both
            ? [PortalFeature::Sso, PortalFeature::Scim]
            : [PortalFeature::from($this->value)];
    }
}
