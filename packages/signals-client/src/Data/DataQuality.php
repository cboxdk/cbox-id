<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

enum DataQuality: string
{
    case Ready = 'ready';
    case WarmingUp = 'warming_up';
    case InsufficientData = 'insufficient_data';
    case Degraded = 'degraded';
    case Disabled = 'disabled';
    case NotConfigured = 'not_configured';
}
