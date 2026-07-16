<?php

declare(strict_types=1);

namespace Cbox\Id\Connectors\Enums;

/**
 * The kinds of connector the platform speaks, one per underlying laravel-id module.
 * Each case maps to exactly one public module contract the catalog delegates to —
 * the enum is the stable key the console and analytics join on, so a new connector
 * kind is a new case here plus a descriptor, never a schema change.
 */
enum ConnectorCategory: string
{
    /** Outbound SCIM 2.0 provisioning to a downstream app (ProvisioningConnections). */
    case Provisioning = 'provisioning';

    /** Outbound event webhooks to a subscriber endpoint (WebhookRegistry). */
    case Webhook = 'webhook';

    /** Inbound SCIM directory sync from an upstream IdP (Directories). */
    case Directory = 'directory';

    /** Upstream IdP federation for SSO — SAML/OIDC (Federation Connections). */
    case Federation = 'federation';

    /** A short human label for the console. */
    public function label(): string
    {
        return match ($this) {
            self::Provisioning => 'Outbound SCIM',
            self::Webhook => 'Webhooks',
            self::Directory => 'Directory sync',
            self::Federation => 'SSO federation',
        };
    }

    /** Whether the data flows OUT to the target (true) or IN from it (false). */
    public function isOutbound(): bool
    {
        return match ($this) {
            self::Provisioning, self::Webhook => true,
            self::Directory, self::Federation => false,
        };
    }
}
