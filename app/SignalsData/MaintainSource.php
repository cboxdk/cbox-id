<?php

declare(strict_types=1);

namespace App\SignalsData;

use App\SignalsData\Models\SourceHead;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Illuminate\Console\Command;

final class MaintainSource extends Command
{
    protected $signature = 'signals:source-maintain';

    protected $description = 'Apply source retention and publish independent capture heartbeats';

    public function handle(SourceLedger $ledger, EnvironmentContext $environments): int
    {
        foreach (array_unique(array_filter([...config()->array('signals-data.environments'), ...SourceHead::query()->pluck('environment_id')->all()], is_string(...))) as $environment) {
            if ($environment !== '') {
                $environments->runAs(GenericEnvironment::of($environment), static fn () => $ledger->maintain());
            }
        }

        return self::SUCCESS;
    }
}
