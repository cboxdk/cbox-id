<?php

declare(strict_types=1);

namespace App\Platform\Mfa;

final readonly class ConfirmedAuthenticator
{
    /** @param list<string> $recoveryCodes Only returned on first enrolment. */
    public function __construct(public array $recoveryCodes = []) {}
}
