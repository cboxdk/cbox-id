<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

enum MetricKind: string
{
    case Gauge = 'gauge';
    case CumulativeCounter = 'cumulative_counter';
    case Delta = 'delta';
}
