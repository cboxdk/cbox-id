<?php

declare(strict_types=1);

namespace App\Platform\Actions\Approvals;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * The action may run, but only after a person approves it on their device. The caller
 * polls the approval, then repeats the same request naming it.
 */
final class ApprovalRequired extends RuntimeException
{
    public function __construct(
        public readonly string $approvalId,
        public readonly string $bindingCode,
        public readonly CarbonImmutable $expiresAt,
        public readonly int $interval,
    ) {
        parent::__construct('This action needs a person\'s approval. Approve it on the device that owns this credential, then repeat the request with the approval id.');
    }
}
