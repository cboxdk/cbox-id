<?php

declare(strict_types=1);

namespace Cbox\Id\Connectors\Connections;

use Cbox\Id\Connectors\Contracts\ConnectorAnalytics;
use Cbox\Id\Connectors\Enums\ConnectorCategory;
use Cbox\Id\Connectors\ValueObjects\ConnectionSummary;
use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\Federation\Contracts\Connections as FederationConnections;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Provisioning\Contracts\ProvisioningConnections;
use Cbox\Id\Provisioning\Models\ProvisioningConnection;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;
use Cbox\Id\Webhooks\Enums\WebhookEventType;
use Cbox\Id\Webhooks\Models\WebhookEndpoint;

/**
 * Reads the four existing module CONTRACTS — never their concrete models directly —
 * to present one normalised, per-organization list of live connections across
 * outbound SCIM, webhooks and upstream federation. Everything is environment-scoped
 * by laravel-id's hard tenancy scope; this class adds no scoping of its own.
 *
 * It is deliberately honest about the public surface it stands on:
 *  - Provisioning exposes {@see ProvisioningConnections::active()} (environment-wide,
 *    ACTIVE connections) which it filters to the organization.
 *  - Webhooks exposes {@see WebhookRegistry::forOrganization()} (every ACTIVE endpoint the
 *    org's events can reach), narrowed here to the configured candidate event types —
 *    paused endpoints and endpoints subscribed only to an un-listed event type are not
 *    surfaced. It used to recover the same set by unioning {@see WebhookRegistry::matching()}
 *    over each candidate type, which was one full read of the endpoint table per candidate.
 *  - Federation exposes {@see FederationConnections::forOrganization()} — the single
 *    ACTIVE SSO connection for an organization; drafts/inactive are not listed.
 *  - Directory ({@see Directory}) has no per-organization listing on its public
 *    contract, so inbound directories are intentionally NOT enumerated here.
 *
 * Delivery health is layered on via {@see ConnectorAnalytics}, which is inert by
 * default — so the list renders with no analytics backend, just without badges.
 */
class ConnectionsOverview
{
    /**
     * @param  list<string>  $webhookEventTypes  candidate event types used to recover an org's endpoints
     */
    public function __construct(
        private readonly ProvisioningConnections $provisioning,
        private readonly WebhookRegistry $webhooks,
        private readonly FederationConnections $federation,
        private readonly ConnectorAnalytics $analytics,
        private readonly array $webhookEventTypes,
    ) {}

    /**
     * Every enumerable connection for an organization (null ⇒ the whole environment,
     * which drops the organization-only federation lookup), each decorated with
     * delivery health when an analytics backend is wired.
     *
     * @return list<ConnectionSummary>
     */
    public function forOrganization(?string $organizationId): array
    {
        return [
            ...$this->provisioningSummaries($organizationId),
            ...$this->webhookSummaries($organizationId),
            ...$this->federationSummaries($organizationId),
        ];
    }

    /** How many connections are currently active for an organization. */
    public function activeCount(?string $organizationId): int
    {
        $active = array_filter(
            $this->forOrganization($organizationId),
            static fn (ConnectionSummary $summary): bool => $summary->isActive(),
        );

        return count($active);
    }

    /**
     * @return list<ConnectionSummary>
     */
    private function provisioningSummaries(?string $organizationId): array
    {
        $summaries = [];

        foreach ($this->provisioning->active() as $connection) {
            /** @var ProvisioningConnection $connection */
            if ($organizationId !== null && ! $connection->isEnvironmentWide()
                && ! in_array($organizationId, $connection->scopeOrganizationIds(), true)) {
                continue;
            }

            $summaries[] = $this->decorate(new ConnectionSummary(
                category: ConnectorCategory::Provisioning,
                id: $connection->id,
                name: $connection->name,
                status: $connection->status->value,
                target: $connection->base_url,
            ));
        }

        return $summaries;
    }

    /**
     * @return list<ConnectionSummary>
     */
    private function webhookSummaries(?string $organizationId): array
    {
        $summaries = [];

        // ONE read, then the same subscription test the registry was doing anyway.
        //
        // This used to call matching() once per candidate event type, and matching()
        // ignores the type in SQL — it reads every active endpoint and filters in PHP. So
        // five candidate types were five identical full reads per render, and the list is
        // config: aligning it with the app's own catalogue, which names 24 event types,
        // is a one-line edit that looks harmless and would have made it 24.
        foreach ($this->webhooks->forOrganization($organizationId) as $endpoint) {
            /** @var WebhookEndpoint $endpoint */
            if (! $this->subscribedToAnyCandidate($endpoint)) {
                continue;
            }

            $summaries[] = $this->decorate(new ConnectionSummary(
                category: ConnectorCategory::Webhook,
                id: $endpoint->id,
                name: $endpoint->url,
                status: $endpoint->status->value,
                target: $endpoint->url,
            ));
        }

        return $summaries;
    }

    /**
     * Whether an endpoint subscribes to anything this deployment listed as a candidate.
     *
     * The same predicate the union of `matching()` calls expressed, said once: an endpoint
     * appeared in that union if ANY candidate type matched it, and the de-duplication by
     * id existed only to undo the repetition. A wildcard subscriber matches whatever the
     * list contains, and an empty list offers nothing to match — which is the honest
     * reading of a deployment that named no candidates, and what the loop did before.
     */
    private function subscribedToAnyCandidate(WebhookEndpoint $endpoint): bool
    {
        if (in_array(WebhookEventType::WILDCARD, $endpoint->event_types, true)) {
            return $this->webhookEventTypes !== [];
        }

        foreach ($this->webhookEventTypes as $eventType) {
            if (in_array($eventType, $endpoint->event_types, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<ConnectionSummary>
     */
    private function federationSummaries(?string $organizationId): array
    {
        if ($organizationId === null) {
            return [];
        }

        $connection = $this->federation->forOrganization($organizationId);

        if (! $connection instanceof Connection) {
            return [];
        }

        return [$this->decorate(new ConnectionSummary(
            category: ConnectorCategory::Federation,
            id: $connection->id,
            name: $connection->name,
            status: $connection->status->value,
            target: $connection->type->value,
        ))];
    }

    private function decorate(ConnectionSummary $summary): ConnectionSummary
    {
        $health = $this->analytics->health($summary->category, $summary->id);

        if ($health === null) {
            return $summary;
        }

        return new ConnectionSummary(
            category: $summary->category,
            id: $summary->id,
            name: $summary->name,
            status: $summary->status,
            target: $summary->target,
            health: $health,
        );
    }
}
