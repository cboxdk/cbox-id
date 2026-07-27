<?php

declare(strict_types=1);

namespace Cbox\Id\Analytics\Readers;

use Carbon\CarbonInterface;
use Cbox\Id\Analytics\ClickHouse\ClickHouseConnection;
use Cbox\Id\Analytics\Contracts\ReportReader;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;

/**
 * Reads analytics from ClickHouse. The event table is a ReplacingMergeTree keyed on
 * `event_id`, so every read uses `FINAL` to collapse at-least-once duplicates at
 * query time. Results are scoped to the current environment, mirroring the hard
 * environment boundary the Postgres path gets from the model scope.
 *
 * Every value is bound as a server-side query parameter — never interpolated — so
 * organization/metric input can't reach the SQL text.
 */
class ClickHouseReportReader implements ReportReader
{
    public function __construct(
        private readonly ClickHouseConnection $connection,
        private readonly EnvironmentContext $environments,
        private readonly string $table,
    ) {}

    public function series(
        string $metric,
        ?string $organizationId,
        CarbonInterface $since,
        CarbonInterface $until,
    ): array {
        $parameters = [
            'metric' => $metric,
            'since' => $since->format('Y-m-d H:i:s'),
            'until' => $until->format('Y-m-d H:i:s'),
        ];

        $sql = 'SELECT toDate(occurred_at) AS d, count() AS c FROM '.$this->qualifiedTable().' FINAL'
            .' WHERE metric = {metric:String} AND occurred_at >= {since:DateTime} AND occurred_at <= {until:DateTime}'
            .$this->orgClause($organizationId, $parameters)
            .$this->environmentClause($parameters)
            .' GROUP BY d ORDER BY d';

        $series = [];
        foreach ($this->connection->select($sql, $parameters) as $row) {
            $day = $row['d'] ?? null;
            $count = $row['c'] ?? null;
            if (is_string($day) && is_numeric($count)) {
                $series[$day] = (int) $count;
            }
        }

        return $series;
    }

    public function snapshot(
        ?string $organizationId,
        CarbonInterface $since,
        CarbonInterface $until,
    ): array {
        $parameters = [
            'since' => $since->format('Y-m-d H:i:s'),
            'until' => $until->format('Y-m-d H:i:s'),
        ];

        $sql = 'SELECT metric AS m, count() AS c FROM '.$this->qualifiedTable().' FINAL'
            .' WHERE metric != \'\' AND occurred_at >= {since:DateTime} AND occurred_at <= {until:DateTime}'
            .$this->orgClause($organizationId, $parameters)
            .$this->environmentClause($parameters)
            .' GROUP BY m ORDER BY m';

        $snapshot = [];
        foreach ($this->connection->select($sql, $parameters) as $row) {
            $metric = $row['m'] ?? null;
            $count = $row['c'] ?? null;
            if (is_string($metric) && is_numeric($count)) {
                $snapshot[$metric] = (int) $count;
            }
        }

        return $snapshot;
    }

    public function topOrganizations(
        string $metric,
        CarbonInterface $since,
        CarbonInterface $until,
        int $limit = 10,
    ): array {
        $parameters = [
            'metric' => $metric,
            'since' => $since->format('Y-m-d H:i:s'),
            'until' => $until->format('Y-m-d H:i:s'),
        ];

        $sql = 'SELECT organization_id AS o, count() AS c FROM '.$this->qualifiedTable().' FINAL'
            .' WHERE metric = {metric:String} AND organization_id != \'\''
            .' AND occurred_at >= {since:DateTime} AND occurred_at <= {until:DateTime}'
            .$this->environmentClause($parameters)
            .' GROUP BY o ORDER BY c DESC LIMIT '.max(0, $limit);

        $top = [];
        foreach ($this->connection->select($sql, $parameters) as $row) {
            $organizationId = $row['o'] ?? null;
            $count = $row['c'] ?? null;
            if (is_string($organizationId) && is_numeric($count)) {
                $top[$organizationId] = (int) $count;
            }
        }

        return $top;
    }

    /**
     * @param  array<string, scalar>  $parameters
     */
    private function orgClause(?string $organizationId, array &$parameters): string
    {
        if ($organizationId === null) {
            return '';
        }

        $parameters['org'] = $organizationId;

        return ' AND organization_id = {org:String}';
    }

    /**
     * @param  array<string, scalar>  $parameters
     */
    private function environmentClause(array &$parameters): string
    {
        $environment = $this->environments->current()?->environmentKey();

        // No environment in context used to drop the clause entirely, which made an
        // unscoped read return EVERY environment's events — the console renders
        // dashboard cards from contexts that do not always carry one. Deny by
        // default instead, matching DatabaseReportReader so a store swap cannot
        // change who sees what. Not `= ''`: a record whose event carried no
        // environment is stored with an empty string and would match.
        if ($environment === null) {
            return ' AND 1 = 0';
        }

        $parameters['env'] = $environment;

        return ' AND environment_id = {env:String}';
    }

    private function qualifiedTable(): string
    {
        return $this->table;
    }
}
