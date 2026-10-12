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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

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
 * PAGED IN THE DATABASE. Each type is one query ({@see self::sources()}) that also says, in
 * SQL, whether a row is active and whether it is failing. {@see self::page()} puts them under
 * one `UNION ALL` and lets the database order the lot — failing first, then by type, then by
 * name — and cut out the page, so a list over an environment with ten thousand
 * organizations' directories reads fifty rows, not ten thousand. Only that page is then
 * loaded as models, one `whereIn` per type on it. {@see self::counts()} is one aggregate per
 * type, which is all the catalog and the filters need.
 *
 * WHICH ROWS "REACH" AN ORGANIZATION, when one is named. Its own, and the environment's
 * where the environment's applies to it — the same answer the type's own list gives when
 * filtered to that organization: an environment-wide webhook, hook, SAML app or log stream
 * carries this organization's data too; an environment social provider is on its sign-in
 * page unless it opted out; an environment-wide provisioning connection pushes its people.
 * With no organization, every row in the environment.
 *
 * Health is read from the row (consecutive failures, last sync result, the stream's failure
 * counter), the same in SQL and in PHP. A bound {@see ConnectorAnalytics} backend decorates
 * the rows it is shown with; the "failing" filter and the ordering are the records', because
 * an analytics backend answers per row and cannot be asked to sort a table. Nothing here
 * writes.
 */
class ConnectionsOverview
{
    /** The SQL that says a delivery row is failing: consecutive failures, or an open circuit. */
    private const string DELIVERY_FAILING = '(consecutive_failures > 0 OR circuit_opened_at IS NOT NULL)';

    private const string NEVER = '(1 = 0)';

    public function __construct(
        private readonly ConnectorAnalytics $analytics,
    ) {}

