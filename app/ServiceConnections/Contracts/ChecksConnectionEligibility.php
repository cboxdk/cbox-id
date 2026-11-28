<?php

declare(strict_types=1);

namespace App\ServiceConnections\Contracts;

interface ChecksConnectionEligibility
{
    /** Organization admission only; the subject is the authenticated consumer, never a person. */
    public function allows(string $organization, string $consumer): bool;
}
