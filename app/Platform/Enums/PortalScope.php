<?php

declare(strict_types=1);

namespace App\Platform\Enums;

/**
 * What a single-use Admin Portal setup link is permitted to configure. Backed by the
 * exact strings persisted on the framework's portal-link `scope` column, so it maps
 * to and from storage with `->value` / `tryFrom()` and needs no migration. A link is
 * scoped to SSO, to SCIM, or to both — or to the organization's AUDIT LOGS, a link that
 * configures nothing and shows the audit events the app sent about the organization.
 * Deny-by-default: an unrecognized stored value reads as null (no scope), never a
 * trusted one.
 */
enum PortalScope: string
{
    case Sso = 'sso';
    case Scim = 'scim';
    case Both = 'both';
    case AuditLogs = 'audit_logs';

    /**
     * Whether this scope permits configuring the given feature.
     *
     * `both` is SSO and SCIM — the two setup screens it always meant — and never a link to
     * the audit logs, which a link minted before they existed must not have become.
     */
    public function permits(PortalFeature $feature): bool
    {
        return in_array($feature, $this->features(), true);
    }

    /**
     * The features this scope covers — what an entitlement check must clear.
     *
     * @return list<PortalFeature>
     */
    public function features(): array
    {
        return match ($this) {
            self::Both => [PortalFeature::Sso, PortalFeature::Scim],
            self::Sso => [PortalFeature::Sso],
            self::Scim => [PortalFeature::Scim],
            self::AuditLogs => [PortalFeature::AuditLogs],
        };
    }
}
