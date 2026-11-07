<?php

declare(strict_types=1);

namespace Cbox\Signals\Contracts;

use Cbox\Signals\Data\CountInterval;
use Cbox\Signals\Data\IntervalHistoryQuery;
use Cbox\Signals\Data\IntervalPage;
use Cbox\Signals\Data\IntervalReceipt;
use Cbox\Signals\Data\IntervalSeries;

interface CountIntervals
{
    /** @param list<CountInterval> $records */
    public function ingest(IntervalSeries $series, array $records): IntervalReceipt;

    public function history(IntervalSeries $series, IntervalHistoryQuery $query): IntervalPage;
}
