<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

enum EnrollmentStatus: string
{
    case Enrolled = 'enrolled';
    case Revoked = 'revoked';
}
