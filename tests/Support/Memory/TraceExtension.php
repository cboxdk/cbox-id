<?php

declare(strict_types=1);

namespace Tests\Support\Memory;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Where the suite's memory goes, test by test — OFF unless asked for.
 *
 *     CBOX_MEMORY_TRACE=/tmp/memory.tsv vendor/bin/pest --testsuite=Unit,Feature
 *
 * After every test it collects cycles and appends `memory_get_usage()`,
 * `memory_get_peak_usage()` and the test's id, tab-separated. Summing the differences
 * per file shows which tests leave memory behind: a file whose tests each add the same
 * amount is leaking, one that adds a lump once is loading code for the first time.
 *
 * This is how the growth that pushed `memory_limit` to 2G was traced (see phpunit.xml).
 * Run it in ONE process — under `--parallel` each worker appends to the same file.
 */
final class TraceExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $path = getenv('CBOX_MEMORY_TRACE');

        if (! is_string($path) || $path === '') {
            return;
        }

        $facade->registerSubscriber(new class($path) implements FinishedSubscriber
        {
            public function __construct(private readonly string $path) {}

            public function notify(Finished $event): void
            {
                gc_collect_cycles();

                file_put_contents(
                    $this->path,
                    sprintf("%d\t%d\t%s\n", memory_get_usage(), memory_get_peak_usage(), $event->test()->id()),
                    FILE_APPEND,
                );
            }
        });
    }
}
