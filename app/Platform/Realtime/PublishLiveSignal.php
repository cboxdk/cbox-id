<?php

declare(strict_types=1);

namespace App\Platform\Realtime;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * The queued half of a live update: on the worker, hand one channel's signal to the socket
 * server. Queued so a slow or absent Reverb never adds a millisecond to the request that
 * changed something; tried once, because a signal that arrives late is worth less than a
 * page that is simply reloaded.
 */
final class PublishLiveSignal implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(public readonly LiveSignal $signal) {}

    public function handle(): void
    {
        event($this->signal);
    }
}
