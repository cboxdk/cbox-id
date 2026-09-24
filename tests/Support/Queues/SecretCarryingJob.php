<?php

declare(strict_types=1);

namespace Tests\Support\Queues;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/**
 * A job shaped like the ones this application queues — its constructor arguments ARE its
 * payload — carrying the kind of value that must never reach the queue monitor's tables.
 *
 * It also asks for its payload to be stored. The monitor honours a per-job
 * `shouldStorePayload()` over its own config, so this is the worst case: a job that opted
 * itself in.
 */
class SecretCarryingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $logoutToken,
        public readonly string $email,
        public readonly bool $fail = false,
    ) {}

    public function handle(): void
    {
        if ($this->fail) {
            // A failure message a job would plausibly throw: it names the job, not its data.
            throw new RuntimeException('Relying party answered 503');
        }
    }

    public function shouldStorePayload(): bool
    {
        return true;
    }
}
