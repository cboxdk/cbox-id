<?php

declare(strict_types=1);

namespace Cbox\Id\Connectors\Connections;

use App\Http\Controllers\Console\LogStreamController;
use Carbon\CarbonInterface;
use Cbox\Id\AuditStreaming\Models\AuditStream;
use Cbox\Id\Connectors\Contracts\ConnectorAnalytics;
use Cbox\Id\Connectors\Enums\ConnectorCategory;
use Cbox\Id\Connectors\ValueObjects\ConnectionSummary;
use Cbox\Id\Directory\Enums\DirectoryProvider;
use Cbox\Id\Directory\Enums\DirectorySyncStatus;
use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\ExternalActions\Models\ExternalActionEndpoint;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\Models\ProviderOptOut;
use Cbox\Id\Federation\ProviderCatalog;
use Cbox\Id\Pipes\Models\Pipe;
use Cbox\Id\Pipes\Models\PipeConnection;
use Cbox\Id\Pipes\PipeProviderCatalog;
use Cbox\Id\Provisioning\Models\ProvisioningConnection;
use Cbox\Id\SamlIdp\Models\ServiceProvider;
use Cbox\Id\Webhooks\Models\WebhookEndpoint;
use Cbox\LaravelSiem\Enums\StreamHealth;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every live connection in the environment, across every connector type, in one
 * normalised list — and what each one's own records say about how it is doing.
 *
 * IT READS THE ROWS, not the modules' "list" contracts, and that is the fix rather than a
 * shortcut. The contracts each answered a narrower question than this list asks: the
 * provisioning contract lists ACTIVE connections only, the webhook registry lists only the
 * endpoints subscribed to a configured set of event types, federation answers one ACTIVE
 * connection per organization, and Directory Sync has no listing at all — so a demo
 * environment with an SSO connection, a SCIM directory, a webhook and a log stream showed
 * "No connectors yet". Every model here is environment-owned, so laravel-id's hard tenancy
 * scope bounds each query to this environment, exactly as it bounds the type's own page.
 *
 * WHICH ROWS "REACH" AN ORGANIZATION, when one is named. Its own, and the environment's
 * where the environment's applies to it — the same answer the type's own list gives when
 * filtered to that organization: an environment-wide webhook, inline hook, SAML app or log
 * stream carries this organization's data too; an environment social provider is on its
 * sign-in page unless it opted out; an environment-wide provisioning connection pushes its
 * people. With no organization, every row in the environment.
 *
 * Health comes first from the row (consecutive failures, last sync result, the stream's
 * circuit), and a bound {@see ConnectorAnalytics} backend decorates it where it knows more.
 * Nothing here writes.
 */
class ConnectionsOverview
{
    public function __construct(
        private readonly ConnectorAnalytics $analytics,
    ) {}

    /**
     * Every connection that reaches an organization (null ⇒ the whole environment).
     *
     * @return list<ConnectionSummary>
     */
    public function forOrganization(?string $organizationId): array
    {
        $summaries = [
            ...$this->federation($organizationId),
            ...$this->social($organizationId),
            ...$this->directories($organizationId),
            ...$this->provisioning($organizationId),
            ...$this->samlApps($organizationId),
            ...$this->webhooks($organizationId),
            ...$this->hooks($organizationId),
            ...$this->logStreams($organizationId),
            ...$this->pipes(),
        ];

        return array_map($this->decorate(...), $summaries);
    }

    /** How many connections are currently active for an organization. */
    public function activeCount(?string $organizationId): int
    {
        return count(array_filter(
            $this->forOrganization($organizationId),
            static fn (ConnectionSummary $summary): bool => $summary->isActive(),
        ));
    }

    /** @return list<ConnectionSummary> */
    private function federation(?string $organizationId): array
    {
        $query = Connection::query()
            ->whereNull('provider')
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where('organization_id', $organizationId))
            ->orderBy('name');

