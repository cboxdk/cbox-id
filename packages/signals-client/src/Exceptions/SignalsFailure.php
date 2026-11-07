<?php

declare(strict_types=1);

namespace Cbox\Signals\Exceptions;

use RuntimeException;

class SignalsFailure extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status)
    {
        parent::__construct('Signals request failed: '.$errorCode, $status);
    }
}
