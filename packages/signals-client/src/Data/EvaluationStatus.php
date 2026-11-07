<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

enum EvaluationStatus: string
{
    case Evaluated = 'evaluated';
    case InsufficientData = 'insufficient_data';
    case Degraded = 'degraded';
}
