<?php

declare(strict_types=1);

namespace Cbox\Id\Connectors\Catalog;

use Cbox\Id\Connectors\Enums\ConnectorCategory;
use Cbox\Id\Connectors\ValueObjects\ConnectorDescriptor;
use Cbox\Id\Directory\Contracts\Directories;
use Cbox\Id\Federation\Contracts\Connections as FederationConnections;
use Cbox\Id\Provisioning\Contracts\ProvisioningConnections;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;

/**
 * The plugin-owned catalog of connector TYPES — NOT a framework hook. It enumerates
 * the four connector kinds laravel-id ships as {@see ConnectorDescriptor}s, each
 * naming the PUBLIC module contract it is backed by and whether that contract can
 * list an organization's live connections. It is deliberately static data: the set
 * of connector kinds is fixed by the modules that exist, so this is a hand-written
 * list, not a discovery mechanism that could silently trust an unregistered kind.
 */
class ConnectorCatalog
{
    /**
     * Every connector type the platform speaks, in console display order.
     *
     * @return list<ConnectorDescriptor>
     */
    public function all(): array
    {
        return [
            new ConnectorDescriptor(
                key: 'scim-provisioning',
                category: ConnectorCategory::Provisioning,
                name: 'Outbound SCIM provisioning',
                description: 'Push users and membership changes OUT to a downstream app over its SCIM 2.0 endpoint.',
                contract: ProvisioningConnections::class,
                enumerable: true,
            ),
            new ConnectorDescriptor(
                key: 'webhook',
                category: ConnectorCategory::Webhook,
                name: 'Event webhooks',
                description: 'Deliver signed platform events to a subscriber HTTPS endpoint.',
                contract: WebhookRegistry::class,
                enumerable: true,
            ),
            new ConnectorDescriptor(
                key: 'directory-sync',
                category: ConnectorCategory::Directory,
                name: 'Inbound directory sync',
                description: 'Receive users and groups pushed IN from an upstream IdP over SCIM (the platform is the SCIM server).',
                contract: Directories::class,
                // The public Directories contract exposes register + authenticate only;
                // it has no per-organization listing, so live directories cannot be
                // enumerated here (managed through the host's own Directory pages).
                enumerable: false,
            ),
            new ConnectorDescriptor(
                key: 'idp-federation',
                category: ConnectorCategory::Federation,
                name: 'Upstream IdP federation',
                description: 'Delegate sign-in to an external SAML or OIDC identity provider (SSO).',
                contract: FederationConnections::class,
                enumerable: true,
            ),
        ];
    }

    /**
     * The descriptors whose module contract can list an organization's connections.
     *
     * @return list<ConnectorDescriptor>
     */
    public function enumerable(): array
    {
        return array_values(array_filter($this->all(), static fn (ConnectorDescriptor $d): bool => $d->enumerable));
    }

    public function find(string $key): ?ConnectorDescriptor
    {
        foreach ($this->all() as $descriptor) {
            if ($descriptor->key === $key) {
                return $descriptor;
            }
        }

        return null;
    }
}
