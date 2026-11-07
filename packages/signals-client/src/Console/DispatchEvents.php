<?php

declare(strict_types=1);

namespace Cbox\Signals\Console;

use Cbox\Signals\Contracts\EventOutbox;
use Illuminate\Console\Command;

class DispatchEvents extends Command
{
    protected $signature = 'signals:dispatch-events {--limit=100}';

    protected $description = 'Deliver pending Signals event batches with stable retry identities';

    public function handle(EventOutbox $outbox): int
    {
        $limit = $this->option('limit');
        if (! is_numeric($limit) || intval($limit) < 1 || intval($limit) > 1000) {
            $this->error('Limit must be between 1 and 1000.');

            return self::INVALID;
        }
        $count = 0;
        while ($count < intval($limit) && $outbox->dispatch()) {
            $count++;
        }
        $this->info('Dispatched '.$count.' batches; inspect signals_event_outbox for refused batches.');

        return self::SUCCESS;
    }
}
