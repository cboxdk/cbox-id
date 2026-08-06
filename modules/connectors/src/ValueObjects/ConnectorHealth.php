<?php

declare(strict_types=1);

namespace Cbox\Id\Connectors\ValueObjects;

use Carbon\CarbonInterface;
use Cbox\Id\Connectors\Contracts\ConnectorAnalytics;

/**
 * Delivery health for a single connection over a recent window, as reported by a
 * {@see ConnectorAnalytics} implementation. It is
 * absent (null on a {@see ConnectionSummary}) until a real analytics backend is
 * wired — the framework never depends on one.
 */
readonly class ConnectorHealth
{
    public function __construct(
        public int $delivered,
        public int $failed,
        public ?CarbonInterface $lastActivityAt,
    ) {}

    public function total(): int
    {
        return $this->delivered + $this->failed;
    }

    /**
     * Fraction of successful deliveries in `[0,1]`, or null when there was no
     * activity to rate.
     */
    public function successRate(): ?float
    {
        $total = $this->total();

        return $total === 0 ? null : $this->delivered / $total;
    }

    /** A coarse health verdict for a badge: healthy, degraded, or idle. */
    public function verdict(): string
    {
        $rate = $this->successRate();

        if ($rate === null) {
            return 'idle';
        }

        return $rate >= 0.95 ? 'healthy' : 'degraded';
    }
}
