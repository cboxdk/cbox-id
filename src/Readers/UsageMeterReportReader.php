<?php

declare(strict_types=1);

namespace Cbox\Id\Analytics\Readers;

use Carbon\CarbonInterface;
use Cbox\Id\Analytics\Contracts\ReportReader;
use Cbox\Id\Kernel\Usage\Contracts\UsageMeter;
use Cbox\Id\Kernel\Usage\Models\UsageCounter;

/**
 * The default, self-hostable reader: it reads the platform's own per-day usage
 * counters (Postgres/SQLite) through the {@see UsageMeter} — so the console
 * dashboards work with no ClickHouse. Everything is environment-scoped by the
 * meter and the counter model's hard environment scope.
 */
final class UsageMeterReportReader implements ReportReader
{
    public function __construct(private readonly UsageMeter $meter) {}

    public function series(
        string $metric,
        ?string $organizationId,
        CarbonInterface $since,
        CarbonInterface $until,
    ): array {
        return $this->meter->series($metric, $organizationId, $since, $until);
    }

    public function snapshot(
        ?string $organizationId,
        CarbonInterface $since,
        CarbonInterface $until,
    ): array {
        return $this->meter->snapshot($organizationId, $since, $until);
    }

    public function topOrganizations(
        string $metric,
        CarbonInterface $since,
        CarbonInterface $until,
        int $limit = 10,
    ): array {
        // Org-attributed counters only (organization_id '' is the system-scoped
        // bucket, which is not an "organization"). Environment scope is applied by
        // the model's global scope.
        $rows = UsageCounter::query()
            ->where('metric', $metric)
            ->where('organization_id', '!=', '')
            ->where('period', '>=', $since->format('Y-m-d'))
            ->where('period', '<=', $until->format('Y-m-d'))
            ->selectRaw('organization_id, SUM(count) as total')
            ->groupBy('organization_id')
            ->orderByDesc('total')
            ->limit($limit)
            ->pluck('total', 'organization_id');

        $top = [];
        foreach ($rows as $organizationId => $total) {
            if (is_string($organizationId) && is_numeric($total)) {
                $top[$organizationId] = (int) $total;
            }
        }

        return $top;
    }
}
