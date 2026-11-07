<?php

declare(strict_types=1);

namespace Cbox\Signals\Console;

use Cbox\Signals\Contracts\ManagedSources;
use Cbox\Signals\Managed\ManagedEnroller;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;

class ReconcileManaged extends Command
{
    protected $signature = 'signals:managed-reconcile {--rotate=* : Owner ids whose read secret must be replaced}';

    protected $description = 'Enrol this application\'s environments in its bundled Signals integration';

    public function handle(Container $container): int
    {
        if (! $container->bound(ManagedSources::class)) {
            $this->error('No '.ManagedSources::class.' is bound; this application has no bundled Signals integration.');

            return self::INVALID;
        }
        $rotate = [];
        foreach ((array) $this->option('rotate') as $owner) {
            if (is_string($owner) && $owner !== '') {
                $rotate[] = $owner;
            }
        }
        $result = $container->make(ManagedEnroller::class)->reconcile($rotate);
        $this->info(sprintf('Enrolled %d, rotated %d, unchanged %d, revoked %d, failed %d.', count($result->enrolled), count($result->rotated), count($result->unchanged), count($result->revoked), count($result->failed)));
        foreach ($result->failed as $owner => $code) {
            $this->warn($owner.': '.$code);
        }

        return $result->clean() ? self::SUCCESS : self::FAILURE;
    }
}
