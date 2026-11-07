<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

enum ErasureState: string
{
    case Pending = 'pending';
    case PendingHistory = 'pending_history';
    case PendingConfiguration = 'pending_configuration';
    case Completed = 'completed';
}
