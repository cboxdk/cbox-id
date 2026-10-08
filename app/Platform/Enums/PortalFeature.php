<?php

declare(strict_types=1);

namespace App\Platform\Enums;

/**
 * A capability an Admin Portal link can open — SSO, SCIM, or the organization's audit
 * logs. Its backing value doubles as the entitlement key checked against the org, so a
 * feature is only reachable when the link's {@see PortalScope} permits it AND the org is
 * entitled.
 */
enum PortalFeature: string
{
    case Sso = 'sso';
    case Scim = 'scim';
    case AuditLogs = 'audit_logs';

    /** The entitlement key gating this feature for an organization. */
    public function entitlement(): string
    {
        return $this->value;
    }
}
