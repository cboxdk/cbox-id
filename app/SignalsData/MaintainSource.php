<?php

declare(strict_types=1);

namespace App\SignalsData;

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Illuminate\Console\Command;

final class MaintainSource extends Command
{
    protected $signature = 'signals:source-maintain';

    protected $description = 'Apply source retention and publish independent capture heartbeats';

    public function handle(SourceLedger $ledger, EnvironmentContext $environments): int
    {
        foreach (config()->array('signals-data.environments') as $environment) {
            if (is_string($environment) && $environment !== '') {
                $environments->runAs(GenericEnvironment::of($environment), static fn () => $ledger->maintain());
            }
        }

        return self::SUCCESS;
    }
}
