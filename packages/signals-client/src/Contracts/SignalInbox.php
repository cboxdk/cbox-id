<?php

declare(strict_types=1);

namespace Cbox\Signals\Contracts;

use Cbox\Signals\Webhooks\SignalEnvelope;

interface SignalInbox
{
    /** Persist before acknowledgement. Returns true only when this revision advances state. */
    public function accept(SignalEnvelope $envelope): bool;

    /** @return list<SignalEnvelope> Current, unexpired active signals in the explicit scope. */
    public function active(string $project, string $environment): array;
}
