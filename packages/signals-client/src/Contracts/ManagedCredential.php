<?php

declare(strict_types=1);

namespace Cbox\Signals\Contracts;

/**
 * The bearer credential that identifies the source application towards the
 * Signals enrolment API. Applications bind a Cbox ID machine-token
 * implementation here; the default reads a configured platform token.
 */
interface ManagedCredential
{
    /** @return string empty when the application holds no credential */
    public function token(): string;
}
