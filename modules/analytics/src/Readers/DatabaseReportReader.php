<?php

declare(strict_types=1);

namespace Cbox\Id\Analytics\Readers;

use Carbon\CarbonInterface;
use Cbox\Id\Analytics\Contracts\ReportReader;
use Cbox\Id\Analytics\Models\AnalyticsEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reads the dashboards out of the relational event store.
 *
 * The environment filter is applied EXPLICITLY on every query rather than through a
 * model scope, because {@see AnalyticsEvent} deliberately carries the event's own
 * environment rather than the writer's context. That mirrors
 * {@see ClickHouseReportReader}, so the two stores enforce the same boundary in the
 * same place and a reader swap cannot quietly change who sees what.
 *
 * With no environment in context the queries return nothing rather than everything:
 * an unscoped read of a cross-tenant table is the failure mode worth being
 * deny-by-default about.
 *
 * All three queries are plain GROUP BYs over the two covering indexes. Nothing here
 * uses a date-truncation function — the day is a stored column — so the same SQL
 * runs on MySQL, PostgreSQL and SQLite.
 */
class DatabaseReportReader implements ReportReader
{
    public function __construct(private readonly EnvironmentContext $environments) {}

    public function series(
        string $metric,
        ?string $organizationId,
        CarbonInterface $since,
        CarbonInterface $until,
    ): array {
        $query = $this->scoped($since, $until)->where('metric', $metric);

        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }

        $rows = $query->selectRaw('occurred_on, count(*) as aggregate')
            ->groupBy('occurred_on')
            ->orderBy('occurred_on')
            ->pluck('aggregate', 'occurred_on');

        $series = [];
        foreach ($rows as $day => $count) {
            if (! is_numeric($count)) {
                continue;
            }

            // The driver hands the date back as a string on MySQL/sqlite and can
            // carry a time part on PostgreSQL; normalise to the Y-m-d the contract
            // documents rather than passing the driver's spelling through.
            $series[substr((string) $day, 0, 10)] = (int) $count;
        }

        return $series;
    }

    public function snapshot(
        ?string $organizationId,
        CarbonInterface $since,
        CarbonInterface $until,
    ): array {
        $query = $this->scoped($since, $until)->where('metric', '<>', '');

        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }

        $rows = $query->selectRaw('metric, count(*) as aggregate')
            ->groupBy('metric')
            ->orderBy('metric')
            ->pluck('aggregate', 'metric');

        $snapshot = [];
        foreach ($rows as $metric => $count) {
            if (is_numeric($count)) {
                $snapshot[(string) $metric] = (int) $count;
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
        $rows = $this->scoped($since, $until)
            ->where('metric', $metric)
            ->where('organization_id', '<>', '')
            ->selectRaw('organization_id, count(*) as aggregate')
            ->groupBy('organization_id')
            ->orderByDesc('aggregate')
            ->limit(max(0, $limit))
            ->pluck('aggregate', 'organization_id');

        $top = [];
        foreach ($rows as $organizationId => $count) {
            if (is_numeric($count)) {
                $top[(string) $organizationId] = (int) $count;
            }
        }

        return $top;
    }

    /**
     * @return Builder<AnalyticsEvent>
     */
    private function scoped(CarbonInterface $since, CarbonInterface $until): Builder
    {
        $environment = $this->environments->current()?->environmentKey();

        $query = AnalyticsEvent::query()
            ->where('occurred_at', '>=', $since)
            ->where('occurred_at', '<=', $until);

        // Not `where('environment_id', '')`: a record whose event carried no
        // environment is stored with an empty string, so that would match real rows
        // rather than none. An impossible predicate is the only honest spelling of
        // "no environment is in context, so nothing is in scope".
        return $environment === null
            ? $query->whereRaw('1 = 0')
            : $query->where('environment_id', $environment);
    }
}