    /**
     * One page of the connections reaching an organization (null ⇒ the whole environment),
     * of the given types, narrowed by status — failing first, then by type, then by name.
     *
     * @param  list<ConnectorCategory>  $categories  the types to list, in display order
     * @param  ''|'failing'|'active'|'inactive'  $status
     * @return array{rows: list<ConnectionSummary>, total: int}
     */
    public function page(?string $organizationId, array $categories, string $status, int $perPage, int $page): array
    {
        $union = null;

        foreach ($this->sources($organizationId, $categories) as [, $source]) {
            $select = $this->keyed($source);
            $union = $union === null ? $select : $union->unionAll($select);
        }

        if ($union === null) {
            return ['rows' => [], 'total' => 0];
        }

        $listed = DB::query()->fromSub($union, 'connections_list')
            ->when($status === 'failing', fn (QueryBuilder $q): QueryBuilder => $q->where('failing', 1))
            ->when($status === 'active', fn (QueryBuilder $q): QueryBuilder => $q->where('active', 1))
            ->when($status === 'inactive', fn (QueryBuilder $q): QueryBuilder => $q->where('active', 0));

        $total = (clone $listed)->count();

        $keys = $listed
            ->orderByDesc('failing')
            ->orderBy('category_order')
            ->orderBy('sort_name')
            ->orderBy('id')
            ->offset(max(0, ($page - 1) * $perPage))
            ->limit($perPage)
            ->get(['category', 'id']);

        /** @var array<string, list<string>> $ids */
        $ids = [];

        /** @var list<array{0: string, 1: string}> $order category, id — the page, in order */
        $order = [];

        foreach ($keys as $key) {
            $order[] = $pair = [self::text($key->category ?? null), self::text($key->id ?? null)];
            $ids[$pair[0]][] = $pair[1];
        }

        /** @var array<string, ConnectionSummary> $loaded category:id => summary */
        $loaded = [];

        foreach ($ids as $category => $wanted) {
            foreach ($this->summaries(ConnectorCategory::from($category), $this->query(ConnectorCategory::from($category), $organizationId)->whereKey($wanted)) as $summary) {
                $loaded[$category.':'.$summary->id] = $summary;
            }
        }

        $rows = [];

        foreach ($order as [$category, $id]) {
            $summary = $loaded[$category.':'.$id] ?? null;

            if ($summary !== null) {
                $rows[] = $this->decorate($summary);
            }
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * How many connections of each type reach an organization, how many are active and how
     * many failing — one aggregate per type.
     *
     * @param  list<ConnectorCategory>|null  $categories  null ⇒ every type
     * @return array<string, array{total: int, active: int, failing: int}> by category value
     */
    public function counts(?string $organizationId, ?array $categories = null): array
    {
        $counts = [];

        foreach ($this->sources($organizationId, $categories ?? ConnectorCategory::cases()) as [$category, $source]) {
            $row = (array) $source['query']->toBase()
                ->selectRaw('COUNT(*) AS total')
                ->selectRaw('SUM(CASE WHEN '.$source['active'].' THEN 1 ELSE 0 END) AS active')
                ->selectRaw('SUM(CASE WHEN '.$source['failing'].' THEN 1 ELSE 0 END) AS failing')
                ->first();

            $counts[$category->value] = [
                'total' => self::number($row['total'] ?? null),
                'active' => self::number($row['active'] ?? null),
                'failing' => self::number($row['failing'] ?? null),
            ];
        }

        return $counts;
    }

    /**
     * Every connection that reaches an organization, loaded — for a caller that genuinely
     * wants them all. The console pages use {@see self::page()} and {@see self::counts()}.
     *
     * @return list<ConnectionSummary>
     */
    public function forOrganization(?string $organizationId): array
    {
        $summaries = [];

        foreach (ConnectorCategory::cases() as $category) {
            foreach ($this->summaries($category, $this->query($category, $organizationId)) as $summary) {
                $summaries[] = $this->decorate($summary);
            }
        }

        return $summaries;
    }

    /** How many connections are currently active for an organization. */
    public function activeCount(?string $organizationId): int
    {
        return array_sum(array_column($this->counts($organizationId), 'active'));
    }

    /**
     * The types asked for, each with its query and its SQL for "active" and "failing", in
     * the order given — the order is the list's second sort key.
     *
     * @param  list<ConnectorCategory>  $categories
     * @return list<array{0: ConnectorCategory, 1: array{query: Builder<covariant Model>, tag: literal-string, name: literal-string, active: literal-string, failing: literal-string}}>
     */
    private function sources(?string $organizationId, array $categories): array
    {
        $sources = [];

        foreach ($categories as $category) {
            $sources[] = [$category, [
                'query' => $this->query($category, $organizationId),
                ...self::columns($category),
            ]];
        }

        return $sources;
    }

    /**
     * What a type's rows are sorted by, and its SQL for "active" and "failing" — the same
     * predicates {@see self::summaries()} computes in PHP.
     *
     * Literal SQL, every piece of it — the type's tag and its place in the order are spelled
     * out per case rather than built from the enum, so nothing a row holds reaches the SQL.
     *
     * @return array{tag: literal-string, name: literal-string, active: literal-string, failing: literal-string}
     */
    private static function columns(ConnectorCategory $category): array
    {
        return match ($category) {
            ConnectorCategory::Federation => ['tag' => "'federation' AS category, 0 AS category_order", 'name' => 'name', 'active' => "status = 'active'", 'failing' => self::NEVER],
            ConnectorCategory::Social => ['tag' => "'social' AS category, 1 AS category_order", 'name' => 'provider', 'active' => "status = 'active'", 'failing' => self::NEVER],
            ConnectorCategory::Directory => [
                'tag' => "'directory' AS category, 2 AS category_order",
                'name' => 'name',
                'active' => "status = 'active'",
                'failing' => "(last_sync_status = 'failed' OR last_sync_error IS NOT NULL)",
            ],
            ConnectorCategory::Hris => [
                'tag' => "'hris' AS category, 3 AS category_order",
                'name' => 'name',
                'active' => "status = 'active'",
                'failing' => "(last_sync_status = 'failed' OR last_sync_error IS NOT NULL)",
            ],
            ConnectorCategory::Provisioning => ['tag' => "'provisioning' AS category, 4 AS category_order", 'name' => 'name', 'active' => "status = 'active'", 'failing' => self::DELIVERY_FAILING],
            ConnectorCategory::SamlApp => ['tag' => "'saml-app' AS category, 5 AS category_order", 'name' => 'entity_id', 'active' => "status = 'active'", 'failing' => self::NEVER],
            ConnectorCategory::Webhook => ['tag' => "'webhook' AS category, 6 AS category_order", 'name' => 'url', 'active' => "status = 'active'", 'failing' => self::DELIVERY_FAILING],
            ConnectorCategory::Hook => ['tag' => "'hook' AS category, 7 AS category_order", 'name' => 'url', 'active' => "status = 'active'", 'failing' => self::NEVER],
            ConnectorCategory::LogStream => ['tag' => "'log-stream' AS category, 8 AS category_order", 'name' => 'name', 'active' => 'enabled = true', 'failing' => '(consecutive_failures > 0)'],
            ConnectorCategory::Pipe => ['tag' => "'pipe' AS category, 9 AS category_order", 'name' => 'provider', 'active' => 'enabled = true', 'failing' => self::NEVER],
        };
    }

    /**
     * One type's rows as the union's common shape: type, its place in the order, id, sort
     * name, and the two flags.
     *
     * @param  array{query: Builder<covariant Model>, tag: literal-string, name: literal-string, active: literal-string, failing: literal-string}  $source
     */
    private function keyed(array $source): QueryBuilder
    {
        // Literals rather than bindings: PostgreSQL cannot type a bound parameter in a
        // UNION's select list, and both values are this class's own constants.
        return $source['query']->toBase()->selectRaw(
            $source['tag'].', id, '.$source['name'].' AS sort_name, '
            .'CASE WHEN '.$source['failing'].' THEN 1 ELSE 0 END AS failing, '
            .'CASE WHEN '.$source['active'].' THEN 1 ELSE 0 END AS active',
        );
    }

    /**
     * The rows of one type that reach an organization (null ⇒ every row in the environment).
     *
     * @return Builder<covariant Model>
     */
    private function query(ConnectorCategory $category, ?string $organizationId): Builder
    {
        $ownOrEnvironment = fn (Builder $q): Builder => $q->where(
            fn (Builder $owner): Builder => $owner->where('organization_id', $organizationId)->orWhereNull('organization_id'),
        );
        $hris = array_map(static fn (DirectoryProvider $provider): string => $provider->value, DirectoryProvider::hris());

        return match ($category) {
            ConnectorCategory::Federation => Connection::query()
                ->whereNull('provider')
                ->when($organizationId !== null, fn (Builder $q): Builder => $q->where('organization_id', $organizationId)),
            ConnectorCategory::Social => Connection::query()
                ->whereNotNull('provider')
                // The organization's own, and the environment's it has not opted out of.
                ->when($organizationId !== null, fn (Builder $q): Builder => $q->where(
                    fn (Builder $owner): Builder => $owner->where('organization_id', $organizationId)->orWhere(
                        fn (Builder $environment): Builder => $environment->whereNull('organization_id')->whereNotIn(
                            'provider',
                            ProviderOptOut::query()->where('organization_id', $organizationId)->select('provider'),
                        ),
                    ),
                )),
            ConnectorCategory::Directory => Directory::query()
                ->whereNotIn('provider', $hris)
                ->when($organizationId !== null, fn (Builder $q): Builder => $q->where('organization_id', $organizationId)),
            ConnectorCategory::Hris => Directory::query()
                ->whereIn('provider', $hris)
                ->when($organizationId !== null, fn (Builder $q): Builder => $q->where('organization_id', $organizationId)),
            ConnectorCategory::Provisioning => ProvisioningConnection::query()
                ->when($organizationId !== null, fn (Builder $q): Builder => $q->whereKey($this->provisioningReaching((string) $organizationId))),
            ConnectorCategory::SamlApp => ServiceProvider::query()->when($organizationId !== null, $ownOrEnvironment),
            ConnectorCategory::Webhook => WebhookEndpoint::query()->when($organizationId !== null, $ownOrEnvironment),
            ConnectorCategory::Hook => ExternalActionEndpoint::query()->when($organizationId !== null, $ownOrEnvironment),
            ConnectorCategory::LogStream => AuditStream::query()->when($organizationId !== null, $ownOrEnvironment),
            // A pipe belongs to no organization — it is where ANY person here connects an
            // account — so it is listed whichever organization is asked about.
            ConnectorCategory::Pipe => Pipe::query(),
        };
    }

    /**
     * The provisioning connections that push an organization's people: its own, the
     * environment-wide ones, and the environment's scoped to it.
     *
     * Read in PHP because "scoped to it" is a list inside a JSON column, which the three
     * databases this runs on query three different ways. It is the one table here that holds
     * a handful of rows per environment — one per downstream app — so its ids are read once
     * and the paging stays in SQL.
     *
     * @return list<string>
     */
    private function provisioningReaching(string $organizationId): array
    {
        $ids = [];

        foreach (ProvisioningConnection::query()->get(['id', 'organization_id', 'scope']) as $connection) {
            /** @var ProvisioningConnection $connection */
            if ($connection->organization_id === $organizationId
                || $connection->isEnvironmentWide()
                || in_array($organizationId, $connection->scopeOrganizationIds(), true)) {
                $ids[] = $connection->id;
            }
        }

        return $ids;
    }

    /**
     * The rows a query finds, as summaries.
     *
     * @param  Builder<covariant Model>  $query
     * @return list<ConnectionSummary>
     */
    private function summaries(ConnectorCategory $category, Builder $query): array
    {
        $models = array_values($query->get()->all());

        if ($category === ConnectorCategory::Pipe) {
            return $this->pipes($models);
        }

        return array_map(fn (Model $model): ConnectionSummary => match (true) {
            $model instanceof Directory => self::directory($model),
            $model instanceof ProvisioningConnection => new ConnectionSummary(
                category: ConnectorCategory::Provisioning,
                id: $model->id,
                name: $model->name,
                status: $model->status->value,
                target: $model->base_url,
                organizationId: $model->organization_id,
                lastActivityAt: $model->last_success_at,
                signal: self::deliverySignal($model->consecutive_failures, $model->circuit_opened_at, $model->last_success_at),
                problem: $model->consecutive_failures > 0 || $model->circuit_opened_at !== null ? $model->last_error : null,
            ),
            $model instanceof WebhookEndpoint => new ConnectionSummary(
                category: ConnectorCategory::Webhook,
                id: $model->id,
                name: self::shortUrl($model->url),
                status: $model->status->value,
                target: $model->url,
                organizationId: $model->organization_id,
                lastActivityAt: $model->last_success_at,
                signal: self::deliverySignal($model->consecutive_failures, $model->circuit_opened_at, $model->last_success_at),
                problem: $model->consecutive_failures > 0 || $model->circuit_opened_at !== null ? $model->last_error : null,
            ),
            $model instanceof AuditStream => new ConnectionSummary(
                category: ConnectorCategory::LogStream,
                id: $model->id,
                name: $model->name,
                status: $model->enabled ? 'active' : 'paused',
                target: LogStreamController::DESTINATIONS[$model->destination->value],
                organizationId: $model->organization_id,
                lastActivityAt: self::latest($model->last_success_at, $model->last_failure_at),
                signal: match (true) {
                    $model->consecutive_failures > 0 => ConnectionSummary::FAILING,
                    $model->last_success_at !== null => ConnectionSummary::HEALTHY,
                    default => ConnectionSummary::IDLE,
                },
                problem: $model->consecutive_failures > 0 ? $model->last_error : null,
            ),
            $model instanceof ServiceProvider => new ConnectionSummary(
                category: ConnectorCategory::SamlApp,
                id: $model->id,
                name: $model->entity_id,
                status: $model->status->value,
                target: $model->acs_url,
                organizationId: $model->organization_id,
            ),
            $model instanceof ExternalActionEndpoint => new ConnectionSummary(
                category: ConnectorCategory::Hook,
                id: $model->id,
                name: $model->hook_point->label(),
                status: $model->status->value,
                target: $model->url,
                organizationId: $model->organization_id,
            ),
            $model instanceof Connection => new ConnectionSummary(
                category: $category,
                id: $model->id,
                name: $model->name,
                status: $model->status->value,
                target: $model->provider === null
                    ? strtoupper($model->type->value)
                    : (ProviderCatalog::find($model->provider)->name ?? $model->provider),
                organizationId: $model->organization_id,
            ),
            default => throw new LogicException('No summary for '.$model::class),
        }, $models);
    }

    private static function directory(Directory $directory): ConnectionSummary
    {
        $failed = $directory->last_sync_status === DirectorySyncStatus::Failed || $directory->last_sync_error !== null;

        return new ConnectionSummary(
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

    /**
     * Pipes, with how many accounts are connected through each — counted for these pipes
     * only, in one grouped query.
     *
     * @param  list<Model>  $pipes
     * @return list<ConnectionSummary>
     */
    private function pipes(array $pipes): array
    {
        $ids = array_map(static fn (Model $pipe): mixed => $pipe->getKey(), $pipes);
        $stats = PipeConnection::query()
            ->whereIn('pipe_id', $ids)
            ->groupBy('pipe_id')
            ->get(['pipe_id', DB::raw('COUNT(*) AS accounts'), DB::raw('MAX(COALESCE(last_refreshed_at, connected_at)) AS latest')])
            ->keyBy('pipe_id');

        return array_map(static function (Model $pipe) use ($stats): ConnectionSummary {
            /** @var Pipe $pipe */
            $stat = $stats->get($pipe->id);
            $count = self::number($stat?->getAttribute('accounts'));
            $latest = $stat?->getAttribute('latest');

            return new ConnectionSummary(
                category: ConnectorCategory::Pipe,
                id: $pipe->id,
                name: PipeProviderCatalog::find($pipe->provider)->name ?? $pipe->provider,
                status: $pipe->enabled ? 'active' : 'disabled',
                target: $count.' connected '.($count === 1 ? 'account' : 'accounts'),
                lastActivityAt: is_string($latest) ? Carbon::parse($latest) : null,
            );
        }, $pipes);
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

    /** A database value as an int: drivers answer aggregates as int, string or null. */
    private static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function decorate(ConnectionSummary $summary): ConnectionSummary
    {
        $health = $this->analytics->health($summary->category, $summary->id);

        return $health === null ? $summary : $summary->withHealth($health);
    }
}