        return array_values(array_map(static fn (Connection $connection): ConnectionSummary => new ConnectionSummary(
            category: ConnectorCategory::Federation,
            id: $connection->id,
            name: $connection->name,
            status: $connection->status->value,
            target: strtoupper($connection->type->value),
            organizationId: $connection->organization_id,
        ), $query->get(['id', 'organization_id', 'name', 'status', 'type', 'provider'])->all()));
    }

    /** @return list<ConnectionSummary> */
    private function social(?string $organizationId): array
    {
        $optedOut = $organizationId === null
            ? []
            : ProviderOptOut::query()->where('organization_id', $organizationId)->pluck('provider')->all();

        $query = Connection::query()
            ->whereNotNull('provider')
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where(
                fn (Builder $owner): Builder => $owner->where('organization_id', $organizationId)->orWhereNull('organization_id'),
            ))
            ->orderBy('provider');

        $summaries = [];

        foreach ($query->get(['id', 'organization_id', 'name', 'status', 'type', 'provider']) as $connection) {
            /** @var Connection $connection */
            if ($connection->organization_id === null && in_array($connection->provider, $optedOut, true)) {
                continue;
            }

            $summaries[] = new ConnectionSummary(
                category: ConnectorCategory::Social,
                id: $connection->id,
                name: $connection->name,
                status: $connection->status->value,
                target: ProviderCatalog::find((string) $connection->provider)->name ?? (string) $connection->provider,
                organizationId: $connection->organization_id,
            );
        }

        return $summaries;
    }

    /** @return list<ConnectionSummary> */
    private function directories(?string $organizationId): array
    {
        $query = Directory::query()
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where('organization_id', $organizationId))
            ->orderBy('name');

        $summaries = [];

        foreach ($query->get(['id', 'organization_id', 'name', 'provider', 'status', 'last_synced_at', 'last_sync_error', 'last_sync_status']) as $directory) {
            /** @var Directory $directory */
            $failed = $directory->last_sync_status === DirectorySyncStatus::Failed || $directory->last_sync_error !== null;

            $summaries[] = new ConnectionSummary(
                category: $directory->provider->isHris() ? ConnectorCategory::Hris : ConnectorCategory::Directory,
                id: $directory->id,
                name: $directory->name,
                status: $directory->status->value,
                target: $directory->provider->label(),
                organizationId: $directory->organization_id,
                lastActivityAt: $directory->last_synced_at,
                signal: match (true) {
                    $failed => ConnectionSummary::FAILING,
                    $directory->last_synced_at !== null => ConnectionSummary::HEALTHY,
                    // A SCIM directory waits for its provider to push; a pull one for its first run.
                    default => $directory->provider === DirectoryProvider::Scim ? null : ConnectionSummary::IDLE,
                },
                problem: $failed ? ($directory->last_sync_error ?? 'The last sync failed.') : null,
            );
        }

        return $summaries;
    }

    /** @return list<ConnectionSummary> */
    private function provisioning(?string $organizationId): array
    {
        $summaries = [];

        foreach (ProvisioningConnection::query()->orderBy('name')->get() as $connection) {
            /** @var ProvisioningConnection $connection */
            $reaches = $organizationId === null
                || $connection->organization_id === $organizationId
                || $connection->isEnvironmentWide()
                || in_array($organizationId, $connection->scopeOrganizationIds(), true);

            if (! $reaches) {
                continue;
            }

            $summaries[] = new ConnectionSummary(
                category: ConnectorCategory::Provisioning,
                id: $connection->id,
                name: $connection->name,
                status: $connection->status->value,
                target: $connection->base_url,
                organizationId: $connection->organization_id,
                lastActivityAt: $connection->last_success_at,
                signal: self::deliverySignal($connection->consecutive_failures, $connection->circuit_opened_at, $connection->last_success_at),
                problem: $connection->consecutive_failures > 0 ? $connection->last_error : null,
            );
        }

        return $summaries;
    }

    /** @return list<ConnectionSummary> */
    private function samlApps(?string $organizationId): array
    {
        $query = ServiceProvider::query()
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where(
                fn (Builder $owner): Builder => $owner->where('organization_id', $organizationId)->orWhereNull('organization_id'),
            ))
            ->orderBy('entity_id');

        return array_values(array_map(static fn (ServiceProvider $provider): ConnectionSummary => new ConnectionSummary(
            category: ConnectorCategory::SamlApp,
            id: $provider->id,
            name: $provider->entity_id,
            status: $provider->status->value,
            target: $provider->acs_url,
            organizationId: $provider->organization_id,
        ), $query->get(['id', 'organization_id', 'entity_id', 'acs_url', 'status'])->all()));
    }

    /** @return list<ConnectionSummary> */
    private function webhooks(?string $organizationId): array
    {
        $query = WebhookEndpoint::query()
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where(
                fn (Builder $owner): Builder => $owner->where('organization_id', $organizationId)->orWhereNull('organization_id'),
            ))
            ->orderBy('url');

        $summaries = [];

        foreach ($query->get(['id', 'organization_id', 'url', 'status', 'consecutive_failures', 'circuit_opened_at', 'last_success_at', 'last_error']) as $endpoint) {
            /** @var WebhookEndpoint $endpoint */
            $summaries[] = new ConnectionSummary(
                category: ConnectorCategory::Webhook,
                id: $endpoint->id,
                name: self::shortUrl($endpoint->url),
                status: $endpoint->status->value,
                target: $endpoint->url,
                organizationId: $endpoint->organization_id,
                lastActivityAt: $endpoint->last_success_at,
                signal: self::deliverySignal($endpoint->consecutive_failures, $endpoint->circuit_opened_at, $endpoint->last_success_at),
                problem: $endpoint->consecutive_failures > 0 || $endpoint->circuit_opened_at !== null ? $endpoint->last_error : null,
            );
        }

        return $summaries;
    }

    /** @return list<ConnectionSummary> */
    private function hooks(?string $organizationId): array
    {
        $query = ExternalActionEndpoint::query()
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where(
                fn (Builder $owner): Builder => $owner->where('organization_id', $organizationId)->orWhereNull('organization_id'),
            ))
            ->orderBy('url');

        return array_values(array_map(static fn (ExternalActionEndpoint $endpoint): ConnectionSummary => new ConnectionSummary(
            category: ConnectorCategory::Hook,
            id: $endpoint->id,
            name: $endpoint->hook_point->label(),
            status: $endpoint->status->value,
            target: $endpoint->url,
            organizationId: $endpoint->organization_id,
        ), $query->get(['id', 'organization_id', 'hook_point', 'url', 'status'])->all()));
    }

    /** @return list<ConnectionSummary> */
    private function logStreams(?string $organizationId): array
    {
        $query = AuditStream::query()
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where(
                fn (Builder $owner): Builder => $owner->where('organization_id', $organizationId)->orWhereNull('organization_id'),
            ))
            ->orderBy('name');

        $summaries = [];

        foreach ($query->get() as $stream) {
            /** @var AuditStream $stream */
            $health = $stream->health();

            $summaries[] = new ConnectionSummary(
                category: ConnectorCategory::LogStream,
                id: $stream->id,
                name: $stream->name,
                status: $stream->enabled ? 'active' : 'paused',
                target: LogStreamController::DESTINATIONS[$stream->destination->value],
                organizationId: $stream->organization_id,
                lastActivityAt: self::latest($stream->last_success_at, $stream->last_failure_at),
                signal: match ($health) {
                    StreamHealth::Degraded, StreamHealth::ActionRequired => ConnectionSummary::FAILING,
                    StreamHealth::Paused => null,
                    StreamHealth::Healthy => $stream->last_success_at === null ? ConnectionSummary::IDLE : ConnectionSummary::HEALTHY,
                },
                problem: in_array($health, [StreamHealth::Degraded, StreamHealth::ActionRequired], true) ? $stream->last_error : null,
            );
        }

        return $summaries;
    }

    /**
     * The environment's pipes. A pipe belongs to no organization — it is where ANY person
     * here connects an account — so it is listed whichever organization is asked about.
     *
     * @return list<ConnectionSummary>
     */
    private function pipes(): array
    {
        $accounts = [];
        $latest = [];

        foreach (PipeConnection::query()->get(['pipe_id', 'connected_at', 'last_refreshed_at']) as $connection) {
            /** @var PipeConnection $connection */
            $accounts[$connection->pipe_id] = ($accounts[$connection->pipe_id] ?? 0) + 1;
            $latest[$connection->pipe_id] = self::latest($latest[$connection->pipe_id] ?? null, self::latest($connection->connected_at, $connection->last_refreshed_at));
        }

        return array_values(array_map(static function (Pipe $pipe) use ($accounts, $latest): ConnectionSummary {
            $count = $accounts[$pipe->id] ?? 0;

            return new ConnectionSummary(
                category: ConnectorCategory::Pipe,
                id: $pipe->id,
                name: PipeProviderCatalog::find($pipe->provider)->name ?? $pipe->provider,
                status: $pipe->enabled ? 'active' : 'disabled',
                target: $count.' connected '.($count === 1 ? 'account' : 'accounts'),
                lastActivityAt: $latest[$pipe->id] ?? null,
            );
        }, Pipe::query()->orderBy('provider')->get(['id', 'provider', 'enabled'])->all()));
    }

    /**
     * What a delivery row's own counters say: failing while it has consecutive failures or
     * an open circuit, healthy once something got through, idle before anything was sent.
     */
    private static function deliverySignal(int $failures, ?CarbonInterface $circuitOpenedAt, ?CarbonInterface $lastSuccessAt): string
    {
        return match (true) {
            $failures > 0 || $circuitOpenedAt !== null => ConnectionSummary::FAILING,
            $lastSuccessAt !== null => ConnectionSummary::HEALTHY,
            default => ConnectionSummary::IDLE,
        };
    }

    private static function latest(?CarbonInterface $a, ?CarbonInterface $b): ?CarbonInterface
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $a->greaterThan($b) ? $a : $b;
    }

    /** `hooks.acme.test/events` for `https://hooks.acme.test/events`: the part a person reads. */
    private static function shortUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host)) {
            return $url;
        }

        $path = parse_url($url, PHP_URL_PATH);

        return $host.(is_string($path) && $path !== '/' ? $path : '');
    }

    private function decorate(ConnectionSummary $summary): ConnectionSummary
    {
        $health = $this->analytics->health($summary->category, $summary->id);

        return $health === null ? $summary : $summary->withHealth($health);
    }
}
