<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

enum Severity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';
}
