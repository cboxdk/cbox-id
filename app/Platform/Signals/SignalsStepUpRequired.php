<?php

declare(strict_types=1);

namespace App\Platform\Signals;

use Cbox\Id\Identity\Exceptions\AuthenticationStepUpRequired;
use Cbox\Id\Identity\ValueObjects\VerifiedAuthentication;

final class SignalsStepUpRequired extends AuthenticationStepUpRequired
{
    public function __construct(string $assessmentId, public readonly VerifiedAuthentication $authentication)
    {
        parent::__construct($assessmentId);
    }
}
