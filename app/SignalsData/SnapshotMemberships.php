<?php

declare(strict_types=1);

namespace App\SignalsData;

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Illuminate\Console\Command;

final class SnapshotMemberships extends Command
{
    protected $signature = 'signals:source-memberships {environment} {--after=} {--limit=500}';

    protected $description = 'Observe one bounded page of current owner memberships for Signals';

    public function handle(MembershipSnapshot $snapshot, EnvironmentContext $environments): int
    {
        $environment = $this->argument('environment');
        $after = $this->option('after');
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! in_array($environment, config()->array('signals-data.environments'), true)
            || $limit === false || $limit < 1 || $limit > 1000) {
            $this->error('Choose an enabled owner environment and a limit from 1 to 1000.');

            return self::FAILURE;
        }
        $result = $environments->runAs(GenericEnvironment::of($environment), fn (): array => $snapshot->page($after, $limit));
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
