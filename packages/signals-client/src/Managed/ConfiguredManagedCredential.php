<?php

declare(strict_types=1);

namespace Cbox\Signals\Managed;

use Cbox\Signals\Contracts\ManagedCredential;

/** Reads the application's enrolment credential from configuration. */
final readonly class ConfiguredManagedCredential implements ManagedCredential
{
    public function __construct(private string $token) {}

    public function token(): string
    {
        return $this->token;
    }
}
