<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

enum OperationState: string
{
    case Accepted = 'accepted';
    case Completed = 'completed';
    case Failed = 'failed';
}
