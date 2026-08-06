<?php

declare(strict_types=1);

namespace Cbox\Id\Analytics\Contracts;

use Carbon\CarbonInterface;
use Cbox\Id\Analytics\Readers\ClickHouseReportReader;
use Cbox\Id\Analytics\Readers\UsageMeterReportReader;

/**
 * The read side of analytics — the queries the console dashboards run. It is a
 * contract so the same dashboards work over the platform's own Postgres usage
 * counters (the self-hostable default, {@see UsageMeterReportReader})
 * or over ClickHouse ({@see ClickHouseReportReader}) with
 * zero UI change. All results are scoped to the current environment.
 */
interface ReportReader
{
    /**
     * A per-day series for a metric over `[since, until]`, for a chart. Days with no
     * activity are omitted (sparse). `$organizationId` null totals across the whole
     * environment; a value scopes to that organization.
     *
     * @return array<string, int> keyed by day (`Y-m-d`) → count
     */
    public function series(
        string $metric,
        ?string $organizationId,
        CarbonInterface $since,
        CarbonInterface $until,
    ): array;

    /**
     * Every metric's total over `[since, until]` — the shape a dashboard reads in
     * one call.
     *
     * @return array<string, int> keyed by metric → total
     */
    public function snapshot(
        ?string $organizationId,
        CarbonInterface $since,
        CarbonInterface $until,
    ): array;

    /**
     * The organizations with the most activity for a metric over `[since, until]`,
     * highest first, capped at `$limit`.
     *
     * @return array<string, int> keyed by organization id → count
     */
    public function topOrganizations(
        string $metric,
        CarbonInterface $since,
        CarbonInterface $until,
        int $limit = 10,
    ): array;
}
